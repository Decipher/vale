<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Process;

/**
 * Describes the Vale installation the site points at.
 *
 * The binary and the configuration come only from settings.php, as
 * $settings['vale_binary'] and $settings['vale_config']. Vale rules can run
 * code, so they are never taken from the user interface or from stored
 * configuration.
 */
class ValeEnvironment {

  use StringTranslationTrait;

  /**
   * How long the version and the style paths are cached, in seconds.
   */
  protected const PROBE_LIFETIME = 3600;

  /**
   * How long a fingerprint or rule catalogue is kept, in seconds.
   */
  protected const ENTRY_LIFETIME = 604800;

  /**
   * The first Vale version that reads a language other than English.
   */
  public const string LANG_MINIMUM = '3.27.0';

  /**
   * The fingerprint for this request, once it has been worked out.
   */
  protected ?string $fingerprint = NULL;

  /**
   * Constructs a ValeEnvironment.
   *
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The vale cache bin.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected FileSystemInterface $fileSystem,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Gives the path to the Vale binary.
   *
   * @return string|null
   *   The path, or NULL when it is not set or not executable.
   */
  public function binary(): ?string {
    $binary = Settings::get('vale_binary');
    return is_string($binary) && is_file($binary) && is_executable($binary) ? $binary : NULL;
  }

  /**
   * Gives the path to the .vale.ini file.
   *
   * @return string|null
   *   The path, or NULL when it is not set or cannot be read.
   */
  public function configPath(): ?string {
    $config = Settings::get('vale_config');
    return is_string($config) && is_file($config) && is_readable($config) ? $config : NULL;
  }

  /**
   * Says why Vale cannot be run.
   *
   * @return string|null
   *   The reason, or NULL when Vale can be run.
   */
  public function unavailableReason(): ?string {
    if (!is_string(Settings::get('vale_binary'))) {
      return (string) $this->t("The Vale binary is not set. Set \$settings['vale_binary'] in settings.php.");
    }
    if ($this->binary() === NULL) {
      return (string) $this->t('The Vale binary set in settings.php does not exist or is not executable.');
    }
    if (!is_string(Settings::get('vale_config'))) {
      return (string) $this->t("The Vale configuration is not set. Set \$settings['vale_config'] in settings.php.");
    }
    if ($this->configPath() === NULL) {
      return (string) $this->t('The Vale configuration set in settings.php does not exist or cannot be read.');
    }
    return NULL;
  }

  /**
   * Gives the time limit for one Vale run.
   *
   * @return int
   *   The limit, in seconds.
   */
  public function timeout(): int {
    $timeout = $this->configFactory->get('vale.settings')->get('timeout');
    return is_int($timeout) && $timeout > 0 ? $timeout : 10;
  }

  /**
   * Makes a process that runs Vale with the site's configuration.
   *
   * The arguments are passed as an array, so no shell reads them.
   *
   * @param string[] $arguments
   *   The arguments after the binary.
   * @param string|null $input
   *   Text for Vale's standard input, or NULL.
   * @param string|null $config
   *   A configuration to use in place of the site's, such as one from
   *   languageConfigPath(), or NULL. Vale still runs in the site
   *   configuration's directory.
   *
   * @return \Symfony\Component\Process\Process
   *   The process, not yet started.
   *
   * @throws \LogicException
   *   When Vale is unavailable.
   */
  public function process(array $arguments, ?string $input = NULL, ?string $config = NULL): Process {
    $binary = $this->binary();
    $site = $this->configPath();
    if ($binary === NULL || $site === NULL) {
      throw new \LogicException((string) $this->unavailableReason());
    }
    return new Process(
      [$binary, '--no-global', '--config=' . ($config ?? $site), ...$arguments],
      dirname($site),
      NULL,
      $input,
      $this->timeout(),
    );
  }

  /**
   * Gives the version of the Vale binary.
   *
   * @return string|null
   *   The version, such as "3.18.0", or NULL when it cannot be read.
   */
  public function version(): ?string {
    $binary = $this->binary();
    if ($binary === NULL) {
      return NULL;
    }
    $cid = 'version:' . hash('sha256', $binary . ':' . filemtime($binary) . ':' . filesize($binary));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $version = NULL;
    try {
      $process = new Process([$binary, '--version'], NULL, NULL, NULL, $this->timeout());
      $process->run();
      if ($process->isSuccessful() && preg_match('/^vale version (\S+)/m', $process->getOutput(), $matches)) {
        $version = $matches[1];
      }
    }
    catch (ProcessException) {
      // A binary that cannot report its version is not Vale.
    }
    // Only a reported version is cached. A run that failed, timed out or
    // could not start may succeed on the next request.
    if ($version !== NULL) {
      $this->cache->set($cid, $version, $this->time->getRequestTime() + static::PROBE_LIFETIME);
    }
    return $version;
  }

