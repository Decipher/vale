<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\vale\Finding;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests walking stored content for findings.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ContentWalkerTest extends KernelTestBase {

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
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Saves a page.
   *
   * @param array<string, mixed> $values
   *   Values for the page.
   */
  protected function savePage(array $values): NodeInterface {
    $page = Node::create($values + ['type' => 'page']);
    $page->save();
    return $page;
  }

  /**
   * Summarises findings as "<host> > <entity>.<field>:<rule>".
   *
   * @param \Drupal\vale\Finding[] $findings
   *   The findings.
   *
   * @return string[]
   *   The summaries.
   */
  protected function summarise(array $findings): array {
    return array_map(static function (Finding $finding): string {
      $host = $finding->host ? $finding->host['entity_type'] . ' ' . $finding->host['label'] . ' > ' : '';
      return $host . $finding->entityType . '.' . $finding->field . ':' . ($finding->alert->rule ?? 'unchecked');
    }, $findings);
  }

  /**
   * Tests linting a bundle, with paragraphs and forward revisions.
   */
  public function testBundle(): void {
    $this->savePage([
      'title' => 'First',
      'field_text' => 'This is very good.',
      'field_code' => 'Very, very code.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'We delve.'])],
    ]);
    $second = $this->savePage(['title' => 'Second', 'field_text' => 'Fine.']);

    // A forward revision is what an editor is working on, so it is linted.
    $second->setNewRevision();
    $second->isDefaultRevision(FALSE);
    $second->set('field_text', 'Really.');
    $second->save();

    $walker = $this->container->get('vale.content_walker');
    $this->assertSame([
      'node.field_text:Demo.Very',
      'node First > paragraph.field_text:Demo.Delve',
      'node.field_text:Demo.Really',
    ], $this->summarise($walker->bundle('node', 'page')));

    $findings = $walker->bundle('node', 'page');
    $this->assertSame('First', $findings[0]->label);
    $this->assertNull($findings[0]->host);
    $this->assertSame('docs_text', $findings[1]->bundle);
    $this->assertSame('field_text', $findings[1]->field);
    $this->assertSame('delve', $findings[1]->alert?->match);

    // Paragraphs can be linted by themselves, and name their host.
    $this->assertSame(['node First > paragraph.field_text:Demo.Delve'], $this->summarise($walker->bundle('paragraph', 'docs_text')));

    // All content reaches paragraphs only through their hosts.
    $this->assertSame($this->summarise($walker->bundle('node', 'page')), $this->summarise($walker->all()));

    $this->expectException(\InvalidArgumentException::class);
    $walker->bundle('node', 'nope');
  }

  /**
   * Tests that a value Vale could not check is a finding of its own.
   */
  public function testUnchecked(): void {
    $this->savePage(['title' => 'First', 'field_text' => 'FAKE_CRASH']);
    $findings = $this->container->get('vale.content_walker')->bundle('node', 'page');
    $this->assertCount(1, $findings);
    $this->assertNull($findings[0]->alert);
    $this->assertStringContainsString('Fake failure.', $findings[0]->reason);
  }

  /**
   * Tests linting what a workspace tracks.
   */
  public function testWorkspace(): void {
    $this->initializeWorkspacesModule();

    // Live content with a finding, which the workspace leaves alone.
    $this->savePage(['title' => 'Live', 'field_text' => 'This is very good.']);
    $changed = $this->savePage(['title' => 'Changed', 'field_text' => 'Fine.']);

    $this->switchToWorkspace('stage');
    $changed = Node::load($changed->id());
    $this->assertInstanceOf(NodeInterface::class, $changed);
    $changed->set('field_text', 'We delve.');
    $changed->save();
    $this->savePage([
      'title' => 'New',
      'field_text' => 'Fine.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'Really very.'])],
    ]);
    $this->switchToLive();

    $walker = $this->container->get('vale.content_walker');
    $this->assertSame([
      'node.field_text:Demo.Delve',
      'node New > paragraph.field_text:Demo.Really',
      'node New > paragraph.field_text:Demo.Very',
    ], $this->summarise($walker->workspace('stage')));

    // Live sees only Live's revisions: the page the workspace made is not
    // there, and the changed page is linted as Live has it.
    $this->assertSame(['node.field_text:Demo.Very'], $this->summarise($walker->bundle('node', 'page')));

    $this->expectException(\InvalidArgumentException::class);
    $walker->workspace('nope');
  }

}
