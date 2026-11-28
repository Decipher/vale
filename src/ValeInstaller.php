<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\File\Exception\FileException;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Downloads a Vale release and checks it before installing it.
 *
 * It installs the latest release, or a version asked for. Each archive is
 * checked against the SHA-256 the release publishes in its checksums file, so
 * a new Vale version needs no new module release.
 */
class ValeInstaller {

  /**
   * Where Vale's releases are published.
   */
  protected const string RELEASES = 'https://github.com/vale-cli/vale/releases';

  /**
   * Constructs a ValeInstaller.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system service.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected FileSystemInterface $fileSystem,
  ) {}

  /**
   * Gives the release archive name for this machine.
   *
   * @param string|null $os
   *   The operating system, as php_uname('s') gives it, or NULL for this one.
   * @param string|null $machine
   *   The machine type, as php_uname('m') gives it, or NULL for this one.
   *
   * @return string
   *   The platform part of the archive name, such as "Linux_64-bit".
   *
   * @throws \RuntimeException
   *   When Vale publishes no archive for the platform.
   */
  public function platform(?string $os = NULL, ?string $machine = NULL): string {
    $os ??= php_uname('s');
    $machine ??= php_uname('m');
    $system = match ($os) {
      'Linux' => 'Linux',
      'Darwin' => 'macOS',
      default => throw new \RuntimeException(sprintf('Vale has no release for %s. Install it by hand.', $os)),
    };
    $arch = match ($machine) {
      'x86_64', 'amd64' => '64-bit',
      'aarch64', 'arm64' => 'arm64',
      default => throw new \RuntimeException(sprintf('Vale has no release for %s on %s. Install it by hand.', $os, $machine)),
    };
    return $system . '_' . $arch;
  }

  /**
   * Gives the version of Vale's latest release.
   *
   * GitHub redirects the latest release page to the release's own page, which
   * names the version. That needs no API request, so it is not rate limited.
   * A repository that moved redirects first, so a few redirects are followed.
   *
   * @return string
   *   The version, such as "3.24.0".
   *
   * @throws \RuntimeException
   *   When the latest release cannot be found.
   */
  public function latestVersion(): string {
    $url = self::RELEASES . '/latest';
    for ($hop = 0; $hop < 3 && $url !== ''; $hop++) {
      try {
        $response = $this->httpClient->request('GET', $url, [
          RequestOptions::ALLOW_REDIRECTS => FALSE,
          RequestOptions::TIMEOUT => 30,
        ]);
      }
      catch (GuzzleException $e) {
        throw new \RuntimeException(sprintf('The latest Vale release could not be found: %s', $e->getMessage()), 0, $e);
      }
      $url = $response->getHeaderLine('Location');
      if (preg_match('#/tag/v(\d+\.\d+\.\d+)$#', $url, $match)) {
        return $match[1];
      }
    }
    throw new \RuntimeException('The latest Vale release could not be found. Give a version with --vale-version.');
  }

  /**
   * Gives the SHA-256 a release publishes for one of its archives.
   *
   * @param string $version
   *   The version.
   * @param string $name
   *   The archive's file name.
   *
   * @return string
   *   The SHA-256.
   *
   * @throws \RuntimeException
   *   When the checksums file cannot be read or does not list the archive.
   */
  public function checksum(string $version, string $name): string {
    $url = sprintf('%s/download/v%s/vale_%s_checksums.txt', self::RELEASES, $version, $version);
    try {
      $list = (string) $this->httpClient->request('GET', $url, [RequestOptions::TIMEOUT => 30])->getBody();
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException(sprintf('The checksums of Vale %s could not be downloaded from %s: %s', $version, $url, $e->getMessage()), 0, $e);
    }
    // Each line is "<sha256>  <file name>".
    foreach (preg_split('/\R/', $list) ?: [] as $line) {
      $parts = preg_split('/\s+/', trim($line)) ?: [];
      if (count($parts) === 2 && $parts[1] === $name && preg_match('/^[0-9a-f]{64}$/', strtolower($parts[0]))) {
        return strtolower($parts[0]);
      }
    }
    throw new \RuntimeException(sprintf('The checksums of Vale %s do not list %s.', $version, $name));
  }