  /**
   * Says whether the Vale binary reads languages other than English.
   *
   * @return bool
   *   TRUE from Vale 3.27.0, which has the Lang key.
   */
  public function supportsLanguages(): bool {
    $version = $this->version();
    return $version !== NULL && version_compare($version, static::LANG_MINIMUM, '>=');
  }

  /**
   * Gives the Lang tag for a Drupal language, whatever the Vale version.
   *
   * @param string $langcode
   *   The langcode, such as "fr" or "pt-br".
   *
   * @return string|null
   *   The langcode, or NULL for English, "Not specified" and "Not
   *   applicable", and for a langcode that is not a language tag.
   */
  protected function languageTag(string $langcode): ?string {
    if (!preg_match('/^([a-z]{2,3})(?:[-_][a-z0-9]{1,8})*$/i', $langcode, $matches)) {
      return NULL;
    }
    $base = strtolower($matches[1]);
    $english = ['en', LanguageInterface::LANGCODE_NOT_SPECIFIED, LanguageInterface::LANGCODE_NOT_APPLICABLE];
    return in_array($base, $english, TRUE) ? NULL : $langcode;
  }

  /**
   * Gives the language Vale reads a value in.
   *
   * @param string $langcode
   *   The langcode of the value's translation.
   *
   * @return string|null
   *   The Lang tag, or NULL when the value is read as English with the site's
   *   own configuration. That is so for English, "Not specified", "Not
   *   applicable", and for any language with Vale older than 3.27.0.
   */
  public function valeLanguage(string $langcode): ?string {
    $tag = $this->languageTag($langcode);
    return $tag !== NULL && $this->supportsLanguages() ? $tag : NULL;
  }

  /**
   * Splits the site's configuration into its StylesPath and the rest.
   *
   * @return array{0: string|null, 1: string}|null
   *   The StylesPath, made absolute, or NULL when the configuration has none.
   *   Then the configuration without its top-level StylesPath and Lang. NULL
   *   when the configuration cannot be read.
   */
  protected function splitConfig(): ?array {
    $config = $this->configPath();
    if ($config === NULL) {
      return NULL;
    }
    $styles = NULL;
    $kept = [];
    $top = TRUE;
    foreach (preg_split('/(?<=\n)/', (string) file_get_contents($config)) ?: [] as $line) {
      $top = $top && !preg_match('/^\s*\[/', $line);
      if ($top && preg_match('/^\s*(StylesPath|Lang)\s*=\s*(.*?)\s*$/i', $line, $matches)) {
        // Vale keeps the first of two keys, so this does too.
        if (strcasecmp($matches[1], 'StylesPath') === 0 && $styles === NULL) {
          $styles = trim($matches[2], '"\'');
        }
        continue;
      }
      $kept[] = $line;
    }
    // Vale reads a relative StylesPath from the configuration's directory.
    if ($styles !== NULL && $styles !== '' && !str_starts_with($styles, '/')) {
      $styles = dirname($config) . '/' . $styles;
    }
    return [$styles ?: NULL, implode('', $kept)];
  }

  /**
   * Gives the configuration Vale reads a language with.
   *
   * It is the site's configuration with Lang set at the top, so a section
   * that sets its own Lang still wins. The site's top-level Lang is left
   * out, as Vale would keep the first of the two. The browser config route
   * can call this with the StylesPath its build reads.
   *
   * @param string $langcode
   *   The langcode of the text.
   * @param string|null $stylesPath
   *   The StylesPath to set, or NULL for the site's, made absolute.
   *
   * @return string|null
   *   The configuration, or NULL when the text is read as English with the
   *   site's own configuration.
   */
  public function languageConfig(string $langcode, ?string $stylesPath = NULL): ?string {
    $language = $this->valeLanguage($langcode);
    $split = $this->splitConfig();
    if ($language === NULL || $split === NULL) {
      return NULL;
    }
    [$styles, $rest] = $split;
    $stylesPath ??= $styles;
    $head = ($stylesPath === NULL ? '' : 'StylesPath = ' . $stylesPath . "\n") . 'Lang = ' . $language . "\n";
    return $head . $rest;
  }

