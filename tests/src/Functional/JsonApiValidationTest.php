<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\Core\Url;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\user\UserInterface;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that JSON:API writes are held to the Vale checks.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class JsonApiValidationTest extends BrowserTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'basic_auth',
    'entity_reference_revisions',
    'jsonapi',
    'node',
    'paragraphs',
    'serialization',
    'text',
    'vale',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The user the requests are made as.
   */
  protected UserInterface $author;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createValeContentModel();
    $this->config('jsonapi.settings')->set('read_only', FALSE)->save();
    // JSON:API has a route per bundle, and the bundles were made after the
    // site was installed.
    $this->container->get('router.builder')->rebuild();
    $this->author = $this->asAccount($this->drupalCreateUser(['access content', 'administer nodes', 'bypass node access']));
    $this->setUpVale();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Sends a JSON:API request as the author.
   *
   * @param string $method
   *   The method.
   * @param string $path
   *   The path below /jsonapi.
   * @param array<string, mixed> $document
   *   The request document.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   The status code and the decoded response document.
   */
  protected function request(string $method, string $path, array $document): array {
    // drupalCreateUser() keeps the plain password on the account.
    /** @var string $password */
    $password = $this->author->passRaw;
    $response = $this->getHttpClient()->request($method, Url::fromUri('base:/jsonapi/' . $path)->setAbsolute()->toString(), [
      RequestOptions::AUTH => [$this->author->getAccountName(), $password],
      RequestOptions::HEADERS => [
        'Accept' => 'application/vnd.api+json',
        'Content-Type' => 'application/vnd.api+json',
      ],
      RequestOptions::BODY => json_encode($document, JSON_THROW_ON_ERROR),
      RequestOptions::HTTP_ERRORS => FALSE,
    ]);
    $decoded = json_decode((string) $response->getBody(), TRUE);
    return [$response->getStatusCode(), is_array($decoded) ? $decoded : []];
  }

  /**
   * Sends a PATCH of a page's attributes.
   *
   * @param \Drupal\node\NodeInterface $page
   *   The page.
   * @param array<string, mixed> $attributes
   *   The attributes to change.
   *
   * @return array{0: int, 1: array<string, mixed>}
   *   The status code and the decoded response document.
   */
  protected function patch(NodeInterface $page, array $attributes): array {
    return $this->request('PATCH', 'node/page/' . $page->uuid(), [
      'data' => ['type' => 'node--page', 'id' => $page->uuid(), 'attributes' => $attributes],
    ]);
  }

  /**
   * Makes a saved page.
   *
   * @param array<string, mixed> $values
   *   Values for the page.
   */
  protected function savedPage(array $values = []): NodeInterface {
    $page = Node::create($values + ['type' => 'page', 'title' => 'A page', 'uid' => $this->author->id()]);
    $page->save();
    return $page;
  }

  /**
   * Tests that a POST with an error-level finding is refused.
   */
  public function testPostWithError(): void {
    [$status, $document] = $this->request('POST', 'node/page', [
      'data' => [
        'type' => 'node--page',
        'attributes' => [
          'title' => 'A page',
          'field_text' => ['value' => 'This is very good.', 'format' => 'plain_text'],
        ],
      ],
    ]);
    $this->assertSame(422, $status);
    $this->assertCount(1, $document['errors']);
    $this->assertSame('/data/attributes/field_text/value', $document['errors'][0]['source']['pointer']);
    $this->assertSame('field_text.0.value: Text: Demo.Very: Avoid \'very\'. (line 1: "very")', $document['errors'][0]['detail']);
    $this->assertSame([], \Drupal::entityQuery('node')->accessCheck(FALSE)->execute());
  }

  /**
   * Tests that a PATCH with an error-level finding leaves the page alone.
   */
  public function testPatchWithError(): void {
    $page = $this->savedPage(['field_text' => 'Fine.']);
    [$status, $document] = $this->patch($page, ['field_text' => ['value' => 'Very fine.', 'format' => 'plain_text']]);
    $this->assertSame(422, $status);
    $this->assertSame('/data/attributes/field_text/value', $document['errors'][0]['source']['pointer']);
    $this->assertStringContainsString('Demo.Very', $document['errors'][0]['detail']);

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertSame('Fine.', Node::load($page->id())?->get('field_text')->value);
  }

  /**
   * Tests that warnings arrive in the response's meta.
   */
  public function testWarningsInMeta(): void {
    $page = $this->savedPage();
    [$status, $document] = $this->patch($page, ['field_text' => ['value' => 'We delve.', 'format' => 'plain_text']]);
    $this->assertSame(200, $status);
    $this->assertTrue($document['meta']['vale']['checked']);
    $this->assertSame([
      'entity_type' => 'node',
      'bundle' => 'page',
      'id' => $page->uuid(),
      'field' => 'field_text',
      'delta' => 0,
      'rule' => 'Demo.Delve',
      'level' => 'warning',
      'message' => "'delve' is an AI tell.",
      'line' => 1,
      'start' => 4,
      'end' => 8,
      'match' => 'delve',
      'description' => '',
      'link' => '',
    ], $document['meta']['vale']['alerts'][0]);

    // Validation covers every value, so a write that leaves the text alone
    // still reports it.
    [, $document] = $this->patch($page, ['title' => 'Renamed']);
    $this->assertSame('Demo.Delve', $document['meta']['vale']['alerts'][0]['rule']);

    // A write with no opted-in text gets no meta of its own.
    [, $document] = $this->patch($this->savedPage(), ['title' => 'Renamed']);
    $this->assertArrayNotHasKey('vale', $document['meta'] ?? []);
  }

  /**
   * Tests publish-only mode over JSON:API.
   */
  public function testPublishOnly(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $paragraph = Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.']);
    $paragraph->save();
    $page = $this->savedPage(['status' => FALSE, 'field_content' => [$paragraph]]);

    // A draft with an error saves, and the error is in the meta.
    [$status, $document] = $this->patch($page, ['field_text' => ['value' => 'Very fine.', 'format' => 'plain_text']]);
    $this->assertSame(200, $status);
    $this->assertSame(['field_text:error'], array_map(
      static fn (array $alert): string => $alert['field'] . ':' . $alert['level'],
      $document['meta']['vale']['alerts'],
    ));

    // Publishing it is refused, for the page's text and for its paragraph,
    // although the PATCH names neither field. The detail is plain text, one
    // alert a line, as core gives a field violation's.
    [$status, $document] = $this->patch($page, ['status' => TRUE]);
    $this->assertSame(422, $status);
    $this->assertSame(['/data'], array_column(array_column($document['errors'], 'source'), 'pointer'));
    $this->assertSame([
      "Entity is not valid: Text: Demo.Very: Avoid 'Very'. (line 1: \"Very\")\nParagraph \"Docs text\", Text: Demo.Very: Avoid 'very'. (line 1: \"very\")",
    ], array_column($document['errors'], 'detail'));
  }

  /**
   * Tests a write while Vale cannot be run.
   */
  public function testUnavailable(): void {
    $this->setValeSetting('vale_binary', NULL);
    $page = $this->savedPage();
    [$status, $document] = $this->patch($page, ['field_text' => ['value' => 'This is very good.', 'format' => 'plain_text']]);
    $this->assertSame(200, $status);
    $this->assertFalse($document['meta']['vale']['checked']);
    $this->assertStringContainsString('vale_binary', $document['meta']['vale']['unchecked'][0]);
  }

}
