<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\StreamWrapper\StreamWrapperManager;
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
   * The first Vale version with a browser build.
   */
  public const string WASM_MINIMUM = '3.26.0';

  /**
   * Where the browser build is, from the web root, unless a setting says.
   */
  public const string WASM_DIRECTORY = 'libraries/vale';

  /**
   * The files of the browser build: the module and the script that loads it.
   */
  public const array WASM_FILES = ['vale.wasm', 'wasm_exec.js'];

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
   * @param \Drupal\Core\File\FileUrlGeneratorInterface $fileUrlGenerator
   *   The file URL generator.
   * @param string $appRoot
   *   The web root.
   */
  public function __construct(
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected string $appRoot,
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
   *
   * @return \Symfony\Component\Process\Process
   *   The process, not yet started.
   *
   * @throws \LogicException
   *   When Vale is unavailable.
   */
  public function process(array $arguments, ?string $input = NULL): Process {
    $binary = $this->binary();
    $config = $this->configPath();
    if ($binary === NULL || $config === NULL) {
      throw new \LogicException((string) $this->unavailableReason());
    }
    return new Process(
      [$binary, '--no-global', '--config=' . $config, ...$arguments],
      dirname($config),
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
    $cid = 'paths:' . hash('sha256', $config . ':' . file_get_contents($config) . ':' . $this->version());
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

  /**
   * Gives the directory that holds Vale's browser build.
   *
   * @return string
   *   The directory $settings['vale_wasm'] names, or libraries/vale. It is a
   *   path from the web root, an absolute path or a stream URI, such as
   *   public://vale.
   */
  public function wasmDirectory(): string {
    $directory = Settings::get('vale_wasm');
    return is_string($directory) && $directory !== '' ? rtrim($directory, '/') : static::WASM_DIRECTORY;
  }

  /**
   * Gives where one file of the browser build is on disk.
   *
   * @param string $file
   *   The file, such as vale.wasm.
   *
   * @return string
   *   The path or stream URI.
   */
  protected function wasmPath(string $file): string {
    $directory = $this->wasmDirectory();
    if (StreamWrapperManager::getScheme($directory) !== FALSE || str_starts_with($directory, '/')) {
      return $directory . '/' . $file;
    }
    return $this->appRoot . '/' . $directory . '/' . $file;
  }

  /**
   * Says whether both files of the browser build are there.
   *
   * @return bool
   *   TRUE when both can be read.
   */
  protected function wasmInstalled(): bool {
    foreach (static::WASM_FILES as $file) {
      if (!is_file($this->wasmPath($file)) || !is_readable($this->wasmPath($file))) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Gives the Vale version the browser build was built from.
   *
   * Go writes the build's module versions into the module, as lines such as
   * "mod github.com/vale-cli/vale/v3 v3.26.0". The module is read in parts,
   * and the result is cached until the file changes.
   *
   * @return string|null
   *   The version, such as "3.26.0", or NULL when the build is missing or
   *   does not say.
   */
  public function wasmVersion(): ?string {
    if (!$this->wasmInstalled()) {
      return NULL;
    }
    $module = $this->wasmPath('vale.wasm');
    $cid = 'wasm:' . hash('sha256', $module . ':' . filemtime($module) . ':' . filesize($module));
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $version = NULL;
    $handle = fopen($module, 'rb');
    if ($handle !== FALSE) {
      $tail = '';
      while ($version === NULL && !feof($handle)) {
        $chunk = $tail . fread($handle, 1048576);
        if (preg_match('#\nmod\tgithub\.com/vale-cli/vale(?:/v\d+)?\tv(\d+\.\d+\.\d+)\s#', $chunk, $match)) {
          $version = $match[1];
        }
        // Keep the end, so a line split across two parts is still found.
        $tail = substr($chunk, -256);
      }
      fclose($handle);
    }
    $this->cache->set($cid, $version, $this->time->getRequestTime() + static::ENTRY_LIFETIME);
    return $version;
  }

  /**
   * Gives the URLs browsers load the browser build from.
   *
   * Each URL ends in ?v= and the version, so a browser can cache it for as
   * long as the web server allows, and a new version gets new URLs.
   *
   * @return array{module: string, loader: string}|null
   *   The URL of vale.wasm and of wasm_exec.js, or NULL when the build is
   *   missing or is not under the web root.
   */
  public function wasmUrls(): ?array {
    if (!$this->wasmInstalled()) {
      return NULL;
    }
    $directory = $this->wasmDirectory();
    if (str_starts_with($directory, '/')) {
      if (!str_starts_with($directory, $this->appRoot . '/')) {
        return NULL;
      }
      $directory = substr($directory, strlen($this->appRoot) + 1);
    }
    $version = $this->wasmVersion();
    $query = $version === NULL ? '' : '?' . UrlHelper::buildQuery(['v' => $version]);
    return [
      'module' => $this->fileUrlGenerator->generateString($directory . '/vale.wasm') . $query,
      'loader' => $this->fileUrlGenerator->generateString($directory . '/wasm_exec.js') . $query,
    ];
  }

  /**
   * Describes the browser build for the site status report.
   *
   * @return array{severity: int, value: string, description: string}
   *   The severity, a short value and a longer description. The severity is
   *   -1 for information, 0 for OK and 1 for a warning, as in
   *   RequirementSeverity.
   */
  public function wasmStatus(): array {
    $directory = $this->wasmDirectory();
    if (!$this->wasmInstalled()) {
      $set = is_string(Settings::get('vale_wasm'));
      return [
        'severity' => $set ? 1 : -1,
        'value' => (string) $this->t('Not installed'),
        'description' => $set
          ? (string) $this->t('The browser build set in settings.php is not in @directory. It needs vale.wasm and wasm_exec.js.', ['@directory' => $directory])
          : (string) $this->t("Run vale:install to put it in @directory, or set \$settings['vale_wasm'] in settings.php.", ['@directory' => $directory]),
      ];
    }
    $version = $this->wasmVersion();
    $binary = $this->version();
    $problems = [];
    if ($version === NULL) {
      $problems[] = $this->t('Its vale.wasm does not say which Vale version built it.');
    }
    elseif (version_compare($version, static::WASM_MINIMUM, '<')) {
      $problems[] = $this->t('It needs Vale @minimum or later.', ['@minimum' => static::WASM_MINIMUM]);
    }
    if ($version !== NULL && $binary !== NULL && $version !== $binary) {
      $problems[] = $this->t('The binary is Vale @binary. Install both at the same version, so they give the same results.', ['@binary' => $binary]);
    }
    if ($this->wasmUrls() === NULL) {
      $problems[] = $this->t('It is not under the web root, so browsers cannot load it.');
    }
    $using = $this->t('Using the browser build in @directory.', ['@directory' => $directory]);
    return [
      'severity' => $problems === [] ? 0 : 1,
      'value' => $version ?? (string) $this->t('Unknown'),
      'description' => implode(' ', array_map(strval(...), [$using, ...$problems])),
    ];
  }

}
