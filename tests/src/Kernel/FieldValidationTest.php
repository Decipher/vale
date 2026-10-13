<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\content_moderation\Traits\ContentModerationTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Vale constraints during entity validation.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FieldValidationTest extends KernelTestBase {

  use ContentModerationTestTrait;
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
    $this->installSchema('node', ['node_access']);
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
   * Gives the validation messages of an entity, keyed by property path.
   *
   * @return array<string, string[]>
   *   The messages.
   */
  protected function violations(ContentEntityInterface $entity): array {
    $messages = [];
    foreach ($entity->validate() as $violation) {
      $messages[$violation->getPropertyPath()][] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Gives the findings that did not stop the save, as "<field>:<rule>".
   *
   * @return string[]
   *   The findings.
   */
  protected function collected(): array {
    return array_map(
      static fn (array $finding): string => $finding['entity_type'] . '.' . $finding['field'] . ':' . $finding['alert']->rule,
      $this->container->get('vale.alert_collector')->alerts(),
    );
  }

  /**
   * Makes a page.
   *
   * @param array<string, mixed> $values
   *   Values for the page.
   */
  protected function page(array $values = []): NodeInterface {
    return Node::create($values + ['type' => 'page', 'title' => 'A page']);
  }

  /**
   * Tests that an error-level finding is a violation on its value.
   */
  public function testErrorBlocks(): void {
    $page = $this->page(['field_text' => ['value' => 'This is very good.', 'format' => 'plain_text']]);
    $this->assertSame(
      ['field_text.0.value' => ['Demo.Very: Avoid &#039;very&#039;. (line 1: "very")']],
      $this->violations($page),
    );
  }

  /**
   * Tests that every error in a value is in its one violation.
   *
   * Form API shows one error per element, so the findings share a message.
   */
  public function testSeveralErrors(): void {
    $page = $this->page(['field_text' => "This is very good.\nVery much so."]);
    $this->assertSame(
      ['field_text.0.value' => ["Demo.Very: Avoid &#039;very&#039;. (line 1: \"very\")<br>\nDemo.Very: Avoid &#039;Very&#039;. (line 2: \"Very\")"]],
      $this->violations($page),
    );
  }

  /**
   * Tests that warnings and suggestions never stop a save.
   */
  public function testWarningsPass(): void {
    $page = $this->page(['field_text' => 'We delve, really.']);
    $this->assertSame([], $this->violations($page));
    $this->assertSame(['node.field_text:Demo.Delve', 'node.field_text:Demo.Really'], $this->collected());

    $alerts = $this->container->get('vale.alert_collector')->alerts();
    $this->assertSame('Text', $alerts[0]['field_label']);
    $this->assertSame(Alert::WARNING, $alerts[0]['alert']->level);
  }

  /**
   * Tests that a field that has not opted in is never linted.
   */
  public function testFieldNotOptedIn(): void {
    $page = $this->page(['field_code' => 'This is very good.']);
    $this->assertSame([], $this->violations($page));
    $this->assertSame([], $this->valeRuns());
  }

  /**
   * Tests that every value of a field is linted under the field's name.
   */
  public function testEveryValue(): void {
    $this->container->get('config.factory')->getEditable('vale.settings')->set('formats', ['plain_text' => 'md'])->save();
    $page = $this->page(['field_text' => ['value' => 'Fine.', 'format' => 'plain_text']]);
    $this->violations($page);
    $this->assertSame(['node.page.field_text.md'], $this->valeRuns());
  }

  /**
   * Tests that Vale being unavailable never stops a save.
   */
  public function testUnavailable(): void {
    $this->setSetting('vale_binary', NULL);
    $page = $this->page(['field_text' => 'This is very good.']);
    $this->assertSame([], $this->violations($page));
    $unchecked = $this->container->get('vale.alert_collector')->unchecked();
    $this->assertCount(1, $unchecked);
    $this->assertStringContainsString('vale_binary', $unchecked[0]);
  }

  /**
   * Tests a paragraph validated by itself, as in a Layout Paragraphs dialog.
   */
  public function testParagraph(): void {
    $paragraph = Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.']);
    $this->assertSame(['field_text.0.value'], array_keys($this->violations($paragraph)));
  }

  /**
   * Tests publish-only mode on an entity without moderation.
   */
  public function testPublishOnly(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();

    $draft = $this->page(['field_text' => 'This is very good.', 'status' => FALSE]);
    $this->assertSame([], $this->violations($draft));
    $this->assertSame(['node.field_text:Demo.Very'], $this->collected());

    // Publishing is refused on the entity, not the field. See
    // ValeTreeConstraintValidator.
    $this->container->get('vale.alert_collector')->reset();
    $published = $this->page(['field_text' => 'This is very good.', 'status' => TRUE]);
    $this->assertSame(
      ['' => ['Text: Demo.Very: Avoid &#039;very&#039;. (line 1: "very")']],
      $this->violations($published),
    );
    $this->assertSame([], $this->collected());
  }

  /**
   * Tests publish-only mode with the editorial workflow.
   */
  public function testPublishOnlyModeration(): void {
    $this->enableModules(['content_moderation', 'workflows']);
    $this->installEntitySchema('content_moderation_state');
    $this->installConfig(['content_moderation']);
    $workflow = $this->createEditorialWorkflow();
    $this->addEntityTypeAndBundleToWorkflow($workflow, 'node', 'page');
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $this->setUpCurrentUser([], [], TRUE);

    $page = $this->page(['field_text' => 'Fine.', 'moderation_state' => 'draft']);
    $page->save();

    // Draft to published: refused.
    $page->set('field_text', 'This is very good.');
    $page->set('moderation_state', 'published');
    $this->assertSame([''], array_keys($this->violations($page)));

    // A draft, including a new draft of published content: saved.
    $page->set('moderation_state', 'draft');
    $this->assertSame([], $this->violations($page));
    $page->set('field_text', 'Fine.');
    $page->set('moderation_state', 'published');
    $page->save();
    $page->set('field_text', 'This is very good.');
    $page->set('moderation_state', 'draft');
    $this->assertSame([], $this->violations($page));

    // Archived is not a published state.
    $page->set('moderation_state', 'archived');
    $this->assertSame([], $this->violations($page));

    // A new entity with no state takes the workflow's initial state, draft.
    $this->assertSame([], $this->violations($this->page(['field_text' => 'This is very good.'])));
  }

  /**
   * Tests that publishing a host checks its paragraphs in publish-only mode.
   */
  public function testParagraphTree(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $paragraph = Paragraph::create(['type' => 'docs_text', 'field_text' => "Fine.\nWe delve into very deep water."]);

    // A paragraph saved by itself is a draft-time save, so it saves.
    $this->assertSame([], $this->violations($paragraph));
    $this->assertSame(['paragraph.field_text:Demo.Delve', 'paragraph.field_text:Demo.Very'], $this->collected());
    $this->container->get('vale.alert_collector')->reset();

    // A draft host saves too.
    $draft = $this->page(['status' => FALSE, 'field_content' => [$paragraph]]);
    $this->assertSame([], $this->violations($draft));

    // Publishing the host is refused, naming the paragraph.
    $published = $this->page(['status' => TRUE, 'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'Fine.']), $paragraph]]);
    $this->assertSame(
      ['' => ['Paragraph "Docs text", Text: Demo.Very: Avoid &#039;very&#039;. (line 2: "very")']],
      $this->violations($published),
    );
    $this->assertContains('paragraph.field_text:Demo.Delve', $this->collected());
  }

  /**
   * Tests that the paragraph tree is not checked outside publish-only mode.
   */
  public function testNoTreeWithoutPublishOnly(): void {
    $paragraph = Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.']);
    $published = $this->page(['status' => TRUE, 'field_content' => [$paragraph]]);
    $this->assertSame([], $this->violations($published));
    $this->assertSame([], $this->valeRuns());
  }

}
