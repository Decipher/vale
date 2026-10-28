<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\Hook\ValeRequirements;
use Drupal\vale\LintResult;
use Drupal\vale\ValeEnvironment;
use Drupal\vale\ValeRunner;
use Drupal\vale\ValeRunnerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests running Vale and reading its results.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ValeRunnerTest extends KernelTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['vale'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['vale']);
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
   * Gives the runner service.
   */
  protected function runner(): ValeRunnerInterface {
    return $this->container->get('vale.runner');
  }

  /**
   * Tests that alerts carry the rule, level, message and span.
   */
  public function testAlerts(): void {
    $result = $this->runner()->lint("This is very good.\nWe delve, really.", 'md', 'node.page.field_text');

    $this->assertTrue($result->isChecked());
    $this->assertSame(['node.page.field_text.md'], $this->valeRuns());
    $this->assertSame([
      ['Demo.Very', Alert::ERROR, "Avoid 'very'.", 1, 9, 12, 'very'],
      ['Demo.Delve', Alert::WARNING, "'delve' is an AI tell.", 2, 4, 8, 'delve'],
      ['Demo.Really', Alert::SUGGESTION, "Consider removing 'really'.", 2, 11, 16, 'really'],
    ], array_map(static fn (Alert $alert): array => [
      $alert->rule,
      $alert->level,
      $alert->message,
      $alert->line,
      $alert->start,
      $alert->end,
      $alert->match,
    ], $result->alerts));
    $this->assertSame('Intensifiers weaken prose.', $result->alerts[0]->description);

    // HTML is wrapped in a document, and the lines still match the text.
    $result = $this->runner()->lint("<p>Fine.</p>\nThis is <em>very</em> good.", 'html');
    $this->assertSame([[2, 13, 16]], array_map(static fn (Alert $alert): array => [$alert->line, $alert->start, $alert->end], $result->alerts));

    // Columns count characters, not bytes.
    $result = $this->runner()->lint('Café, naïve and very.');
    $this->assertSame([17, 20], [$result->alerts[0]->start, $result->alerts[0]->end]);
    $this->assertSame('https://example.com/very', $result->alerts[0]->link);
  }

  /**
   * Tests that empty text is a pass without running Vale.
   */
  public function testEmptyText(): void {
    $result = $this->runner()->lint("  \n ");
    $this->assertTrue($result->isChecked());
    $this->assertSame([], $result->alerts);
    $this->assertSame([], $this->valeRuns());
  }

  /**
   * Tests that the extension comes from the text format.
   */
  public function testFormatMapping(): void {
    $this->config('vale.settings')
      ->set('formats', ['docs_markdown' => 'md', 'broken' => 'm d'])
      ->save();
    $runner = $this->runner();

    $this->assertSame('md', $runner->extensionForFormat('docs_markdown'));
    $this->assertSame('html', $runner->extensionForFormat('basic_html'));
    $this->assertSame('html', $runner->extensionForFormat(NULL));
    $this->assertSame('html', $runner->extensionForFormat('broken'));

    $this->config('vale.settings')->set('default_extension', 'txt')->save();
    $this->assertSame('txt', $this->runner()->extensionForFormat('basic_html'));

    $this->runner()->lint('Very.', 'md', 'node.page.field_text');
    $this->runner()->lint('Very.', '../../etc', 'node.page.field_text');
    $this->assertSame(['node.page.field_text.md', 'node.page.field_text.html'], $this->valeRuns());
  }

  /**
   * Tests that text and names reach Vale as data, not as commands.
   */
  public function testMetacharacters(): void {
    $marker = $this->valeDirectory . '/ran';
    $text = "Very \$(touch $marker) `touch $marker` ; touch $marker && echo 'x' \"y\"";
    $result = $this->runner()->lint($text, 'md', "node; touch $marker");

    $this->assertTrue($result->isChecked());
    $this->assertCount(1, $result->alerts);
    $this->assertFileDoesNotExist($marker);
    $this->assertSame(['node__touch_' . str_replace('/', '_', $marker) . '.md'], $this->valeRuns());
  }

  /**
   * Tests that a run past the time limit is a failure, not a pass.
   */
  public function testTimeout(): void {
    $this->config('vale.settings')->set('timeout', 1)->save();
    $result = $this->runner()->lint('FAKE_TIMEOUT');

    $this->assertFalse($result->isChecked());
    $this->assertSame(LintResult::FAILED, $result->status);
    $this->assertStringContainsString('ran out of time', $result->reason);
  }

  /**
   * Tests that a run killed by a signal is a failure, not a pass.
   */
  public function testKilled(): void {
    $result = $this->runner()->lint('FAKE_KILLED');

    $this->assertSame(LintResult::FAILED, $result->status);
    $this->assertStringStartsWith('Vale could not be run:', $result->reason);
  }

  /**
   * Tests that a failed run and unreadable output are failures.
   */
  public function testFailures(): void {
    $crash = $this->runner()->lint('FAKE_CRASH');
    $this->assertSame(LintResult::FAILED, $crash->status);
    $this->assertStringContainsString('Fake failure.', $crash->reason);

    $garbage = $this->runner()->lint('FAKE_GARBAGE');
    $this->assertSame(LintResult::FAILED, $garbage->status);
    $this->assertStringContainsString('could not be read', $garbage->reason);

    // Failures are never cached.
    $this->runner()->lint('FAKE_CRASH');
    $this->assertCount(3, $this->valeRuns());
  }

  /**
   * Tests that results are cached until the configuration changes.
   */
  public function testCache(): void {
    $first = $this->runner()->lint('This is very good.', 'md', 'node.page.field_text');
    $second = $this->runner()->lint('This is very good.', 'md', 'node.page.field_text');
    $this->assertEquals($first, $second);
    $this->assertCount(1, $this->valeRuns());

    // A result that is not stored is linted again next time.
    $this->runner()->lint('Not kept.', 'md', 'node.page.field_text', FALSE);
    $this->runner()->lint('Not kept.', 'md', 'node.page.field_text', FALSE);
    $this->assertCount(3, $this->valeRuns());

    // Another name or another extension is another result.
    $this->runner()->lint('This is very good.', 'md', 'node.page.field_callout');
    $this->runner()->lint('This is very good.', 'html', 'node.page.field_text');
    $this->assertCount(5, $this->valeRuns());

    // A changed style file changes the fingerprint. The fingerprint is
    // worked out once per request, so new services stand in for a new
    // request.
    file_put_contents($this->valeDirectory . '/styles/Demo/Very.yml', "\n# Edited.\n", FILE_APPEND);
    $this->freshRunner()->lint('This is very good.', 'md', 'node.page.field_text');
    $this->assertCount(6, $this->valeRuns());

    // So does a changed .vale.ini.
    file_put_contents($this->valeDirectory . '/.vale.ini', "\n# Edited.\n", FILE_APPEND);
    $this->freshRunner()->lint('This is very good.', 'md', 'node.page.field_text');
    $this->assertCount(7, $this->valeRuns());

    // An unchanged configuration still hits the cache in a new request.
    $this->freshRunner()->lint('This is very good.', 'md', 'node.page.field_text');
    $this->assertCount(7, $this->valeRuns());
  }

  /**
   * Tests the results when Vale is not set up.
   */
  public function testUnavailable(): void {
    $this->setSetting('vale_binary', NULL);
    $result = $this->runner()->lint('Very.');
    $this->assertSame(LintResult::UNAVAILABLE, $result->status);
    $this->assertStringContainsString('vale_binary', $result->reason);

    $this->setSetting('vale_binary', $this->valeDirectory . '/.vale.ini');
    $this->assertStringContainsString('not executable', $this->runner()->lint('Very.')->reason);

    $this->setUpVale();
    $this->setSetting('vale_config', NULL);
    $this->assertStringContainsString("\$settings['vale_config']", $this->runner()->lint('Very.')->reason);
    $this->setSetting('vale_config', $this->valeDirectory . '/missing.ini');
    $this->assertStringContainsString('cannot be read', $this->runner()->lint('Very.')->reason);
    $this->assertSame([], $this->valeRuns());

    $requirement = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class)->requirement();
    $this->assertFalse($requirement['severity']);
    $this->assertSame('Not available', $requirement['value']);
    $this->assertStringEndsWith('Content is saved without being checked.', $requirement['description']);

    // With fail_closed, the same text is refused instead.
    $this->config('vale.settings')->set('fail_closed', TRUE)->save();
    $requirement = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class)->requirement();
    $this->assertStringEndsWith('Saves of text checked with Vale are refused until this is fixed.', $requirement['description']);
  }

  /**
   * Tests the rule catalogue and the status report.
   */
  public function testEnvironment(): void {
    $environment = $this->container->get('vale.environment');
    $this->assertSame('0.0.0-fake', $environment->version());
    $this->assertSame([$this->valeDirectory . '/styles'], $environment->stylesPaths());

    $rules = $environment->rules();
    $this->assertSame(['Demo.Delve', 'Demo.Really', 'Demo.Very'], array_keys($rules));
    $this->assertSame([
      'level' => Alert::ERROR,
      'message' => "Avoid '%s'.",
      'description' => 'Intensifiers weaken prose.',
      'link' => 'https://example.com/very',
    ], $rules['Demo.Very']);

    $requirement = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class)->requirement();
    $this->assertTrue($requirement['severity']);
    $this->assertSame('0.0.0-fake', $requirement['value']);
    $this->assertStringContainsString('Its styles hold 3 rules.', $requirement['description']);

    // Both hooks give the enum where it exists, and the integer before it.
    // The legacy hook says nothing outside the runtime phase.
    $this->container->get('module_handler')->loadInclude('vale', 'install');
    $this->assertSame([], vale_requirements('install'));
    $this->assertSame(enum_exists(RequirementSeverity::class) ? RequirementSeverity::OK : 0, vale_requirements('runtime')['vale']['severity']);
    if (class_exists(RequirementSeverity::class)) {
      $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class);
      $this->assertSame(RequirementSeverity::OK, $hooks->runtime()['vale']['severity']);
    }
    $this->setSetting('vale_binary', NULL);
    $this->assertSame(enum_exists(RequirementSeverity::class) ? RequirementSeverity::Warning : 1, vale_requirements('runtime')['vale']['severity']);
  }

  /**
   * Tests that a probe that failed is tried again, not cached.
   */
  public function testFailedProbesNotCached(): void {
    $environment = $this->container->get('vale.environment');
    // Setting up Vale has already probed it.
    $this->container->get('cache.vale')->deleteAll();
    // Symfony's Process passes $_ENV on to Vale.
    $_ENV['FAKE_PROBE_FAIL'] = '1';
    try {
      $this->assertNull($environment->version());
      $this->assertSame([], $environment->stylesPaths());
    }
    finally {
      unset($_ENV['FAKE_PROBE_FAIL']);
    }
    $this->assertSame('0.0.0-fake', $environment->version());
    $this->assertSame([$this->valeDirectory . '/styles'], $environment->stylesPaths());
  }

  /**
   * Tests a styles directory that is missing on this server.
   *
   * The styles paths are cached in a bin all servers share, so a server can
   * read a path it does not have. The value saves unchecked, not with an
   * error page.
   */
  public function testMissingStylesDirectory(): void {
    $environment = $this->container->get('vale.environment');
    $this->assertSame([$this->valeDirectory . '/styles'], $environment->stylesPaths());
    rename($this->valeDirectory . '/styles', $this->valeDirectory . '/moved');

    $result = $this->freshRunner()->lint('This is very good.');
    $this->assertTrue($result->isChecked());
    rename($this->valeDirectory . '/moved', $this->valeDirectory . '/styles');
  }

  /**
   * Builds a runner with its own environment, as a new request would.
   */
  protected function freshRunner(): ValeRunnerInterface {
    $environment = new ValeEnvironment(
      $this->container->get('cache.vale'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
    );
    return new ValeRunner(
      $environment,
      $this->container->get('cache.vale'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.vale'),
    );
  }

}
