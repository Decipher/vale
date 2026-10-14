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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
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

}
