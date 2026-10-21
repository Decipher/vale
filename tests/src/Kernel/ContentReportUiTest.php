<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
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