  /**
   * Downloads, checks and installs the Vale binary.
   *
   * @param string $directory
   *   The directory to put the binary in. It is created when missing.
   * @param string|null $version
   *   The version, or NULL for the latest release.
   * @param string|null $sha256
   *   The archive's SHA-256, or NULL to take it from the release.
   * @param string|null $platform
   *   The platform, or NULL for this machine.
   *
   * @return string
   *   The path of the installed binary.
   *
   * @throws \InvalidArgumentException
   *   When the version or the SHA-256 given is not valid.
   * @throws \RuntimeException
   *   When a download fails, the checksum differs, or the archive has no
   *   binary.
   */
  public function install(string $directory, ?string $version = NULL, ?string $sha256 = NULL, ?string $platform = NULL): string {
    $this->validate($version, $sha256);
    $version ??= $this->latestVersion();
    $platform ??= $this->platform();
    $name = sprintf('vale_%s_%s.tar.gz', $version, $platform);
    $work = $this->workDirectory();
    try {
      $this->extract($this->download($version, $name, $sha256, $work), $work, ['vale']);
      if (!is_file($work . '/vale')) {
        throw new \RuntimeException(sprintf('%s has no vale binary in it.', $name));
      }
      $this->prepare($directory);
      $binary = rtrim($directory, '/') . '/vale';
      try {
        $this->place([$work . '/vale' => $binary], 0755);
      }
      catch (FileException $e) {
        throw new \RuntimeException(sprintf('Vale could not be written to %s.', $binary), 0, $e);
      }
      return $binary;
    }
    finally {
      $this->fileSystem->deleteRecursive($work);
    }
  }

  /**
   * Downloads, checks and installs Vale's browser build.
   *
   * The build is Vale compiled to WebAssembly: vale.wasm, and the
   * wasm_exec.js that loads it. Releases before 3.26.0 have none.
   *
   * @param string $directory
   *   The directory to put the files in. It is created when missing.
   * @param string $version
   *   The version. Give the binary's version, so the two match.
   * @param string|null $sha256
   *   The archive's SHA-256, or NULL to take it from the release.
   *
   * @return bool
   *   TRUE when it was installed, or FALSE when the version has no browser
   *   build.
   *
   * @throws \InvalidArgumentException
   *   When the version or the SHA-256 given is not valid.
   * @throws \RuntimeException
   *   When a download fails, the checksum differs, or the archive is missing
   *   a file.
   */
  public function installWasm(string $directory, string $version, ?string $sha256 = NULL): bool {
    $this->validate($version, $sha256);
    if (version_compare($version, ValeEnvironment::WASM_MINIMUM, '<')) {
      return FALSE;
    }
    $name = sprintf('vale_%s_wasm.tar.gz', $version);
    $work = $this->workDirectory();
    try {
      $files = ValeEnvironment::WASM_FILES;
      $this->extract($this->download($version, $name, $sha256, $work), $work, $files);
      foreach ($files as $file) {
        if (!is_file($work . '/' . $file)) {
          throw new \RuntimeException(sprintf('%s has no %s in it.', $name, $file));
        }
      }
      $this->prepare($directory);
      $targets = [];
      foreach ($files as $file) {
        $targets[$work . '/' . $file] = rtrim($directory, '/') . '/' . $file;
      }
      try {
        $this->place($targets, 0644);
      }
      catch (FileException $e) {
        throw new \RuntimeException(sprintf("Vale's browser build could not be written to %s.", $directory), 0, $e);
      }
      return TRUE;
    }
    finally {
      $this->fileSystem->deleteRecursive($work);
    }
  }

  /**
   * Checks the version and the SHA-256 given.
   *
   * @param string|null $version
   *   The version, or NULL.
   * @param string|null $sha256
   *   The SHA-256, or NULL.
   *
   * @throws \InvalidArgumentException
   *   When either is not valid.
   */
  protected function validate(?string $version, ?string $sha256): void {
    if ($version !== NULL && !preg_match('/^\d+\.\d+\.\d+$/', $version)) {
      throw new \InvalidArgumentException(sprintf('"%s" is not a Vale version, such as 3.24.0.', $version));
    }
    if ($sha256 !== NULL && !preg_match('/^[0-9a-f]{64}$/', strtolower($sha256))) {
      throw new \InvalidArgumentException('The SHA-256 must be 64 hexadecimal characters.');
    }
  }

