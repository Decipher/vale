<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Traits;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\After;
use Symfony\Component\Filesystem\Filesystem;

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
    // Saving content writes to the content report's table.
    if ($this instanceof KernelTestBase && !\Drupal::database()->schema()->tableExists('vale_finding')) {
      $this->installSchema('vale', ['vale_finding']);
    }
    $this->setValeSetting('vale_binary', $binary ?? $fixtures . '/bin/fake-vale');
    $this->setValeSetting('vale_config', $this->valeDirectory . '/.vale.ini');
  }

  /**
   * Sets a setting for the code under test.
   *
   * A kernel test runs that code in its own process. A functional test runs
   * it on the test site, which reads settings.php. Call setUpVale() after
   * drupalLogin(): rewriting settings.php first makes the login check fail.
   *
   * @param string $name
   *   The setting.
   * @param mixed $value
   *   Its value.
   */
  protected function setValeSetting(string $name, mixed $value): void {
    if ($this instanceof KernelTestBase) {
      $this->setSetting($name, $value);
      return;
    }
    $this->writeSettings(['settings' => [$name => (object) ['value' => $value, 'required' => TRUE]]]);
  }

  /**
   * Removes the copied configuration after each test.
   *
   * @after
   */
  #[After]
  protected function tearDownVale(): void {
    if (isset($this->valeDirectory)) {
      (new Filesystem())->remove($this->valeDirectory);
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
   * Narrows a created user to a user.
   *
   * Drupal 10 types drupalCreateUser() as User|false and Drupal 11 types it
   * as UserInterface, so a plain assertion fails static analysis on one.
   *
   * @param mixed $account
   *   The created user.
   *
   * @return \Drupal\user\UserInterface
   *   The user.
   */
  protected function asAccount(mixed $account): UserInterface {
    if (!$account instanceof UserInterface) {
      throw new \LogicException('The user was not created.');
    }
    return $account;
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
