<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\Drush\Commands\ValeCommands;
use Drupal\vale\Finding;
use Drupal\vale\FindingStorage;
use Drupal\vale\Plugin\Action\CheckProseAction;
use Drupal\vale\ReportRefresher;
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
   * Summarises the stored rows as "<root label> > <entity>.<field>:<rule>".
   *
   * @return string[]
   *   The summaries.
   */
  protected function report(array $filters = []): array {
    // Saves refresh the report at the end of the request.
    $this->container->get('vale.report_refresher')->destruct();
    return array_map(
      static fn (array $row): string => $row['root_label'] . ' > ' . $row['entity_type'] . '.' . $row['field'] . ':' . $row['rule'] . ':' . $row['level'],
      iterator_to_array($this->container->get('vale.finding_storage')->rows($filters), FALSE),
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

    // A forward draft is what an editor is about to publish, so the report
    // shows it.
    $page->setNewRevision();
    $page->isDefaultRevision(FALSE);
    $page->set('field_text', 'Really.');
    $page->save();
    $this->assertSame([
      'Guide > node.field_text:Demo.Really:suggestion',
      'Guide > paragraph.field_text:Demo.Very:error',
    ], $this->report());

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

    // A paragraph's findings belong to its page, so its bundle is not saved.
    $this->assertSame(1, $commands->lint('paragraph:docs_text', ['workspace' => NULL, 'save' => TRUE])->getExitCode());

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
    // The save's own refresh runs first.
    $this->assertSame([], $this->report());
    // Change the text without a save hook, as an import would.
    foreach (['node__field_text', 'node_revision__field_text'] as $table) {
      $this->container->get('database')->update($table)->fields(['field_text_value' => 'This is very good.'])->execute();
    }
    $this->container->get('entity_type.manager')->getStorage('node')->resetCache();
    $this->assertSame([], $this->report());

    $this->setUpCurrentUser([], ['access content', 'scan content with vale']);
    $action = $this->container->get('plugin.manager.action')->createInstance('vale_check_prose');
    $this->assertInstanceOf(CheckProseAction::class, $action);
    $page = Node::load($page->id());
    $this->assertTrue($action->access($page));
    $action->executeMultiple([$page]);
    $this->assertSame(['Guide > node.field_text:Demo.Very:error'], $this->report());
    // Something that holds no fields is skipped.
    $action->execute();
    $this->assertSame(['Guide > node.field_text:Demo.Very:error'], $this->report());

    $this->setUpCurrentUser([], ['access content']);
    $this->assertFalse($action->access($page));
  }

  /**
   * Tests storing more findings than one insert can hold.
   */
  public function testManyFindings(): void {
    $alert = new Alert('Demo.Very', Alert::ERROR, 'Avoid it.', 1, 1, 4, 'very');
    $findings = [];
    for ($i = 1; $i <= 1200; $i++) {
      $findings[] = new Finding('node', (string) $i, "Page $i", 'page', 'field_text', 0, $alert);
    }
    // Record each insert, so the test fails if the batches go. The row count
    // alone fits under the database's limit on bound values.
    $storage = new class($this->container->get('database'), $this->container->get('datetime.time')) extends FindingStorage {

      /**
       * The size of each insert.
       *
       * @var list<int>
       */
      public array $sizes = [];

      /**
       * {@inheritdoc}
       */
      protected function insert(array $findings, int $now): void {
        $this->sizes[] = count($findings);
        parent::insert($findings, $now);
      }

    };
    $storage->add($findings);
    $this->assertSame([500, 500, 200], $storage->sizes);
    $count = $this->container->get('database')->select('vale_finding')->countQuery()->execute()->fetchField();
    $this->assertSame(1200, (int) $count);
  }

  /**
   * Tests that a failed replace keeps the rows it was replacing.
   */
  public function testReplaceRollsBack(): void {
    $alert = new Alert('Demo.Very', Alert::ERROR, 'Avoid it.', 1, 1, 4, 'very');
    $finding = new Finding('node', '1', 'Page 1', 'page', 'field_text', 0, $alert);
    $this->container->get('vale.finding_storage')->add([$finding]);
    // The new rows fail to insert after the old rows are deleted.
    $storage = new class($this->container->get('database'), $this->container->get('datetime.time')) extends FindingStorage {

      /**
       * {@inheritdoc}
       */
      protected function insert(array $findings, int $now): void {
        throw new \RuntimeException('Insert failed.');
      }

    };
    try {
      $storage->replace('node', ['1'], [$finding]);
      $this->fail('The failed insert was not thrown.');
    }
    catch (\RuntimeException $e) {
      $this->assertSame('Insert failed.', $e->getMessage());
    }
    $count = $this->container->get('database')->select('vale_finding')->countQuery()->execute()->fetchField();
    $this->assertSame(1, (int) $count);
  }

  /**
   * Tests that the queue worker drops an item it cannot read.
   */
  public function testMalformedQueueItem(): void {
    $worker = $this->container->get('plugin.manager.queue_worker')->createInstance(ReportRefresher::QUEUE);
    $worker->processItem('not an item');
    $worker->processItem(['node']);
    $this->assertSame([], $this->report());
  }

  /**
   * Tests that a save Vale cannot check waits in the queue.
   */
  public function testRefreshQueue(): void {
    $page = $this->savePage('Guide', 'This is very good.');
    $this->assertSame(['Guide > node.field_text:Demo.Very:error'], $this->report());

    // Vale is down: the checked row stays, and the refresh is queued.
    $this->setValeSetting('vale_binary', NULL);
    $this->container->get('cache.vale')->deleteAll();
    $page->set('field_text', 'We delve.')->save();
    $this->assertSame(['Guide > node.field_text:Demo.Very:error'], $this->report());
    $queue = $this->container->get('queue')->get(ReportRefresher::QUEUE);
    $this->assertSame(1, $queue->numberOfItems());

    // Cron leaves it queued until Vale is back.
    $this->container->get('cron')->run();
    $this->assertSame(1, $queue->numberOfItems());

    $this->setUpVale();
    $this->container->get('cron')->run();
    $this->assertSame(0, $queue->numberOfItems());
    $this->assertSame(['Guide > node.field_text:Demo.Delve:warning'], $this->report());

    // A syncing save, such as a migration, is left to cron.
    $page->set('field_text', 'Fine.')->setSyncing(TRUE)->save();
    $this->assertSame(['Guide > node.field_text:Demo.Delve:warning'], $this->report());
    $this->assertSame(1, $queue->numberOfItems());
  }

  /**
   * Tests the report's rule filter, which matches part of a name.
   */
  public function testRuleFilter(): void {
    $this->savePage('Guide', 'We delve.', 'This is very good.');
    $this->assertSame(['Guide > paragraph.field_text:Demo.Very:error'], $this->report(['rule' => 'Ver']));
    $this->assertSame([], $this->report(['rule' => '%']));
  }

  /**
   * Tests that the report keeps its rows when Vale is unavailable.
   */
  public function testWritersRefuseWithoutVale(): void {
    $page = $this->savePage('Guide', 'This is very good.');
    $before = $this->report();
    $this->setValeSetting('vale_binary', NULL);
    $this->container->get('cache.vale')->deleteAll();

    // The batch stops before it writes, and says why.
    $context = ['sandbox' => [], 'results' => []];
    ScanBatch::scan('node', 'page', $context);
    $this->assertSame(1, $context['finished']);
    $this->assertStringContainsString('vale_binary', (string) $context['results']['error']);
    ScanBatch::finished(TRUE, $context['results']);
    $errors = array_map(strval(...), $this->container->get('messenger')->messagesByType('error'));
    $this->assertStringStartsWith('The scan stopped:', $errors[0]);
    // The reason is escaped once, so its quotes show as quotes.
    $this->assertStringContainsString("\$settings[&#039;vale_binary&#039;]", $errors[0]);

    // Later bundles stay stopped, even once Vale is back.
    $this->setUpVale();
    $context['sandbox'] = [];
    ScanBatch::scan('node', 'page', $context);
    $this->assertSame(1, $context['finished']);
    $this->assertSame($before, $this->report());

    // So does the bulk action.
    $this->setUpCurrentUser([], ['access content', 'scan content with vale']);
    $action = $this->container->get('plugin.manager.action')->createInstance('vale_check_prose');
    $this->assertInstanceOf(CheckProseAction::class, $action);
    $action->executeMultiple([$page]);
    $this->assertSame($before, $this->report());
  }

  /**
   * Tests that a long message is cut to fit its column.
   */
  public function testLongMessage(): void {
    $this->container->get('vale.finding_storage')->add([
      new Finding('node', '1', 'Guide', 'page', 'field_text', 0, NULL, str_repeat('é', 20000)),
    ]);
    $rows = iterator_to_array($this->container->get('vale.finding_storage')->rows(), FALSE);
    $this->assertSame(16000, mb_strlen($rows[0]['message']));
  }

  /**
   * Tests that a bundle loses its rows when nothing in it is checked.
   */
  public function testFieldTurnedOff(): void {
    $this->savePage('Guide', 'We delve.', 'This is very good.');
    $this->assertCount(2, $this->report());

    // The field's own rows go. The page still holds prose through its
    // paragraphs, so their rows stay.
    FieldConfig::loadByName('node', 'page', 'field_text')?->setThirdPartySetting('vale', 'enabled', FALSE)->save();
    $this->assertSame(['Guide > paragraph.field_text:Demo.Very:error'], $this->report());

    FieldConfig::loadByName('paragraph', 'docs_text', 'field_text')?->setThirdPartySetting('vale', 'enabled', FALSE)->save();
    $this->assertSame([], $this->report());
  }

  /**
   * Tests that a bundle loses its rows when it can no longer hold prose.
   */
  public function testHolderFieldDeleted(): void {
    FieldConfig::loadByName('node', 'page', 'field_text')?->setThirdPartySetting('vale', 'enabled', FALSE)->save();
    $this->savePage('Guide', 'We delve.', 'This is very good.');
    $this->assertSame(['Guide > paragraph.field_text:Demo.Very:error'], $this->report());

    // The page's only checked text was in its paragraphs.
    FieldConfig::loadByName('node', 'page', 'field_content')?->delete();
    $this->assertSame([], $this->report());
  }

  /**
   * Tests that deleting a forward draft refreshes the report.
   */
  public function testDraftDeleted(): void {
    $page = $this->savePage('Guide', 'Fine.');
    $page->setNewRevision();
    $page->isDefaultRevision(FALSE);
    $page->set('field_text', 'We delve.');
    $page->save();
    $this->assertSame(['Guide > node.field_text:Demo.Delve:warning'], $this->report());

    $this->container->get('entity_type.manager')->getStorage('node')->deleteRevision((int) $page->getRevisionId());
    $this->assertSame([], $this->report());
  }

}