  /**
   * Writes the configuration Vale reads a language with.
   *
   * The file goes in the temporary directory, as the code may be read-only.
   * Its name holds the fingerprint, the site's configuration and the
   * langcode, so a changed configuration or style gets a new file.
   *
   * @param string $langcode
   *   The langcode of the text.
   *
   * @return string|null
   *   The path to the file, or NULL when the text is read as English with the
   *   site's own configuration.
   *
   * @throws \RuntimeException
   *   When the file cannot be written.
   */
  public function languageConfigPath(string $langcode): ?string {
    $ini = $this->languageConfig($langcode);
    if ($ini === NULL) {
      return NULL;
    }
    $key = hash('sha256', $this->fingerprint() . "\0" . $this->configPath());
    return $this->writeConfig($key . '.' . $langcode . '.ini', $ini);
  }

  /**
   * Gives the language codes of the site's languages that are not English.
   *
   * @return string[]
   *   The codes, in the site's order.
   */
  public function siteLanguages(): array {
    return array_values(array_filter(
      array_keys($this->languageManager->getLanguages()),
      fn (string $langcode): bool => $this->languageTag($langcode) !== NULL,
    ));
  }

  /**
   * Writes a configuration that names each of the site's languages.
   *
   * Vale's sync downloads the model for each language a configuration names,
   * so .vale.ini need not name them. It has no Packages, so only the models
   * are downloaded.
   *
   * @return string|null
   *   The path to the file, or NULL when every language is read as English.
   *
   * @throws \RuntimeException
   *   When the file cannot be written.
   */
  public function modelsConfigPath(): ?string {
    $languages = $this->supportsLanguages() ? $this->siteLanguages() : [];
    $split = $this->splitConfig();
    if ($languages === [] || $split === NULL) {
      return NULL;
    }
    $ini = $split[0] === NULL ? '' : 'StylesPath = ' . $split[0] . "\n";
    foreach ($languages as $langcode) {
      // The sections match no file. They only name the languages.
      $ini .= "\n[*.vale-language-" . $langcode . "]\nLang = " . $langcode . "\n";
    }
    return $this->writeConfig('models-' . hash('sha256', $ini) . '.ini', $ini);
  }

  /**
   * Writes a configuration to the temporary directory.
   *
   * An existing file is kept, as its name says what it holds. A new file is
   * written beside it and moved into place, so Vale never reads half of it.
   *
   * @param string $name
   *   The file name.
   * @param string $ini
   *   The configuration.
   *
   * @return string
   *   The path to the file.
   *
   * @throws \RuntimeException
   *   When the file cannot be written.
   */
  protected function writeConfig(string $name, string $ini): string {
    $directory = $this->fileSystem->getTempDirectory() . '/vale';
    $file = $directory . '/' . $name;
    if (is_file($file)) {
      return $file;
    }
    $partial = $file . '.' . bin2hex(random_bytes(6));
    if ((!is_dir($directory) && !@mkdir($directory, 0775, TRUE) && !is_dir($directory))
      || @file_put_contents($partial, $ini) === FALSE
      || !@rename($partial, $file)) {
      @unlink($partial);
      throw new \RuntimeException(sprintf('The Vale configuration for a language could not be written to %s.', $directory));
    }
    return $file;
  }

