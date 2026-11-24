<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Packs the site's styles as zips for Vale's browser build.
 *
 * The browser build reads its styles from an in-memory filesystem. A zip
 * here holds what StylesPath holds, at its top, as a Vale package does, so
 * ValeAddPackage() can load it at the StylesPath the browser configuration
 * names. The Vale browser extension imports the same zip as a package.
 *
 * Only files under the directories Vale loads styles from are packed. A
 * link that points out of them, and any hidden file, is left out.
 */
class ValePackages {

  /**
   * How long a zip is kept, in seconds.
   *
   * A changed style changes the fingerprint, so this only stops an unused
   * zip from being kept forever.
   */
  protected const int LIFETIME = 604800;

  /**
   * Constructs a ValePackages.
   *
   * @param \Drupal\vale\ValeEnvironment $environment
   *   The Vale environment.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The vale cache bin.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   */
  public function __construct(
    protected ValeEnvironment $environment,
    protected CacheBackendInterface $cache,
    protected FileSystemInterface $fileSystem,
    protected TimeInterface $time,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Gives the styles as a zip.
   *
   * The grammars are left out, as Vale uses them only for source code. So
   * are the models and the dictionaries of other languages than English,
   * which come by language from models().
   *
   * @return string|null
   *   The zip, or NULL when there are no styles.
   *
   * @throws \RuntimeException
   *   When the zip could not be made.
   */
  public function styles(): ?string {
    return $this->zip('styles', static function (string $file): bool {
      if (str_starts_with($file, 'config/grammars/') || str_starts_with($file, 'config/models/')) {
        return FALSE;
      }
      if (str_starts_with($file, 'config/dictionaries/')) {
        return static::baseLanguage(basename($file)) === 'en';
      }
      return TRUE;
    });
  }

  /**
   * Gives one language's model and spelling dictionary as a zip.
   *
   * @param string $langcode
   *   A langcode of the site, such as "fr".
   *
   * @return string|null
   *   The zip, or NULL when the language is not one of the site's, is read
   *   as English, or has neither a model nor a dictionary.
   *
   * @throws \RuntimeException
   *   When the zip could not be made.
   */
  public function models(string $langcode): ?string {
    if ($this->languageManager->getLanguage($langcode) === NULL || $this->environment->valeLanguage($langcode) === NULL) {
      return NULL;
    }
    $base = static::baseLanguage($langcode);
    return $this->zip('models:' . $base, static fn (string $file): bool => str_starts_with($file, 'config/models/' . $base . '/')
      || (str_starts_with($file, 'config/dictionaries/') && static::baseLanguage(basename($file)) === $base));
  }

  /**
   * Gives the site's languages that models() has a zip for.
   *
   * A browser asks for a model only in these languages, so a language
   * with none costs no request.
   *
   * @return list<string>
   *   The langcode of each, such as "fr".
   */
  public function modelLanguages(): array {
    $cid = 'zip:models:bases:' . $this->environment->fingerprint();
    if ($cached = $this->cache->get($cid)) {
      $bases = $cached->data;
    }
    else {
      $bases = [];
      $files = $this->files(static fn (string $file): bool => str_starts_with($file, 'config/models/') || str_starts_with($file, 'config/dictionaries/'));
      foreach (array_keys($files) as $file) {
        $parts = explode('/', $file);
        // A model is a directory named as its language.
        $name = $parts[1] === 'models' ? (count($parts) > 3 ? $parts[2] : '') : basename($file);
        $bases[static::baseLanguage($name)] = TRUE;
      }
      unset($bases['']);
      $this->cache->set($cid, $bases, $this->time->getRequestTime() + static::LIFETIME);
    }
    $found = [];
    foreach (array_keys($this->languageManager->getLanguages()) as $langcode) {
      if ($this->environment->valeLanguage($langcode) !== NULL && isset($bases[static::baseLanguage($langcode)])) {
        $found[] = $langcode;
      }
    }
    return $found;
  }

  /**
   * Gives the language part of a langcode or a dictionary's file name.
   *
   * @param string $name
   *   Such as "pt-br", "de_DE.dic" or "fr.aff".
   *
   * @return string
   *   The language, such as "pt", "de" or "fr".
   */
  protected static function baseLanguage(string $name): string {
    return strtolower(substr($name, 0, strcspn($name, '-_.')));
  }

  /**
   * Packs the style files a filter keeps, cached on the fingerprint.
   *
   * @param string $name
   *   The name of the zip in the cache.
   * @param callable(string): bool $keep
   *   Says whether to pack a file, from its path under StylesPath.
   *
   * @return string|null
   *   The zip, or NULL when no file is kept.
   *
   * @throws \RuntimeException
   *   When the zip cannot be written. Nothing is cached then, so the next
   *   request tries again.
   */
  protected function zip(string $name, callable $keep): ?string {
    $cid = 'zip:' . $name . ':' . $this->environment->fingerprint();
    if ($cached = $this->cache->get($cid)) {
      return $cached->data;
    }
    $files = $this->files($keep);
    if ($files === []) {
      return NULL;
    }
    // Such as "styles", or "models:fr", which is named "models for fr".
    $zip = $this->pack($files, str_replace(':', ' for ', $name));
    $this->cache->set($cid, $zip, $this->time->getRequestTime() + static::LIFETIME);
    return $zip;
  }

  /**
   * Lists the style files a filter keeps.
   *
   * @param callable(string): bool $keep
   *   Says whether to list a file, from its path under StylesPath.
   *
   * @return array<string, string>
   *   The paths on disk, keyed and sorted by their paths under StylesPath.
   */
  protected function files(callable $keep): array {
    $files = [];
    foreach ($this->environment->stylesPaths() as $path) {
      $root = realpath($path);
      if ($root === FALSE) {
        continue;
      }
      $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
      foreach ($iterator as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
          continue;
        }
        $relative = substr($file->getPathname(), strlen($root) + 1);
        // Vale reads no hidden files, and a link must stay in StylesPath.
        $real = $file->getRealPath();
        if (preg_match('#(^|/)\.#', $relative) || $real === FALSE || !str_starts_with($real, $root . '/') || !$keep($relative)) {
          continue;
        }
        // Vale reads the first StylesPath that has a file, so this does too.
        $files[$relative] ??= $real;
      }
    }
    ksort($files);
    return $files;
  }

