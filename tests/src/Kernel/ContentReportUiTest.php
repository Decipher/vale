<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Database\Database;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\Controller\ReportExportController;
use Drupal\vale\Finding;
use Drupal\vale\FindingStorage;
use Drupal\vale\Form\ScanForm;
use Drupal\vale\ScanBatch;
use Drupal\views\Views;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the content report's view, export and scan form.
 *
 * The functional ContentReportTest drives these over HTTP. This test runs
 * them in process.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ContentReportUiTest extends KernelTestBase {

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
    'views',
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
    $this->installConfig(['filter', 'node', 'system', 'views', 'vale']);
    $this->container->get('config.installer')->installOptionalConfig();
    $this->setUpVale();
    $this->createValeContentModel();
    $this->container->get('router.builder')->rebuild();
    $this->setUpCurrentUser([], ['access content', 'view vale report', 'scan content with vale', 'bypass node access']);

    $page = Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'We delve.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ]);
    $page->save();
    // Fill the report directly, so the test does not depend on when saves
    // refresh it.
    $this->container->get('vale.finding_storage')->replace('node', [$page->id()], $this->container->get('vale.content_walker')->entity($page));
  }

  /**
   * Tests the default view, with its link to each page and to the export.
   */
  public function testView(): void {
    // A root whose entity type has no edit form is shown as plain text.
    $this->container->get('vale.finding_storage')->add([
      new Finding('gone', '5', 'Orphan', 'page', 'field_text', 0, new Alert('Demo.Very', Alert::ERROR, 'Avoid it.', 1, 1, 4, 'very')),
    ]);
    $view = Views::getView('vale_findings');
    $this->assertNotNull($view);
    $view->setExposedInput(['level' => 'error']);
    $build = $view->preview('page_1');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertStringContainsString('Demo.Very', $html);
    $this->assertStringNotContainsString('Demo.Delve', $html);
    $this->assertStringContainsString('/node/1/edit', $html);
    $this->assertStringContainsString('/admin/reports/vale/export?level=error', str_replace('&amp;', '&', $html));
    $this->assertStringContainsString('Orphan', $html);
    $this->assertStringNotContainsString('/gone/', $html);

    // An empty report has nothing to export.
    $view = Views::getView('vale_findings');
    $this->assertNotNull($view);
    $view->setExposedInput(['rule' => 'Demo.Missing']);
    $build = $view->preview('page_1');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $this->assertStringNotContainsString('/admin/reports/vale/export', $html);

    // With no rows, the export link is not shown.
    $view = Views::getView('vale_findings');
    $view->setExposedInput(['level' => 'suggestion']);
    $build = $view->preview('page_1');
    $this->assertStringNotContainsString('/admin/reports/vale/export', (string) $this->container->get('renderer')->renderInIsolation($build));

    $this->assertSame(['page' => 'page'], FindingStorage::bundleOptions());
    $this->assertSame(['error', 'warning', 'suggestion', 'unchecked'], array_keys(FindingStorage::levelOptions()));
  }

  /**
   * Tests that the table fits an admin theme.
   *
   * Where an alert was found is shown only for a component. The table has
   * no caption, which Gin's sticky header does not allow for.
   */
  public function testTableFits(): void {
    $view = Views::getView('vale_findings');
    $this->assertNotNull($view);
    $build = $view->preview('page_1');
    $html = (string) $this->container->get('renderer')->renderInIsolation($build);
    $table = (new Crawler($html))->filter('table');

    $rows = $table->filter('tbody tr')->each(static fn (Crawler $row): string => (string) preg_replace('/\s+/', ' ', trim($row->text())));
    $this->assertCount(2, $rows);
    // The page's own field names the page once.
    $top = array_values(array_filter($rows, static fn (string $row): bool => str_contains($row, 'Demo.Delve')));
    $this->assertCount(1, $top);
    $this->assertSame(1, substr_count($top[0], 'Guide'));
    // The page's own row has no second line, so no line break either.
    $cells = $table->filter('tbody tr')->each(static fn (Crawler $row): string => $row->html());
    $top_cell = array_values(array_filter($cells, static fn (string $row): bool => str_contains($row, 'Demo.Delve')));
    $this->assertStringNotContainsString('<br', $top_cell[0]);
    // A paragraph's alert also says where in the page it was found, without
    // naming the page again.
    $component = array_values(array_filter($rows, static fn (string $row): bool => str_contains($row, 'Demo.Very')));
    $this->assertCount(1, $component);
    $this->assertSame(1, substr_count($component[0], 'Guide'));
    $this->assertStringContainsString('> Content', $component[0]);
    // Its second line is on a line of its own.
    $component_cell = array_values(array_filter($cells, static fn (string $row): bool => str_contains($row, 'Demo.Very')));
    $this->assertStringContainsString('<br', $component_cell[0]);

    $this->assertCount(0, $table->filter('caption'));
    $headers = $table->filter('thead th')->each(static fn (Crawler $th): string => trim($th->text()));
    $this->assertNotContains('Bundle', $headers);
    $this->assertNotContains('Found in', $headers);
  }

  /**
   * Tests the CSV export, with its filters and its formula guard.
   */
  public function testExport(): void {
    $this->container->get('vale.finding_storage')->add([
      new Finding('node', '2', 'Sums', 'page', 'field_text', 0, new Alert('Demo.Very', Alert::ERROR, 'Avoid it.', 1, 1, 8, '=SUM(A1)')),
    ]);
    $controller = ReportExportController::create($this->container);
    $response = $controller->export(Request::create('/admin/reports/vale/export', 'GET', ['level' => 'error', 'rule' => 'Demo.Very']));
    ob_start();
    $response->sendContent();
    $lines = array_filter(explode("\n", (string) ob_get_clean()));

    $this->assertSame('text/csv; charset=utf-8', $response->headers->get('Content-Type'));
    $this->assertStringStartsWith('"Root type","Root ID"', $lines[0]);
    $this->assertCount(3, $lines);
    $this->assertStringContainsString("'=SUM(A1)", $lines[2]);
  }

  /**
   * Tests that a large export is read a page at a time.
   *
   * MySQL buffers a whole result set, so one query for every row can run a
   * large site out of memory.
   */
  public function testExportPages(): void {
    $database = $this->container->get('database');
    $database->truncate(FindingStorage::TABLE)->execute();

    // Many rows share a root, so a page can end inside one. Ids are strings,
    // so "10" sorts before "9".
    $findings = [];
    for ($i = 0; $i < 2500; $i++) {
      $type = $i % 2 ? 'node' : 'taxonomy_term';
      $findings[] = new Finding(
        $type,
        (string) ($i % 37),
        'Item ' . $i,
        $type === 'node' ? 'page' : 'tags',
        'field_text',
        $i % 3,
        new Alert($i % 5 ? 'Demo.Very' : 'Demo.Delve', $i % 3 ? Alert::ERROR : Alert::WARNING, 'Avoid "it", ' . $i . '.', 1, $i, $i + 4, '=' . $i),
      );
    }
    $this->container->get('vale.finding_storage')->add($findings);

    $filter_sets = [
      [],
      ['level' => 'error'],
      // Exactly one full page, then an empty one.
      ['root_bundle' => 'page', 'rule' => 'Very'],
    ];
    foreach ($filter_sets as $filters) {
      // The rows the export should hold, in the order it should hold them.
      $query = $database->select(FindingStorage::TABLE, 'f')->fields('f', ['fid']);
      if (isset($filters['level'])) {
        $query->condition('f.level', $filters['level']);
      }
      if (isset($filters['root_bundle'])) {
        $query->condition('f.root_bundle', $filters['root_bundle']);
      }
      if (isset($filters['rule'])) {
        $query->condition('f.rule', '%' . $filters['rule'] . '%', 'LIKE');
      }
      $expected = $query->orderBy('f.root_type')->orderBy('f.root_id')->orderBy('f.fid')->execute()->fetchCol();

      Database::startLog('vale_export');
      $response = ReportExportController::create($this->container)->export(Request::create('/admin/reports/vale/export', 'GET', $filters));
      ob_start();
      $response->sendContent();
      $csv = (string) ob_get_clean();
      $log = Database::getLog('vale_export');
      $reads = array_values(array_filter(
        array_column($log, 'query'),
        static fn (string $sql): bool => str_starts_with(ltrim($sql), 'SELECT') && str_contains($sql, FindingStorage::TABLE),
      ));

      // Each row is the stored row, once and in order. The fid is not
      // exported, so the row is matched on its start, which is unique.
      $lines = array_map(static fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", $csv)));
      $this->assertSame('Root type', array_shift($lines)[0]);
      $starts = $database->select(FindingStorage::TABLE, 'f')->fields('f', ['fid', 'span_start'])->execute()->fetchAllKeyed();
      $this->assertSame(
        array_map(static fn ($fid): string => (string) $starts[$fid], $expected),
        array_column($lines, 11),
        'The export holds every row for ' . json_encode($filters) . ', in order.',
      );
      $this->assertGreaterThanOrEqual(1000, count($expected));

      // It reads in pages, and only the columns it exports.
      $this->assertGreaterThan(1, count($reads), 'The export reads more than one page.');
      foreach ($reads as $sql) {
        $this->assertStringNotContainsString('f.*', $sql);
        $this->assertStringNotContainsString('f.label', $sql);
        $this->assertMatchesRegularExpression('/LIMIT /i', $sql);
      }
    }
  }

  /**
   * Tests the scan form, which starts a batch.
   */
  public function testScanForm(): void {
    $form_builder = $this->container->get('form_builder');
    $form = $form_builder->getForm(ScanForm::class);
    $this->assertSame(['node:page' => 'Content: Page'], array_map(strval(...), $form['bundles']['#options']));
    $this->assertFalse($form['actions']['submit']['#disabled']);

    // A form submitted in code runs its batch at once.
    $this->container->get('database')->truncate(FindingStorage::TABLE)->execute();
    $form_state = (new FormState())->setValues(['bundles' => ['node:page' => 'node:page']]);
    $form_builder->submitForm(ScanForm::class, $form_state);
    $this->assertCount(2, iterator_to_array($this->container->get('vale.finding_storage')->rows(), FALSE));
    $status = array_map(strval(...), $this->container->get('messenger')->deleteByType('status'));
    $this->assertStringStartsWith('Checked 1 item', $status[0]);

    // Nothing chosen starts nothing.
    $form_state = (new FormState())->setValues(['bundles' => []]);
    $form_builder->submitForm(ScanForm::class, $form_state);
    $this->assertSame([], $this->container->get('messenger')->messagesByType('status'));

    // Without Vale, the form says why and cannot be submitted.
    $this->setValeSetting('vale_binary', NULL);
    $this->container->get('cache.vale')->deleteAll();
    $form = $form_builder->getForm(ScanForm::class);
    $this->assertTrue($form['actions']['submit']['#disabled']);
  }

  /**
   * Tests the messages that end a scan.
   */
  public function testFinished(): void {
    ScanBatch::finished(TRUE, ['entities' => 2, 'findings' => 2]);
    ScanBatch::finished(FALSE, []);
    $messenger = $this->container->get('messenger');
    $this->assertSame(['Checked 2 items and found 2 alerts.'], array_map(strval(...), $messenger->messagesByType('status')));
    $this->assertSame(['The scan did not finish. The report holds what it checked.'], array_map(strval(...), $messenger->messagesByType('error')));
    $this->assertSame('/admin/reports/vale', ScanBatch::reportUrl()->toString());
  }

}