  /**
   * Describes the site's languages for the site status report.
   *
   * @return array{severity: int, value: string, description: string}
   *   The severity, a short value and a longer description. The severity is
   *   0 for OK and 1 for a warning, as in RequirementSeverity.
   */
  public function languageStatus(): array {
    $names = [];
    foreach ($this->languageManager->getLanguages() as $langcode => $language) {
      $names[$langcode] = $language->getName();
    }
    $value = implode(', ', $names);
    $others = $this->siteLanguages();
    if ($others === []) {
      return [
        'severity' => 0,
        'value' => $value,
        'description' => (string) $this->t('Every value is checked in English.'),
      ];
    }
    $list = static fn (array $codes): string => implode(', ', array_map(static fn (string $langcode): string => $names[$langcode], $codes));
    if (!$this->supportsLanguages()) {
      $version = $this->version();
      $arguments = [
        '@version' => (string) $version,
        '@languages' => $list($others),
        '@minimum' => static::LANG_MINIMUM,
      ];
      return [
        'severity' => 1,
        'value' => $value,
        'description' => (string) ($version === NULL
          ? $this->t('Vale did not report its version, so every value is checked in English. Checking @languages in their own language needs Vale @minimum or later.', $arguments)
          : $this->t('Vale @version checks every value in English. Checking @languages in their own language needs Vale @minimum or later.', $arguments)),
      ];
    }
    $models = [];
    $dictionaries = [];
    foreach ($others as $langcode) {
      $base = strtolower(substr($langcode, 0, strcspn($langcode, '-_')));
      $model = FALSE;
      $dictionary = FALSE;
      foreach ($this->stylesPaths() as $path) {
        $model = $model || is_file($path . '/config/models/' . $base . '/model.json');
        // A dictionary is named for the language, or for it and a region in
        // Hunspell's way, such as de_DE.
        $dictionary = $dictionary || is_file($path . '/config/dictionaries/' . $base . '.dic') || (glob($path . '/config/dictionaries/' . $base . '_*.dic') ?: []) !== [];
      }
      if (!$model) {
        $models[] = $langcode;
      }
      if (!$dictionary) {
        $dictionaries[] = $langcode;
      }
    }
    $parts = [$this->t('Each value is checked in the language of its translation.')];
    if ($models !== []) {
      $parts[] = $this->t('Vale has no model for @languages, so it splits their sentences by punctuation. Run vale:sync to download the models Vale publishes.', ['@languages' => $list($models)]);
    }
    if ($dictionaries !== []) {
      $parts[] = $this->t('There is no spelling dictionary for @languages in config/dictionaries, so Vale skips its spelling check in them.', ['@languages' => $list($dictionaries)]);
    }
    return [
      'severity' => $models === [] ? 0 : 1,
      'value' => $value,
      'description' => implode(' ', array_map(strval(...), $parts)),
    ];
  }

  /**
   * Gives the directories Vale loads styles from.
   *
   * These come from Vale itself, through "vale ls-config", so they match what
   * Vale resolves from the configuration's StylesPath.
   *
   * @return string[]
   *   The directories that exist.
   */
  public function stylesPaths(): array {
    $config = $this->configPath();
    if ($this->binary() === NULL || $config === NULL) {
      return [];
    }
    $cid = $this->stylesPathsCid($config);
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    try {
      $process = $this->process(['ls-config']);
      $process->run();
    }
    catch (ProcessException) {
      // A run that timed out or could not start is not cached.
      return [];
    }
    $listing = $process->isSuccessful() ? json_decode($process->getOutput(), TRUE) : NULL;
    if (!is_array($listing) || !is_array($listing['Paths'] ?? NULL)) {
      // No paths are known, so no rules are known. A failed listing is not
      // cached, so a style change is not missed for an hour.
      return [];
    }
    $paths = array_values(array_filter($listing['Paths'], static fn (mixed $path): bool => is_string($path) && is_dir($path)));
    $this->cache->set($cid, $paths, $this->time->getRequestTime() + static::PROBE_LIFETIME);
    return $paths;
  }

  /**
   * Forgets the styles directories, so they are asked of Vale again.
   *
   * Call it after the styles change on disk, such as after "vale sync". The
   * directories that exist are cached for an hour, so one that a sync creates
   * would otherwise be missed. The fingerprint and the rules are keyed by the
   * files they cover, so they follow once the directories are known.
   */
  public function resetStylesPaths(): void {
    $config = $this->configPath();
    if ($config !== NULL) {
      $this->cache->delete($this->stylesPathsCid($config));
    }
    $this->fingerprint = NULL;
  }

  /**
   * Gives the cache ID for the styles directories.
   *
   * @param string $config
   *   The path to the .vale.ini file.
   *
   * @return string
   *   The cache ID.
   */
  protected function stylesPathsCid(string $config): string {
    return 'paths:' . hash('sha256', $config . ':' . file_get_contents($config) . ':' . $this->version());
  }

