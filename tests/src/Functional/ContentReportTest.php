<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the prose linting report, its scan, export and bulk action.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ContentReportTest extends BrowserTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['block', 'entity_reference_revisions', 'node', 'paragraphs', 'text', 'vale', 'views'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->createValeContentModel();
    $this->drupalPlaceBlock('local_actions_block');
  }

  /**
   * Logs in a user with the given permissions and sets up Vale.
   *
   * @param string[] $permissions
   *   The permissions.
   */
  protected function logInWith(array $permissions): void {
    $this->drupalLogin($this->asAccount($this->drupalCreateUser($permissions)));
    $this->setUpVale();
  }

  /**
   * Makes pages as an import would, without the report seeing them.
   */
  protected function importPages(): void {
    Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'We delve.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ])->save();
    Node::create(['type' => 'page', 'title' => 'Notes', 'field_text' => 'Fine.'])->save();
    // The pages were saved before Vale was set up in this test, so the report
    // has nothing yet.
    \Drupal::database()->truncate('vale_finding')->execute();
  }

  /**
   * Tests scanning, filtering and exporting.
   */
  public function testScanAndReport(): void {
    $this->importPages();
    $this->logInWith(['view vale report', 'scan content with vale']);

    $this->drupalGet('admin/reports/vale');
    $this->assertSession()->pageTextContains('No alerts. Check content to fill the report.');

    $this->clickLink('Check content');
    $this->assertSession()->checkboxChecked('bundles[node:page]');
    $this->submitForm([], 'Check content');
    $this->assertSession()->pageTextContains('Checked 2 items and found 2 alerts.');
    $this->assertSession()->addressEquals('admin/reports/vale');
    $this->assertSession()->linkExists('Guide');
    $this->assertSession()->pageTextContains('Demo.Very');
    $this->assertSession()->pageTextContains('Demo.Delve');

    // An array in the query string does not break the export link.
    $this->drupalGet('admin/reports/vale', ['query' => ['rule' => ['Demo.Very']]]);
    $this->assertSession()->statusCodeEquals(200);

    // Filter to errors, and export what the filter shows.
    $this->drupalGet('admin/reports/vale', ['query' => ['level' => 'error']]);
    $this->assertSession()->pageTextContains('Demo.Very');
    $this->assertSession()->pageTextNotContains('Demo.Delve');
    $this->clickLink('Export these alerts as CSV');
    $this->assertSession()->responseHeaderContains('Content-Type', 'text/csv');
    $lines = array_values(array_filter(explode("\n", $this->getSession()->getPage()->getContent())));
    $this->assertCount(2, $lines);
    $this->assertStringStartsWith('"Root type","Root ID"', $lines[0]);
    $this->assertStringContainsString('Demo.Very', $lines[1]);
    $this->assertStringContainsString(',paragraph,', $lines[1]);
  }

  /**
   * Tests the bulk action on the content overview.
   */
  public function testBulkAction(): void {
    $this->importPages();
    $this->logInWith(['access content overview', 'view vale report', 'scan content with vale', 'bypass node access']);

    $this->drupalGet('admin/content');
    $this->submitForm([
      'node_bulk_form[0]' => TRUE,
      'node_bulk_form[1]' => TRUE,
      'action' => 'node_vale_check_prose',
    ], 'Apply to selected items');
    $this->assertSession()->pageTextContains('Checked 2 items: 1 error and 1 other alert.');
    $this->drupalGet('admin/reports/vale');
    $this->assertSession()->pageTextContains('Demo.Very');
  }

  /**
   * Tests who may see, export and scan.
   */
  public function testAccess(): void {
    $this->logInWith(['access content']);
    foreach (['admin/reports/vale', 'admin/reports/vale/export', 'admin/reports/vale/scan'] as $path) {
      $this->drupalGet($path);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->logInWith(['view vale report']);
    $this->drupalGet('admin/reports/vale');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->linkNotExists('Check content');
    $this->drupalGet('admin/reports/vale/scan');
    $this->assertSession()->statusCodeEquals(403);
  }

}
