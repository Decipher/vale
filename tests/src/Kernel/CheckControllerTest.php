<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Lock\DatabaseLockBackend;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Controller\CheckController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the check endpoint's controller.
 *
 * CheckEndpointTest covers the route, its permission and CSRF over HTTP.
 * This covers the controller's own decisions.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class CheckControllerTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'node',
    'paragraphs',
    'system',
    'text',
    'user',
    'vale',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'node', 'vale']);
    $this->setUpVale();
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
   * Sends a body to the controller.
   *
   * @param mixed $body
   *   The body, as JSON.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   The status code and the decoded response.
   */
  protected function check(mixed $body): array {
    $request = Request::create('/vale/check', 'POST', [], [], [], [], is_string($body) ? $body : (string) json_encode($body));
    $response = CheckController::create($this->container)->check($request);
    $decoded = json_decode((string) $response->getContent(), TRUE);
    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
  }

  /**
   * Gives a body for the page's text field.
   *
   * @return array<string, mixed>
   *   The body.
   */
  protected function body(string $text, string $field = 'field_text'): array {
    return ['entity_type' => 'node', 'bundle' => 'page', 'field' => $field, 'format' => NULL, 'text' => $text];
  }

  /**
   * Tests findings, field settings and an unchecked text.
   */
  public function testFindings(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);

    [$status, $response] = $this->check($this->body("Fine.\nIt is very good! We delve."));
    $this->assertSame(200, $status);
    $this->assertTrue($response['checked']);
    $this->assertSame(['Demo.Very', 'Demo.Delve'], array_column($response['alerts'], 'rule'));
    $this->assertSame('It is very good!', $response['alerts'][0]['sentence']);
    $this->assertSame('https://example.com/very', $response['alerts'][0]['link']);

    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Very'])->save();
    [, $response] = $this->check($this->body('It is very good! We delve.'));
    $this->assertSame(['Demo.Delve'], array_column($response['alerts'], 'rule'));

    $this->setSetting('vale_binary', NULL);
    [$status, $response] = $this->check($this->body('Very.'));
    $this->assertSame(200, $status);
    $this->assertFalse($response['checked']);
    // The reason names no server paths.
    $this->assertSame('Vale could not check the text.', $response['reason']);
  }

  /**
   * Tests the requests that are refused.
   */
  public function testRefused(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);

    $refusals = [
      ['not json', 400, 'The request body must be a JSON object.'],
      [$this->body('Very.') + ['config' => '/etc/x.ini'], 400, 'Unknown keys: config. Only entity_type, bundle, id, field, format, text are accepted.'],
      [['text' => 5] + $this->body(''), 400, '"text" must be a string.'],
      [['format' => 5] + $this->body('Very.'), 400, '"format" must be a string or null.'],
      [['id' => [1]] + $this->body('Very.'), 400, '"id" must be a string, an integer or null.'],
      [['id' => 99] + $this->body('Very.'), 403, 'You may not check text for this bundle.'],
      [$this->body(str_repeat('a', 100001)), 400, '"text" is longer than 100000 characters.'],
      [['bundle' => 'nope'] + $this->body('Very.'), 400, 'Unknown entity type or bundle.'],
      [['entity_type' => 'nope'] + $this->body('Very.'), 400, 'Unknown entity type or bundle.'],
      [$this->body('Very.', 'field_code'), 400, 'This field is not checked by Vale.'],
      [$this->body('Very.', 'field_nope'), 400, 'This field is not checked by Vale.'],
    ];
    foreach ($refusals as [$body, $code, $message]) {
      [$status, $response] = $this->check($body);
      $this->assertSame([$code, $message], [$status, $response['message']]);
    }
    $this->assertSame([], $this->valeRuns());

    // No right to create pages.
    $this->setUpCurrentUser([], ['access content']);
    [$status] = $this->check($this->body('Very.'));
    $this->assertSame(403, $status);
  }

  /**
   * Tests the rate limit.
   */
  public function testRateLimit(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);
    $this->config('vale.settings')->set('check_limit', 1)->set('check_window', 30)->save();
    $this->assertSame(200, $this->check($this->body('Fine.'))[0]);

    $request = Request::create('/vale/check', 'POST', [], [], [], [], (string) json_encode($this->body('Fine.')));
    $response = CheckController::create($this->container)->check($request);
    $this->assertSame(429, $response->getStatusCode());
    $this->assertSame('30', $response->headers->get('Retry-After'));
  }

  /**
   * Tests checking text for an entity that exists.
   */
  public function testExistingEntity(): void {
    $owner = $this->setUpCurrentUser([], ['access content', 'edit own page content']);
    $page = Node::create(['type' => 'page', 'title' => 'Mine', 'uid' => $owner->id()]);
    $page->save();
    // Editing an existing page needs no right to create pages.
    [$status, $response] = $this->check(['id' => $page->id()] + $this->body('Very.'));
    $this->assertSame(200, $status);
    $this->assertTrue($response['checked']);

    $this->setUpCurrentUser([], ['access content', 'edit own page content']);
    [$status] = $this->check(['id' => (string) $page->id()] + $this->body('Very.'));
    $this->assertSame(403, $status);
  }

  /**
   * Tests that only a few checks run Vale at once.
   */
  public function testBusy(): void {
    $this->setUpCurrentUser([], ['access content', 'create page content']);
    // Kernel tests use a lock backend that always grants the lock, so use
    // real locks. Another request holds every slot: a lock backend of its
    // own has its own lock id, as another request would.
    $this->container->set('lock', new DatabaseLockBackend($this->container->get('database')));
    $lock = new DatabaseLockBackend($this->container->get('database'));
    for ($i = 0; $i < 4; $i++) {
      $this->assertTrue($lock->acquire('vale.check.' . $i));
    }
    [$status, $response] = $this->check($this->body('Very.'));
    $this->assertSame([503, 'Vale is busy. Try again shortly.'], [$status, $response['message']]);
    $this->assertSame([], $this->valeRuns());

    $lock->release('vale.check.2');
    $this->assertSame(200, $this->check($this->body('Very.'))[0]);
    // The slot is free again after the check.
    $this->assertTrue($lock->acquire('vale.check.2'));
  }

}
