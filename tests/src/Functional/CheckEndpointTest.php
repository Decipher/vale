<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\Core\Url;
use Drupal\field\Entity\FieldConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the check endpoint.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class CheckEndpointTest extends BrowserTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_reference_revisions', 'node', 'paragraphs', 'text', 'vale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createValeContentModel();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Logs in a user with the given permissions and sets up Vale.
   *
   * @param string[] $permissions
   *   The permissions.
   */
  protected function logInWith(array $permissions): void {
    $this->drupalLogin($this->asAccount($this->drupalCreateUser($permissions)));
    $this->setUpVale();
  }

  /**
   * Posts a check request.
   *
   * @param array<string, mixed> $body
   *   The request body.
   * @param bool $session
   *   Whether to send the session cookie and a CSRF token.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   The status code and the decoded response.
   *
   * @phpstan-impure
   */
  protected function check(array $body, bool $session = TRUE): array {
    $options = [
      RequestOptions::HEADERS => ['Content-Type' => 'application/json'],
      RequestOptions::BODY => json_encode($body),
      RequestOptions::HTTP_ERRORS => FALSE,
    ];
    if ($session) {
      $options[RequestOptions::COOKIES] = $this->getSessionCookies();
      $options[RequestOptions::HEADERS]['X-CSRF-Token'] = $this->drupalGet('session/token');
    }
    $response = $this->getHttpClient()->request('POST', Url::fromRoute('vale.check')->setAbsolute()->toString(), $options);
    $decoded = json_decode((string) $response->getBody(), TRUE);
    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
  }

  /**
   * Gives a request body for the page's text field.
   *
   * @param string $text
   *   The text.
   * @param string $field
   *   The field.
   *
   * @return array<string, mixed>
   *   The body.
   */
  protected function body(string $text, string $field = 'field_text'): array {
    return ['entity_type' => 'node', 'bundle' => 'page', 'field' => $field, 'format' => 'plain_text', 'text' => $text];
  }

  /**
   * Tests the findings for a piece of text.
   */
  public function testFindings(): void {
    $this->logInWith(['use vale check', 'create page content']);

    [$status, $response] = $this->check($this->body("Fine.\nIt is <em>very</em> good! We delve. Next."));
    $this->assertSame(200, $status);
    $this->assertTrue($response['checked']);
    $this->assertFalse($response['publish_only']);
    $this->assertSame([
      'description' => 'Intensifiers weaken prose.',
      'link' => 'https://example.com/very',
      'sentence' => 'It is very good!',
      'rule' => 'Demo.Very',
      'level' => 'error',
      'message' => "Avoid 'very'.",
      'line' => 2,
      'start' => 11,
      'end' => 14,
      'match' => 'very',
    ], $response['alerts'][0]);
    $this->assertSame(['Demo.Very', 'Demo.Delve'], array_column($response['alerts'], 'rule'));
    $this->assertSame('We delve.', $response['alerts'][1]['sentence']);

    // The field's settings apply.
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Very'])->save();
    [, $response] = $this->check($this->body('It is very good! We delve.'));
    $this->assertSame(['Demo.Delve'], array_column($response['alerts'], 'rule'));
  }

  /**
   * Tests who may use the endpoint.
   */
  public function testAccess(): void {
    // No session.
    [$status] = $this->check($this->body('Very.'), FALSE);
    $this->assertSame(403, $status);

    // The permission, but no right to make pages.
    $this->logInWith(['use vale check']);
    [$status] = $this->check($this->body('Very.'));
    $this->assertSame(403, $status);

    // Pages, but not the permission.
    $this->logInWith(['create page content']);
    [$status] = $this->check($this->body('Very.'));
    $this->assertSame(403, $status);
    $this->assertSame([], $this->valeRuns());
  }

  /**
   * Tests a session request without a CSRF token.
   */
  public function testCsrf(): void {
    $this->logInWith(['use vale check', 'create page content']);
    $response = $this->getHttpClient()->request('POST', Url::fromRoute('vale.check')->setAbsolute()->toString(), [
      RequestOptions::COOKIES => $this->getSessionCookies(),
      RequestOptions::BODY => json_encode($this->body('Very.')),
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $this->assertSame(403, $response->getStatusCode());
  }

  /**
   * Tests requests that are refused before Vale runs.
   */
  public function testBadRequests(): void {
    $this->logInWith(['use vale check', 'create page content']);

    [$status, $response] = $this->check($this->body('Very.', 'field_code'));
    $this->assertSame(400, $status);
    $this->assertSame('This field is not checked by Vale.', $response['message']);

    [$status, $response] = $this->check($this->body('Very.') + ['config' => '/etc/other.ini']);
    $this->assertSame(400, $status);
    $this->assertStringStartsWith('Unknown keys: config.', $response['message']);

    [$status, $response] = $this->check(['text' => 5] + $this->body('Very.'));
    $this->assertSame(400, $status);
    $this->assertSame('"text" must be a string.', $response['message']);

    [$status, $response] = $this->check(['bundle' => 'nope'] + $this->body('Very.'));
    $this->assertSame(400, $status);
    $this->assertSame('Unknown entity type or bundle.', $response['message']);

    $this->assertSame([], $this->valeRuns());
  }

  /**
   * Tests the rate limit.
   */
  public function testRateLimit(): void {
    $this->config('vale.settings')->set('check_limit', 2)->save();
    $this->logInWith(['use vale check', 'create page content']);

    $this->assertSame(200, $this->check($this->body('Fine.'))[0]);
    $this->assertSame(200, $this->check($this->body('Fine.'))[0]);
    [$status, $response] = $this->check($this->body('Fine.'));
    $this->assertSame(429, $status);
    $this->assertSame('Too many checks. Try again shortly.', $response['message']);
  }

}
