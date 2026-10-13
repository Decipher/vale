<?php

declare(strict_types=1);

namespace Drupal\vale;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\RequestOptions;

/**
 * Downloads a pinned Vale release and checks it before installing it.
 *
 * The module pins a version and the SHA-256 of each platform's archive, so an
 * install is the same everywhere and a changed download is refused. Another
 * version can be installed by giving its checksum too.
 */
class ValeInstaller {

  /**
   * The Vale version the module pins.
   */
  public const string VERSION = '3.18.0';

  /**
   * The SHA-256 of each archive of the pinned version, from the release.
   */
  protected const array CHECKSUMS = [
    'Linux_64-bit' => 'a6f71a75a12fe689345b754f2412b90367fe33648abb7d200fa19eaadc2dbf6d',
    'Linux_arm64' => '228325a79f3e6d8b4798d82901f8ed36f6d12b7ff0bad2cc83512dde0c84fbcc',
    'macOS_64-bit' => '7ec47dad588bf1421e12f72f15219dcc96e3adf7204918ee86ea81fe8cddab8d',
    'macOS_arm64' => '4d7de9bda8379da14cd45048500199c549413187526d0913383ad822866ad43d',
  ];

  /**
   * Constructs a ValeInstaller.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   */
  public function __construct(
    protected ClientInterface $httpClient,
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
   * Downloads, checks and installs the Vale binary.
   *
   * @param string $directory
   *   The directory to put the binary in. It is created when missing.
   * @param string|null $version
   *   A version other than the pinned one, or NULL for the pinned one.
   * @param string|null $sha256
   *   The archive's SHA-256. Required with another version.
   * @param string|null $platform
   *   The platform, or NULL for this machine.
   *
   * @return string
   *   The path of the installed binary.
   *
   * @throws \InvalidArgumentException
   *   When another version is asked for without its checksum.
   * @throws \RuntimeException
   *   When the download fails, the checksum differs, or the archive has no
   *   binary.
   */
  public function install(string $directory, ?string $version = NULL, ?string $sha256 = NULL, ?string $platform = NULL): string {
    $version ??= self::VERSION;
    $platform ??= $this->platform();
    if ($version !== self::VERSION && $sha256 === NULL) {
      throw new \InvalidArgumentException(sprintf('Vale %s is not the pinned %s. Give its SHA-256 from the release checksums with --sha256.', $version, self::VERSION));
    }
    $expected = $sha256 ?? self::CHECKSUMS[$platform] ?? NULL;
    if (!is_string($expected) || !preg_match('/^[0-9a-f]{64}$/', strtolower($expected))) {
      throw new \InvalidArgumentException(sprintf('No valid SHA-256 for %s.', $platform));
    }

    $name = sprintf('vale_%s_%s.tar.gz', $version, $platform);
    $url = sprintf('https://github.com/errata-ai/vale/releases/download/v%s/%s', $version, $name);
    $work = sys_get_temp_dir() . '/vale-install-' . bin2hex(random_bytes(6));
    mkdir($work, 0700, TRUE);
    try {
      $archive = $work . '/' . $name;
      try {
        $this->httpClient->request('GET', $url, [RequestOptions::SINK => $archive, RequestOptions::TIMEOUT => 120]);
      }
      catch (GuzzleException $e) {
        throw new \RuntimeException(sprintf('Vale could not be downloaded from %s: %s', $url, $e->getMessage()), 0, $e);
      }

      $actual = hash_file('sha256', $archive);
      if (!hash_equals(strtolower($expected), (string) $actual)) {
        throw new \RuntimeException(sprintf('The checksum of %s is %s, not %s. It was not installed.', $name, $actual, $expected));
      }

      try {
        (new \PharData($archive))->extractTo($work, 'vale', TRUE);
      }
      catch (\PharException | \UnexpectedValueException) {
        // A missing binary or an unreadable archive, reported below.
      }
      if (!is_file($work . '/vale')) {
        throw new \RuntimeException(sprintf('%s has no vale binary in it.', $name));
      }

      if (!is_dir($directory) && !mkdir($directory, 0755, TRUE) && !is_dir($directory)) {
        throw new \RuntimeException(sprintf('The directory %s could not be created.', $directory));
      }
      $binary = rtrim($directory, '/') . '/vale';
      if (!rename($work . '/vale', $binary) && !copy($work . '/vale', $binary)) {
        throw new \RuntimeException(sprintf('Vale could not be written to %s.', $binary));
      }
      chmod($binary, 0755);
      return $binary;
    }
    finally {
      $this->removeDirectory($work);
    }
  }

  /**
   * Removes a directory and everything in it.
   *
   * @param string $directory
   *   The directory.
   */
  protected function removeDirectory(string $directory): void {
    if (!is_dir($directory)) {
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $file) {
      $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
  }

}
