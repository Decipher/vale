<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests drush vale:lint.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class DrushLintTest extends BrowserTestBase {

  use DrushTestTrait;
  use ValeContentTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['entity_reference_revisions', 'node', 'paragraphs', 'text', 'vale'];

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
   * Tests that warnings exit zero and are printed.
   */
  public function testWarnings(): void {
    Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'We delve.'])],
    ])->save();

    // A wide terminal, so the table does not wrap.
    $this->drush('vale:lint', ['node:page'], [], NULL, NULL, 0, NULL, ['COLUMNS' => '300']);
    $output = $this->getOutput();
    $this->assertStringContainsString('node 1: Guide', $output);
    $this->assertStringContainsString('paragraph 1 (docs_text)', $output);
    $this->assertStringContainsString('Demo.Delve', $output);

    $this->drush('vale:lint', ['node:page'], ['format' => 'json']);
    $records = $this->getOutputFromJSON();
    $this->assertCount(1, $records);
    $this->assertSame([
      'host' => 'node 1: Guide',
      'entity' => 'paragraph 1 (docs_text)',
      'entity_type' => 'paragraph',
      'id' => '1',
      'bundle' => 'docs_text',
      'label' => $records[0]['label'],
      'language' => 'en',
      'host_type' => 'node',
      'host_id' => '1',
      'host_label' => 'Guide',
      'field' => 'field_text',
      'delta' => 0,
      'line' => 1,
      'start' => 4,
      'end' => 8,
      'level' => 'warning',
      'rule' => 'Demo.Delve',
      'message' => "'delve' is an AI tell.",
      'text' => 'delve',
    ], $records[0]);
  }

  /**
   * Tests that an error exits non-zero.
   */
  public function testError(): void {
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_text' => 'This is very good.'])->save();
    $this->drush('vale:lint', [], [], NULL, NULL, 1, NULL, ['COLUMNS' => '300']);
    $this->assertStringContainsString('Demo.Very', $this->getOutput());

    // So does a bad scope.
    $this->drush('vale:lint', ['node:nope'], [], NULL, NULL, 1);
    $this->assertStringContainsString('There is no node bundle "nope".', $this->getErrorOutput());
  }

  /**
   * Tests that Vale being unavailable exits non-zero and says why.
   */
  public function testUnavailable(): void {
    $this->setValeSetting('vale_binary', '/nonexistent/vale');
    $this->drush('vale:lint', [], [], NULL, NULL, 1);
    $this->assertStringContainsString('Vale could not be run.', $this->getErrorOutput());
  }

  /**
   * Tests vale:status and vale:rules.
   */
  public function testStatusAndRules(): void {
    $this->drush('vale:status', [], ['format' => 'json']);
    $status = $this->getOutputFromJSON();
    $this->assertSame('Yes', $status['available']);
    $this->assertSame('0.0.0-fake', $status['version']);
    $this->assertSame(3, $status['rules']);

    $this->drush('vale:rules', [], ['format' => 'json']);
    $this->assertSame(['Demo.Delve', 'Demo.Really', 'Demo.Very'], array_keys($this->getOutputFromJSON()));
    $this->assertSame('error', $this->getOutputFromJSON()['Demo.Very']['level']);

    $this->setValeSetting('vale_binary', '/nonexistent/vale');
    $this->drush('vale:status', [], ['format' => 'json'], NULL, NULL, 1);
    $this->assertSame('No', $this->getOutputFromJSON()['available']);
  }

  /**
   * Tests vale:check.
   */
  public function testCheck(): void {
    $this->drush('vale:check', ['node.page.field_text', 'We delve.'], ['format' => 'json']);
    $this->assertSame(['Demo.Delve'], array_column($this->getOutputFromJSON(), 'rule'));

    $this->drush('vale:check', ['node.page.field_text', 'This is very good.'], ['format' => 'json'], NULL, NULL, 1);
    $this->assertSame(['Demo.Very'], array_column($this->getOutputFromJSON(), 'rule'));

    // Standard input, in a named format.
    $draft = $this->valeDirectory . '/draft.md';
    file_put_contents($draft, "Fine.\nReally fine.\n");
    $this->config('vale.settings')->set('formats', ['docs_markdown' => 'md'])->save();
    $this->drush('vale:check', ['node.page.field_text'], ['format' => 'json', 'text-format' => 'docs_markdown'], NULL, NULL, 0, '< ' . escapeshellarg($draft));
    $this->assertSame([[2, 'Demo.Really']], array_map(static fn (array $row): array => [$row['line'], $row['rule']], $this->getOutputFromJSON()));
    $this->assertSame('node.page.field_text.md', $this->valeRuns()[count($this->valeRuns()) - 1]);

    // A field that is not checked, or does not exist, is refused.
    $this->drush('vale:check', ['node.page.field_code', 'Very.'], [], NULL, NULL, 1);
    $this->assertStringContainsString('is not checked by Vale', $this->getErrorOutput());
    $this->drush('vale:check', ['node.page', 'Very.'], [], NULL, NULL, 1);
    $this->assertStringContainsString('There is no field "node.page".', $this->getErrorOutput());
  }

  /**
   * Tests that vale:install refuses a SHA-256 that is not one.
   *
   * The download itself is covered by ValeInstallerTest, without a network.
   */
  public function testInstallRefusesBadChecksum(): void {
    $this->drush('vale:install', [], ['dir' => $this->valeDirectory . '/bin', 'vale-version' => '3.24.0', 'sha256' => 'abc'], NULL, NULL, 1);
    $this->assertStringContainsString('The SHA-256 must be 64 hexadecimal characters.', $this->getErrorOutput());
    $this->assertFileDoesNotExist($this->valeDirectory . '/bin/vale');
  }

}
