<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Drush\Commands\ValeCommands;
use Drupal\vale\Plugin\Action\CheckProseAction;
use Drupal\vale\ScanBatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the content report's stored findings.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ContentReportTest extends KernelTestBase {

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
   * Summarises the stored rows as "<root label> > <entity>.<field>:<rule>".
   *
   * @return string[]
   *   The summaries.
   */
  protected function report(array $filters = []): array {
    return array_map(
      static fn (array $row): string => $row['root_label'] . ' > ' . $row['entity_type'] . '.' . $row['field'] . ':' . $row['rule'] . ':' . $row['level'],
      $this->container->get('vale.finding_storage')->rows($filters),
    );
  }

  /**
   * Saves a page, which the update hook reports.
   */
  protected function savePage(string $title, string $text, string $paragraph = ''): NodeInterface {
    $page = Node::create([
      'type' => 'page',
      'title' => $title,
      'field_text' => $text,
      'field_content' => $paragraph === '' ? [] : [Paragraph::create(['type' => 'docs_text', 'field_text' => $paragraph])],
    ]);
    $page->save();
    return $page;
  }

  /**
   * Tests that saving and deleting content keeps the report current.
   */
  public function testSaveAndDelete(): void {
    $page = $this->savePage('Guide', 'We delve.', 'This is very good.');
    $this->assertSame([
      'Guide > node.field_text:Demo.Delve:warning',
      'Guide > paragraph.field_text:Demo.Very:error',
    ], $this->report());

    $page->set('field_text', 'Fine.');
    $page->save();
    $this->assertSame(['Guide > paragraph.field_text:Demo.Very:error'], $this->report());

    // A forward draft is not what Live shows, so it does not change the row.
    $page->setNewRevision();
    $page->isDefaultRevision(FALSE);
    $page->set('field_text', 'Really.');
    $page->save();
    $this->assertSame(['Guide > paragraph.field_text:Demo.Very:error'], $this->report());

    $page->delete();
    $this->assertSame([], $this->report());
  }

  /**
   * Tests that drush vale:lint --save replaces the scope's rows.
   */
  public function testDrushSave(): void {
    $this->savePage('First', 'We delve.');
    $this->savePage('Second', 'Fine.');
    // Rows from before a scan of the bundle are replaced, even stale ones.
    $this->container->get('database')->update('vale_finding')->fields(['rule' => 'Stale.Rule'])->execute();

    $commands = ValeCommands::create($this->container);
    $this->assertSame(0, $commands->lint('node:page', ['workspace' => NULL, 'save' => TRUE])->getExitCode());
    $this->assertSame(['First > node.field_text:Demo.Delve:warning'], $this->report());

    $this->container->get('database')->update('vale_finding')->fields(['rule' => 'Stale.Rule'])->execute();
    $commands->lint(NULL, ['workspace' => NULL, 'save' => TRUE]);
    $this->assertSame(['First > node.field_text:Demo.Delve:warning'], $this->report());

    // The report is Live, so a workspace cannot be saved to it.
    $this->assertSame(1, $commands->lint(NULL, ['workspace' => 'stage', 'save' => TRUE])->getExitCode());
  }

  /**
   * Tests the batch scan, chunk by chunk.
   */
  public function testBatchScan(): void {
    for ($i = 1; $i <= 30; $i++) {
      $this->savePage("Page $i", $i % 10 === 0 ? 'This is very good.' : 'Fine.');
    }
    $this->container->get('vale.finding_storage')->clearAll();

    $context = ['sandbox' => [], 'results' => []];
    $rounds = 0;
    do {
      ScanBatch::scan('node', 'page', $context);
      $rounds++;
    } while ($context['finished'] < 1 && $rounds < 10);

    $this->assertSame(2, $rounds);
    $this->assertSame(30, $context['results']['entities']);
    $this->assertSame(3, $context['results']['findings']);
    $this->assertCount(3, $this->report(['level' => 'error']));
    $this->assertSame([], $this->report(['level' => 'warning']));
  }

  /**
   * Tests the bulk action.
   */
  public function testAction(): void {
    $page = $this->savePage('Guide', 'Fine.');
    // Change the text without a save hook, as an import would.
    $this->container->get('database')->update('node__field_text')->fields(['field_text_value' => 'This is very good.'])->execute();
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertSame([], $this->report());

    $this->setUpCurrentUser([], ['access content', 'scan content with vale']);
    $action = $this->container->get('plugin.manager.action')->createInstance('vale_check_prose');
    $this->assertInstanceOf(CheckProseAction::class, $action);
    $page = Node::load($page->id());
    $this->assertTrue($action->access($page));
    $action->executeMultiple([$page]);
    $this->assertSame(['Guide > node.field_text:Demo.Very:error'], $this->report());

    $this->setUpCurrentUser([], ['access content']);
    $this->assertFalse($action->access($page));
  }

}
