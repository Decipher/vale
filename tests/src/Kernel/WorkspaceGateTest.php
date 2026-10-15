<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\vale\Plugin\Action\CheckProseAction;
use Drupal\workspaces\WorkspacePublishException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the check made before a workspace is published.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class WorkspaceGateTest extends KernelTestBase {

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
   * Sets the workspace mode.
   *
   * @param string $mode
   *   The mode.
   */
  protected function setMode(string $mode): void {
    $this->config('vale.settings')->set('workspace_mode', $mode)->save();
  }

  /**
   * Saves a page in the stage workspace.
   *
   * @param string $text
   *   The page's text.
   */
  protected function stagePage(string $text): void {
    $this->switchToWorkspace('stage');
    Node::create(['type' => 'page', 'title' => 'Staged', 'field_text' => $text])->save();
    $this->switchToLive();
  }

  /**
   * Publishes the stage workspace.
   *
   * @return string|null
   *   Why publishing stopped, or NULL when it went ahead.
   */
  protected function publish(): ?string {
    try {
      $this->workspaces['stage']->publish();
      return NULL;
    }
    catch (WorkspacePublishException $e) {
      return $e->getMessage();
    }
  }

  /**
   * Gives the messages shown, by type.
   *
   * @return array<string, string[]>
   *   The messages.
   */
  protected function messages(): array {
    return array_map(
      static fn (array $messages): array => array_map(strval(...), $messages),
      $this->container->get('messenger')->all(),
    );
  }

  /**
   * Gives the number of published pages in Live.
   */
  protected function livePages(): int {
    return count($this->entityTypeManager->getStorage('node')->getQuery()->accessCheck(FALSE)->condition('status', 1)->execute());
  }

  /**
   * Tests that block mode refuses a workspace with an error.
   */
  public function testBlock(): void {
    $this->setMode('block');
    $this->stagePage('This is very good.');

    $this->assertSame('Stage: 1 prose error, 0 warnings or suggestions, 0 values not checked.', $this->publish());
    $this->assertSame(0, $this->livePages());
    $messages = $this->messages();
    // The message is markup, escaped once.
    $this->assertSame('node "Staged", field_text: Demo.Very: Avoid &#039;very&#039;. (line 1: "very")', $messages['error'][0]);
    $this->assertStringStartsWith('Stage: 1 prose error,', $messages['error'][1]);
  }

  /**
   * Tests that block mode lets warnings through.
   */
  public function testBlockWithWarnings(): void {
    $this->setMode('block');
    $this->stagePage('We delve.');
    $this->assertNull($this->publish());
    $this->assertSame(1, $this->livePages());
    $warnings = $this->messages()['warning'];
    $this->assertStringContainsString('Demo.Delve', $warnings[0]);
    $this->assertStringStartsWith('Stage: 0 prose errors, 1 warning or suggestion', $warnings[1]);
  }

  /**
   * Tests that advise mode publishes and shows the findings.
   */
  public function testAdvise(): void {
    $this->setMode('advise');
    $this->stagePage('This is very good.');
    $this->assertNull($this->publish());
    $this->assertSame(1, $this->livePages());
    $warnings = $this->messages()['warning'];
    $this->assertStringContainsString('Demo.Very', $warnings[0]);
    $this->assertStringStartsWith('Stage: 1 prose error,', $warnings[1]);
  }

  /**
   * Tests that a long list of findings is cut, and the total still shown.
   */
  public function testManyWarnings(): void {
    $this->setMode('advise');
    $this->stagePage(implode("\n", array_fill(0, 25, 'We delve.')));
    $this->assertNull($this->publish());
    $warnings = $this->messages()['warning'];
    $this->assertCount(21, $warnings);
    $this->assertSame('Stage: 0 prose errors, 25 warnings or suggestions, 0 values not checked. 5 are not listed. Run drush vale:lint --workspace=stage for all of them.', $warnings[20]);
  }

  /**
   * Tests that off mode lints nothing.
   */
  public function testOff(): void {
    $this->setMode('off');
    $this->stagePage('This is very good.');
    $this->assertNull($this->publish());
    $this->assertSame(1, $this->livePages());
    // Publishing saves the page in Live, which refreshes the content report,
    // but the publish check itself shows nothing.
    $this->assertSame([], $this->messages());
  }

  /**
   * Tests that findings already in Live do not count.
   */
  public function testLiveFindingsIgnored(): void {
    $this->setMode('block');
    $live = Node::create(['type' => 'page', 'title' => 'Live', 'field_text' => 'Fine.']);
    $live->save();
    // A finding that is already live, saved past validation as old content
    // would be.
    $live->set('field_text', 'This is very good.');
    $live->save();
    $this->stagePage('Fine.');
    $this->assertNull($this->publish());
  }

  /**
   * Tests that block mode refuses when Vale cannot be run.
   */
  public function testUnavailableBlocks(): void {
    $this->setMode('block');
    $this->stagePage('Fine.');
    $this->setSetting('vale_binary', NULL);
    $this->assertStringContainsString('Prose in Stage not checked:', (string) $this->publish());
    $this->assertSame(0, $this->livePages());
  }

  /**
   * Tests that settings.php can override the mode.
   */
  public function testSettingsOverride(): void {
    $this->setMode('advise');
    $GLOBALS['config']['vale.settings']['workspace_mode'] = 'block';
    $this->container->get('config.factory')->reset('vale.settings');
    $this->stagePage('This is very good.');
    $this->assertNotNull($this->publish());
  }

  /**
   * Tests the messages for a paragraph and for text Vale could not check.
   */
  public function testComponentAndUnchecked(): void {
    $this->setMode('advise');
    $this->switchToWorkspace('stage');
    Node::create([
      'type' => 'page',
      'title' => 'Staged',
      'field_text' => 'FAKE_CRASH',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ])->save();
    // The report describes Live, so it refuses to be written here.
    $this->assertSame('Switch to Live to check content for the report.', $this->container->get('vale.report_refresher')->refusal());
    $this->switchToLive();

    $this->assertNull($this->publish());
    $warnings = $this->messages()['warning'];
    $this->assertStringStartsWith('node "Staged", field_text: not checked.', $warnings[0]);
    $this->assertStringStartsWith('node "Staged", paragraph "Staged &gt; Content", field_text: Demo.Very:', $warnings[1]);
  }

  /**
   * Tests that the bulk action refuses to write the report in a workspace.
   */
  public function testActionRefusedInWorkspace(): void {
    $page = Node::create(['type' => 'page', 'title' => 'Live page', 'field_text' => 'This is very good.']);
    $page->save();
    $this->switchToWorkspace('stage');
    $action = $this->container->get('plugin.manager.action')->createInstance('vale_check_prose');
    $this->assertInstanceOf(CheckProseAction::class, $action);
    $action->executeMultiple([$page]);
    $this->switchToLive();
    $this->assertSame(['Switch to Live to check content for the report.'], $this->messages()['error'] ?? []);
  }

}