  /**
   * Packs files into a zip.
   *
   * @param array<string, string> $files
   *   The paths on disk, keyed by their names in the zip.
   * @param string $label
   *   Which zip it is, such as "styles" or "models for fr", for the error.
   *
   * @return string
   *   The zip.
   *
   * @throws \RuntimeException
   *   When the zip cannot be written. The message says what failed.
   */
  protected function pack(array $files, string $label): string {
    $error = static fn (string $reason): \RuntimeException => new \RuntimeException(sprintf('The Vale %s zip could not be packed: %s', $label, $reason));
    $temporary = $this->fileSystem->tempnam('temporary://', 'vale');
    // Drupal 10 types tempnam() as string|bool, so this asks for a string.
    $path = is_string($temporary) ? $this->fileSystem->realpath($temporary) : FALSE;
    if ($path === FALSE) {
      if (is_string($temporary)) {
        $this->fileSystem->unlink($temporary);
      }
      throw $error('no temporary file could be made.');
    }
    try {
      $archive = new \ZipArchive();
      if ($archive->open($path, \ZipArchive::OVERWRITE) !== TRUE) {
        throw $error(sprintf('%s could not be opened as a zip.', $path));
      }
      foreach ($files as $name => $file) {
        // ZipArchive reads a file only when the zip is closed, so one that
        // cannot be read is found here first.
        $readable = is_readable($file);
        if (!$readable || !$archive->addFile($file, $name)) {
          // Drop what was added, so closing it reads no file.
          $archive->unchangeAll();
          $archive->close();
          throw $error(sprintf($readable ? '%s could not be added.' : '%s could not be read.', $name));
        }
      }
      // A file removed since the check above fails here.
      if (!$archive->close()) {
        throw $error(sprintf('%s could not be written.', $path));
      }
      $zip = file_get_contents($path);
      if ($zip === FALSE || $zip === '') {
        throw $error(sprintf('%s could not be read back.', $path));
      }
      return $zip;
    }
    finally {
      // The temporary file goes, whether the zip was packed or not.
      if (file_exists($path)) {
        $this->fileSystem->unlink($path);
      }
    }
  }

}