  /**
   * Makes a temporary directory to work in.
   *
   * @return string
   *   The directory.
   *
   * @throws \RuntimeException
   *   When it cannot be created.
   */
  protected function workDirectory(): string {
    $work = $this->fileSystem->getTempDirectory() . '/vale-install-' . bin2hex(random_bytes(6));
    if (!$this->fileSystem->mkdir($work, 0700, TRUE)) {
      throw new \RuntimeException(sprintf('The temporary directory %s could not be created.', $work));
    }
    return $work;
  }

  /**
   * Downloads a release archive and checks its checksum.
   *
   * @param string $version
   *   The version.
   * @param string $name
   *   The archive's file name.
   * @param string|null $sha256
   *   The archive's SHA-256, or NULL to take it from the release.
   * @param string $work
   *   The directory to download into.
   *
   * @return string
   *   The path of the downloaded archive.
   *
   * @throws \RuntimeException
   *   When the download fails or the checksum differs.
   */
  protected function download(string $version, string $name, ?string $sha256, string $work): string {
    $expected = $sha256 === NULL ? $this->checksum($version, $name) : strtolower($sha256);
    $url = sprintf('%s/download/v%s/%s', self::RELEASES, $version, $name);
    $archive = $work . '/' . $name;
    try {
      $this->httpClient->request('GET', $url, [RequestOptions::SINK => $archive, RequestOptions::TIMEOUT => 120]);
    }
    catch (GuzzleException $e) {
      throw new \RuntimeException(sprintf('Vale could not be downloaded from %s: %s', $url, $e->getMessage()), 0, $e);
    }
    $actual = hash_file('sha256', $archive);
    if (!hash_equals($expected, (string) $actual)) {
      throw new \RuntimeException(sprintf('The checksum of %s is %s, not %s. It was not installed.', $name, $actual, $expected));
    }
    return $archive;
  }

  /**
   * Extracts files from an archive.
   *
   * A missing file or an unreadable archive is not an error here. The caller
   * checks that each file it needs was extracted.
   *
   * @param string $archive
   *   The archive.
   * @param string $work
   *   The directory to extract into.
   * @param string[] $files
   *   The files to extract.
   */
  protected function extract(string $archive, string $work, array $files): void {
    try {
      (new \PharData($archive))->extractTo($work, $files, TRUE);
    }
    catch (\PharException | \UnexpectedValueException) {
      // The caller reports what is missing.
    }
  }

  /**
   * Creates a target directory.
   *
   * @param string $directory
   *   The directory.
   *
   * @throws \RuntimeException
   *   When it does not exist and cannot be created, or cannot be written.
   */
  protected function prepare(string $directory): void {
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY)) {
      throw new \RuntimeException(sprintf('The directory %s does not exist or cannot be written.', $directory));
    }
  }

  /**
   * Puts files into place.
   *
   * Each file is copied next to its target with its mode set, then renamed
   * into place. A rename in one directory is atomic, so nothing reads a
   * part-written file, and a failure before it leaves the old file as it
   * was. FileSystem::move() is not used because it resets the mode after the
   * rename.
   *
   * @param array<string, string> $targets
   *   The target of each file, keyed by the file.
   * @param int $mode
   *   The mode to give each target.
   *
   * @throws \Drupal\Core\File\Exception\FileException
   *   When a file cannot be copied, given its mode or renamed. The staged
   *   copies are removed.
   */
  protected function place(array $targets, int $mode): void {
    $staged = [];
    try {
      foreach ($targets as $source => $target) {
        $copy = $this->fileSystem->copy($source, $target . '.new', FileExists::Rename);
        $staged[$copy] = $target;
        if (!$this->fileSystem->chmod($copy, $mode)) {
          throw new FileException(sprintf('%s could not be given its mode.', $copy));
        }
      }
      foreach ($staged as $copy => $target) {
        if (!@rename($copy, $target)) {
          throw new FileException(sprintf('%s could not be moved to %s.', $copy, $target));
        }
        unset($staged[$copy]);
      }
    }
    finally {
      foreach (array_keys($staged) as $copy) {
        if (file_exists($copy)) {
          $this->fileSystem->delete($copy);
        }
      }
    }
  }

}
