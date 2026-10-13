<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Drush\Commands\ValeCommands;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Drush commands' own logic.
 *
 * DrushLintTest runs them through Drush. This calls them directly.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ValeCommandsTest extends KernelTestBase {

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
   * Gives the commands.
   */
  protected function commands(): ValeCommands {
    return ValeCommands::create($this->container);
  }

  /**
   * Gives the rows of a result.
   *
   * @return array<mixed>
   *   The rows.
   */
  protected function rows(CommandResult $result): array {
    $data = $result->getOutputData();
    $this->assertInstanceOf(RowsOfFields::class, $data);
    return $data->getArrayCopy();
  }

  /**
   * Tests vale:lint.
   */
  public function testLint(): void {
    Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'We delve.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ])->save();

    $result = $this->commands()->lint('node:page', ['workspace' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $rows = $this->rows($result);
    $this->assertSame(['Demo.Delve', 'Demo.Very'], array_column($rows, 'rule'));
    $this->assertSame('node 1: Guide', $rows[1]['host']);
    $this->assertSame('paragraph 1 (docs_text)', $rows[1]['entity']);

    // Everything, with only warnings, exits zero.
    $field = $this->container->get('entity_type.manager')->getStorage('field_config')->load('paragraph.docs_text.field_text');
    $field->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Very', 'level' => 'warning']])->save();
    $this->assertSame(0, $this->commands()->lint(NULL, ['workspace' => NULL])->getExitCode());

    // Refusals.
    $this->assertSame(1, $this->commands()->lint('node:nope', ['workspace' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->lint('node:page', ['workspace' => 'stage'])->getExitCode());
    $this->assertSame(1, $this->commands()->lint(NULL, ['workspace' => 'stage'])->getExitCode());
    $this->setSetting('vale_binary', NULL);
    $this->assertSame(1, $this->commands()->lint(NULL, ['workspace' => NULL])->getExitCode());
  }

  /**
   * Tests that a value Vale could not check fails vale:lint.
   */
  public function testLintUnchecked(): void {
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_text' => 'FAKE_CRASH'])->save();
    $result = $this->commands()->lint('node:page', ['workspace' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame([], $this->rows($result));
  }

  /**
   * Tests vale:status and vale:rules.
   */
  public function testStatusAndRules(): void {
    $result = $this->commands()->status();
    $this->assertSame(0, $result->getExitCode());
    $data = $result->getOutputData();
    $this->assertInstanceOf(PropertyList::class, $data);
    $this->assertSame('0.0.0-fake', $data['version']);
    $this->assertSame(3, $data['rules']);

    $this->assertSame(['Demo.Delve', 'Demo.Really', 'Demo.Very'], array_keys($this->commands()->rules()->getArrayCopy()));

    $this->setSetting('vale_binary', NULL);
    $this->assertSame(1, $this->commands()->status()->getExitCode());
  }

  /**
   * Tests vale:check.
   */
  public function testCheck(): void {
    $result = $this->commands()->check('node.page.field_text', 'We delve.', ['text-format' => NULL]);
    $this->assertSame(0, $result->getExitCode());
    $this->assertSame(['Demo.Delve'], array_column($this->rows($result), 'rule'));

    $this->assertSame(1, $this->commands()->check('node.page.field_text', 'This is very good.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page.field_code', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('nope.page.field_text', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page.field_text', 'FAKE_CRASH', ['text-format' => NULL])->getExitCode());
  }

  /**
   * Tests that vale:install refuses an unpinned version without a checksum.
   */
  public function testInstallRefused(): void {
    $result = $this->commands()->install(['dir' => $this->valeDirectory . '/bin', 'vale-version' => '1.0.0', 'sha256' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $this->assertFileDoesNotExist($this->valeDirectory . '/bin/vale');
  }

}
