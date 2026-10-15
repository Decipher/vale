<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
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
   */
  public function __construct(
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
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
    $this->cache->set($cid, $version, $this->time->getRequestTime() + static::PROBE_LIFETIME);
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
    $paths = [];
    try {
      $process = $this->process(['ls-config']);
      $process->run();
      $listing = json_decode($process->getOutput(), TRUE);
      foreach (is_array($listing) && is_array($listing['Paths'] ?? NULL) ? $listing['Paths'] : [] as $path) {
        if (is_string($path) && is_dir($path)) {
          $paths[] = $path;
        }
      }
    }
    catch (ProcessException) {
      // No paths are known, so no rules are known.
    }
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
    $reason = $this->unavailableReason();
    if ($reason !== NULL) {
      return [
        'available' => FALSE,
        'value' => 'Not available',
        'description' => $reason . ' Content is saved without being checked.',
      ];
    }
    $version = $this->version();
    if ($version === NULL) {
      return [
        'available' => FALSE,
        'value' => 'Not available',
        'description' => 'The binary set in settings.php did not report a Vale version. Content is saved without being checked.',
      ];
    }
    return [
      'available' => TRUE,
      'value' => $version,
      'description' => sprintf('Using %s with %s. Its styles hold %d rules.', $this->binary(), $this->configPath(), count($this->rules())),
    ];
  }

}
