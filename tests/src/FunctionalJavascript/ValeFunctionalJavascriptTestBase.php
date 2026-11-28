<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\FunctionalJavascript;

use PHPUnit\Framework\Attributes\Group;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use WebDriver\Service\CurlService;
use WebDriver\ServiceFactory;

/**
 * Base class for Vale JavaScript functional tests.
 *
 * @group vale
 */
#[Group('vale')]
abstract class ValeFunctionalJavascriptTestBase extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['vale'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    // Drupal.org's CI and gitlab.local set the site's address themselves.
    if (!$this->hasTooling()) {
      parent::setUp();
      return;
    }

    // Point the browser at the host's PHP webserver. With the default
    // 'chromedriver' backend the browser runs on the host, so 'localhost'
    // is reachable as-is. With the 'selenium' backend the browser runs
    // inside a container that cannot reach the host's 'localhost', so a
    // host-reachable address is used instead (host.docker.internal on
    // macOS, the default docker bridge IP elsewhere). WEBDRIVER_HOST
    // overrides the resolved host.
    $port = getenv('WEBSERVER_PORT') ?: '8000';
    $host = getenv('WEBDRIVER_HOST');

    if ($host === FALSE || $host === '') {
      $backend = getenv('WEBDRIVER_BACKEND') ?: 'chromedriver';
      $host = $backend === 'selenium' ? (PHP_OS_FAMILY === 'Darwin' ? 'host.docker.internal' : '172.17.0.1') : 'localhost';
    }

    putenv('SIMPLETEST_BASE_URL=http://' . $host . ':' . $port);
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   *
   * Drupal 10's driver sends its requests through core's
   * WebDriverCurlService, and current Chrome answers those with a 400. Drupal
   * 11 uses the WebDriver library's own curl service, so this does the same
   * on Drupal 10. On Drupal 11 it changes nothing.
   */
  protected function getDefaultDriverInstance() {
    $driver = parent::getDefaultDriverInstance();
    ServiceFactory::getInstance()->setServiceClass('service.curl', CurlService::class);
    return $driver;
  }

  /**
   * Says whether the scaffold's tooling started the browser.
   *
   * Its commands and its GitHub workflow set one of these variables. No
   * other CI does, and there the site's address and the WebDriver endpoint
   * are left as that CI gives them.
   *
   * @return bool
   *   TRUE when a WEBDRIVER_* variable is set.
   */
  protected function hasTooling(): bool {
    foreach (['WEBDRIVER_BACKEND', 'WEBDRIVER_PORT', 'WEBDRIVER_HOST'] as $name) {
      $value = getenv($name);
      if ($value !== FALSE && $value !== '') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  protected function getMinkDriverArgs(): string|false {
    $args = parent::getMinkDriverArgs();
    if ($args === FALSE || $args === '' || !$this->hasTooling()) {
      return $args;
    }

    $decoded = json_decode($args, TRUE);
    if (!is_array($decoded) || !isset($decoded[2])) {
      return $args;
    }

    // The WebDriver endpoint is always reachable at 'localhost'; only the
    // port varies. Both backends serve the endpoint on the per-project
    // port resolved by 'eddy-browser-start' (WEBDRIVER_PORT, default 4444).
    $backend = getenv('WEBDRIVER_BACKEND') ?: 'chromedriver';
    $port = getenv('WEBDRIVER_PORT') ?: '4444';
    $decoded[2] = 'http://localhost:' . $port;

    // The 'chromedriver' backend drives the host's Chrome directly, so it must
    // run headless to work on CI runners that have no display. The 'selenium'
    // container provides its own virtual display and stays headful.
    if ($backend !== 'selenium') {
      if (!isset($decoded[1]['goog:chromeOptions']['args']) || !is_array($decoded[1]['goog:chromeOptions']['args'])) {
        $decoded[1]['goog:chromeOptions']['args'] = [];
      }

      if (!in_array('--headless=new', $decoded[1]['goog:chromeOptions']['args'], TRUE)) {
        $decoded[1]['goog:chromeOptions']['args'][] = '--headless=new';
      }
    }

    return json_encode($decoded);
  }

  /**
   * Create a screenshot with an auto-generated filename.
   *
   * Filename format: {ms_timestamp}-{Class}-{method}-L{line}.png.
   *
   * @param bool $set_background_color
   *   (optional) Whether to set the background color. Defaults to TRUE.
   */
  protected function createAutoScreenshot(bool $set_background_color = TRUE): void {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

    $line = $trace[0]['line'] ?? 0;
    $class = str_replace('\\', '_', $trace[1]['class'] ?? 'Unknown');
    $method = $trace[1]['function'];
    $timestamp = (int) (microtime(TRUE) * 1000);

    $filename = sprintf('%d-%s-%s-L%d.png', $timestamp, $class, $method, $line);
    $path = \Drupal::root() . '/sites/simpletest/browser_output/' . $filename;

    $this->createScreenshot($path, $set_background_color);
    $this->assertFileExists($path);
  }

}
