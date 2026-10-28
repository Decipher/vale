<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\ParagraphInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\workspaces\WorkspaceInformation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests validating content that is being edited in a workspace.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class WorkspaceValidationTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeContentTrait;
  use ValeTestTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, which WorkspaceTestTrait sets.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
    'vale_test',
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
    $this->initializeWorkspacesModule();
  }

  /**
   * Tests a paragraph saved with findings while its host is in a workspace.
   *
   * Some forms validate the edited paragraph by itself, without its host.
   * Reporting the findings must not disturb the
   * workspace's own checks on that paragraph.
   */
  public function testParagraphInWorkspace(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $page = Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'Fine.'])],
    ]);
    $page->save();

    $this->switchToWorkspace('stage');
    $page = Node::load($page->id());
    $this->assertInstanceOf(NodeInterface::class, $page);
    $page->set('title', 'Guide, edited in stage');
    $page->save();

    $paragraph = $page->get('field_content')->entity;
    $this->assertInstanceOf(ParagraphInterface::class, $paragraph);
    $paragraph->set('field_text', 'This is very good.');
    $paragraph->setNewRevision(TRUE);

    $messages = [];
    foreach ($paragraph->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    $this->assertSame([], $messages);

    $collected = $this->container->get('vale.alert_collector')->alerts();
    $this->assertCount(1, $collected);
    $this->assertSame('Docs text', $collected[0]['label']);
  }

  /**
   * Tests that editing published content in a workspace is a draft save.
   *
   * Under publish-only mode, an error stops only the save that publishes.
   * A save inside a workspace publishes nothing live, so the same edit
   * validates there and its finding waits for the workspace publish check.
   */
  public function testPublishedEditIsDraftInWorkspace(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $page = Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'Fine.',
      'status' => TRUE,
    ]);
    $page->save();
    $this->assertTrue($page->isPublished());

    // In Live, saving an error into published content is the publishing
    // save, and is refused on the entity. See ValeTreeConstraintValidator.
    $page->set('field_text', 'This is very good.');
    $messages = [];
    foreach ($page->validate() as $violation) {
      $messages[$violation->getPropertyPath()][] = (string) $violation->getMessage();
    }
    $this->assertSame([''], array_keys($messages));
    $this->assertStringContainsString('Text: Demo.Very', $messages[''][0]);

    // In a workspace, the same edit is a draft: it validates, and the
    // finding is collected for the publish check instead.
    $this->container->get('vale.alert_collector')->reset();
    $this->switchToWorkspace('stage');
    $page = Node::load($page->id());
    $this->assertInstanceOf(NodeInterface::class, $page);
    $page->set('field_text', 'This is very good.');
    $messages = [];
    foreach ($page->validate() as $violation) {
      $messages[$violation->getPropertyPath()][] = (string) $violation->getMessage();
    }
    $this->assertSame([], $messages);
    $collected = $this->container->get('vale.alert_collector')->alerts();
    $this->assertCount(1, $collected);
    $this->assertSame('field_text', $collected[0]['field']);
    $this->assertSame('Demo.Very', $collected[0]['alert']->rule);
  }

  /**
   * Tests that content a workspace does not track keeps its refusal.
   *
   * A type an ignored workspace handler keeps out saves straight to Live,
   * so publishing it is refused as it is outside a workspace, rather than
   * drafting into a workspace that would never publish it.
   */
  public function testUntrackedTypeStillRefusedInWorkspace(): void {
    if (!class_exists(WorkspaceInformation::class)) {
      $this->markTestSkipped('Workspace information arrives in Drupal 11.3.');
    }
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    \Drupal::state()->set('vale_test_nodes_not_tracked', TRUE);
    $this->container->get('kernel')->rebuildContainer();
    $this->entityTypeManager = \Drupal::entityTypeManager();
    $this->entityTypeManager->clearCachedDefinitions();

    $this->switchToWorkspace('stage');
    $page = Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'This is very good.',
      'status' => TRUE,
    ]);
    $messages = [];
    foreach ($page->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    $this->assertCount(1, $messages);
    $this->assertStringContainsString('Text: Demo.Very', $messages[0]);
  }

}
