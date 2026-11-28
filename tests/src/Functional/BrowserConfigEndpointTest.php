<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\ResponseInterface;

/**
 * Tests the routes that serve the Vale configuration, over HTTP.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class BrowserConfigEndpointTest extends BrowserTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['vale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Requests a route, with the session of the user logged in.
   *
   * @param string $route
   *   The route.
   * @param array<string, string> $parameters
   *   Its parameters.
   * @param array<string, string> $headers
   *   Request headers.
   */
  protected function fetch(string $route, array $parameters = [], array $headers = []): ResponseInterface {
    return $this->getHttpClient()->request('GET', Url::fromRoute($route, $parameters)->setAbsolute()->toString(), [
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::HEADERS => $headers,
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
  }

  /**
   * Tests what a user with the permission gets.
   */
  public function testServed(): void {
    $this->drupalLogin($this->asAccount($this->drupalCreateUser(['use vale check', 'administer site configuration'])));
    $this->setUpVale();
    // A file beside the configuration is never served.
    file_put_contents($this->valeDirectory . '/secret.txt', 'secret');

    $response = $this->fetch('vale.browser_config', ['langcode' => 'en']);
    $this->assertSame(200, $response->getStatusCode());
    $this->assertStringStartsWith("StylesPath = /styles\nMinAlertLevel = suggestion\n", (string) $response->getBody());

    $response = $this->fetch('vale.browser_styles');
    $this->assertSame(200, $response->getStatusCode());
    $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
    $this->assertStringContainsString('private', $response->getHeaderLine('Cache-Control'));
    $zip = $this->valeDirectory . '/served.zip';
    file_put_contents($zip, (string) $response->getBody());
    $archive = new \ZipArchive();
    $this->assertTrue($archive->open($zip));
    $names = [];
    for ($i = 0; $i < $archive->numFiles; $i++) {
      $names[] = $archive->getNameIndex($i);
    }
    $archive->close();
    sort($names);
    $this->assertSame(['Demo/Delve.yml', 'Demo/Really.yml', 'Demo/Very.yml'], $names);

    // The browser keeps the zip until the styles change.
    $etag = $response->getHeaderLine('ETag');
    $this->assertNotSame('', $etag);
    $this->assertSame(304, $this->fetch('vale.browser_styles', [], ['If-None-Match' => $etag])->getStatusCode());

    // English has no model, and a path is not a langcode.
    $this->assertSame(404, $this->fetch('vale.browser_models', ['langcode' => 'en'])->getStatusCode());
    $this->assertSame(404, $this->fetch('vale.browser_config', ['langcode' => '..'])->getStatusCode());

    // The status report links to the zip.
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->linkByHrefExists(Url::fromRoute('vale.browser_styles')->toString());
  }

  /**
   * Tests that a user without the permission gets nothing.
   */
  public function testRefused(): void {
    $this->drupalLogin($this->asAccount($this->drupalCreateUser(['administer site configuration'])));
    $this->setUpVale();
    $routes = [
      ['vale.browser_config', ['langcode' => 'en']],
      ['vale.browser_styles', []],
      ['vale.browser_models', ['langcode' => 'en']],
    ];
    foreach ($routes as [$route, $parameters]) {
      $this->assertSame(403, $this->fetch($route, $parameters)->getStatusCode(), $route);
    }
    $this->drupalGet('admin/reports/status');
    $this->assertSession()->linkByHrefNotExists(Url::fromRoute('vale.browser_styles')->toString());

    $this->drupalLogout();
    foreach ($routes as [$route, $parameters]) {
      $this->assertSame(403, $this->fetch($route, $parameters)->getStatusCode(), $route);
    }
  }

}