  /**
   * Gives a fingerprint of everything that decides Vale's results.
   *
   * It covers the configuration file, every file under the styles
   * directories and the Vale version. A changed fingerprint means a cached
   * result that depends on the old one is never used again. File contents
   * are hashed only when a style file's size or modification time changes,
   * or a file is added or removed. An edit that keeps a file's size within
   * the same second is missed until the fingerprint expires.
   *
   * @return string
   *   The fingerprint.
   */
  public function fingerprint(): string {
    if ($this->fingerprint !== NULL) {
      return $this->fingerprint;
    }
    $config = $this->configPath();
    $prefix = $this->version() . "\0" . ($config === NULL ? '' : (string) file_get_contents($config));

    // List every style file with its size and modification time. That costs
    // a stat per file. The contents are hashed only when this list changes,
    // and the result is cached under it, so most requests hash nothing.
    $files = [];
    foreach ($this->stylesPaths() as $path) {
      // The paths are cached across servers, so one may be missing here.
      if (!is_dir($path)) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if ($file instanceof \SplFileInfo && $file->isFile()) {
          $files[$file->getPathname()] = substr($file->getPathname(), strlen($path)) . "\0" . $file->getSize() . "\0" . $file->getMTime();
        }
      }
    }
    ksort($files);
    $cid = 'fingerprint:' . hash('sha256', $prefix . "\0" . implode("\0", $files));
    if ($cached = $this->cache->get($cid)) {
      return $this->fingerprint = $cached->data;
    }

    $context = hash_init('sha256');
    hash_update($context, $prefix);
    foreach ($files as $file => $stat) {
      // A file removed since it was listed changes the fingerprint anyway.
      $hash = hash_file('sha256', $file);
      if (is_string($hash)) {
        hash_update($context, "\0" . explode("\0", $stat)[0] . "\0" . $hash);
      }
    }
    $this->fingerprint = hash_final($context);
    // A changed file gives a new key, so the old entry is only left to
    // expire.
    $this->cache->set($cid, $this->fingerprint, $this->time->getRequestTime() + static::ENTRY_LIFETIME);
    return $this->fingerprint;
  }

  /**
   * Gives every rule the styles directories hold.
   *
   * A rule is a YAML file at <styles>/<Style>/<Rule>.yml, named
   * "<Style>.<Rule>". The "config" directory holds vocabularies and
   * templates, not rules, so it is skipped.
   *
   * @return array<string, array{level: string, message: string, description: string, link: string}>
   *   The rules, keyed by name and sorted by name.
   *
   * @phpstan-impure
   */
  public function rules(): array {
    $cid = 'rules:' . $this->fingerprint();
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $rules = [];
    foreach ($this->stylesPaths() as $path) {
      foreach (glob($path . '/*/*.yml') ?: [] as $file) {
        $style = basename(dirname($file));
        if ($style === 'config') {
          continue;
        }
        try {
          $definition = Yaml::decode((string) file_get_contents($file));
        }
        catch (InvalidDataTypeException) {
          continue;
        }
        if (!is_array($definition)) {
          continue;
        }
        $name = $style . '.' . basename($file, '.yml');
        $rules[$name] ??= [
          'level' => is_string($definition['level'] ?? NULL) && Alert::weight($definition['level']) >= 0 ? $definition['level'] : Alert::WARNING,
          'message' => is_string($definition['message'] ?? NULL) ? $definition['message'] : '',
          'description' => is_string($definition['description'] ?? NULL) ? $definition['description'] : '',
          'link' => is_string($definition['link'] ?? NULL) ? $definition['link'] : '',
        ];
      }
    }
    ksort($rules);
    $this->cache->set($cid, $rules, $this->time->getRequestTime() + static::ENTRY_LIFETIME);
    return $rules;
  }

  /**
   * Describes the installation for the site status report.
   *
   * @return array{available: bool, value: string, description: string}
   *   Whether Vale can be run, a short value and a longer description.
   */
  public function status(): array {
    // With fail_closed, text Vale cannot check is refused, not saved.
    $effect = $this->configFactory->get('vale.settings')->get('fail_closed')
      ? $this->t('Saves of text checked with Vale are refused until this is fixed.')
      : $this->t('Content is saved without being checked.');
    $reason = $this->unavailableReason();
    if ($reason === NULL && $this->version() === NULL) {
      $reason = (string) $this->t('The binary set in settings.php did not report a Vale version.');
    }
    if ($reason !== NULL) {
      return [
        'available' => FALSE,
        'value' => (string) $this->t('Not available'),
        'description' => $reason . ' ' . $effect,
      ];
    }
    return [
      'available' => TRUE,
      'value' => (string) $this->version(),
      'description' => (string) $this->formatPlural(
        count($this->rules()),
        'Using @binary with @config. Its styles hold 1 rule.',
        'Using @binary with @config. Its styles hold @count rules.',
        ['@binary' => $this->binary(), '@config' => $this->configPath()],
      ),
    ];
  }

}
