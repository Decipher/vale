<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Traits;

/**
 * Points a test site at a Vale binary and a copy of the Demo style.
 *
 * The fake binary in tests/fixtures/bin stands in for Vale, so tests run
 * where Vale is not installed. The configuration is copied to a temporary
 * directory, so a test can change a style or count runs in calls.log.
 */
trait ValeTestTrait {

  /**
   * The directory that holds the copied .vale.ini and styles.
   */
  protected string $valeDirectory;

  /**
   * Copies the Demo configuration and sets the Vale settings.
   *
   * @param string|null $binary
   *   The Vale binary, or NULL for the fake one.
   */
  protected function setUpVale(?string $binary = NULL): void {
    // A test that sets Vale up again leaves no directory behind.
    $this->tearDownVale();
    $fixtures = dirname(__DIR__, 2) . '/fixtures';
    $this->valeDirectory = sys_get_temp_dir() . '/vale-test-' . bin2hex(random_bytes(6));
    $this->copyDirectory($fixtures . '/vale', $this->valeDirectory);
    $this->setSetting('vale_binary', $binary ?? $fixtures . '/bin/fake-vale');
    $this->setSetting('vale_config', $this->valeDirectory . '/.vale.ini');
  }

  /**
   * Removes the copied configuration.
   */
  protected function tearDownVale(): void {
    if (isset($this->valeDirectory) && is_dir($this->valeDirectory)) {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($this->valeDirectory, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
      );
      foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
      }
      rmdir($this->valeDirectory);
    }
  }

  /**
   * Gives the --path values the fake binary was run with.
   *
   * @return string[]
   *   One value per lint run, oldest first.
   */
  protected function valeRuns(): array {
    $log = $this->valeDirectory . '/calls.log';
    return is_file($log) ? array_values(array_filter(explode("\n", (string) file_get_contents($log)))) : [];
  }

  /**
   * Copies a directory and everything in it.
   *
   * @param string $source
   *   The directory to copy.
   * @param string $destination
   *   Where to copy it to.
   */
  private function copyDirectory(string $source, string $destination): void {
    mkdir($destination, 0777, TRUE);
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($iterator as $file) {
      $target = $destination . substr($file->getPathname(), strlen($source));
      $file->isDir() ? mkdir($target) : copy($file->getPathname(), $target);
    }
  }

}
