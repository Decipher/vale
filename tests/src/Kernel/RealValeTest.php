<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\ValeRunnerInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the runner against the real Vale binary.
 *
 * The other tests use a fake binary, so they run anywhere. This one checks
 * that the fake tells the truth about what Vale does. It runs only when the
 * VALE_BINARY environment variable points at Vale, as it does in CI.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class RealValeTest extends KernelTestBase {

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
    $binary = getenv('VALE_BINARY');
    if (!is_string($binary) || $binary === '' || !is_executable($binary)) {
      $this->markTestSkipped('Set VALE_BINARY to the Vale binary to run this test.');
    }
    $this->installConfig(['vale']);
    $this->setUpVale($binary);
  }

  /**
   * Gives the runner service.
   */
  protected function runner(): ValeRunnerInterface {
    return $this->container->get('vale.runner');
  }

  /**
   * Summarises alerts as [rule, level, line, start, end, match].
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts.
   *
   * @return array<array{0: string, 1: string, 2: int, 3: int, 4: int, 5: string}>
   *   The summaries.
   */
  protected function summarise(array $alerts): array {
    return array_map(static fn (Alert $alert): array => [$alert->rule, $alert->level, $alert->line, $alert->start, $alert->end, $alert->match], $alerts);
  }

  /**
   * Tests that real Vale gives what the fake gives.
   */
  public function testMatchesTheFake(): void {
    $text = "This is very good.\nWe delve, really.\nCafé, naïve and very.";
    $this->assertSame([
      ['Demo.Very', Alert::ERROR, 1, 9, 12, 'very'],
      ['Demo.Delve', Alert::WARNING, 2, 4, 8, 'delve'],
      ['Demo.Really', Alert::SUGGESTION, 2, 11, 16, 'really'],
      ['Demo.Very', Alert::ERROR, 3, 17, 20, 'very'],
    ], $this->summarise($this->runner()->lint($text, 'txt', 'node.page.field_text')->alerts));

    $alert = $this->runner()->lint('Very.', 'txt')->alerts[0];
    $this->assertSame("Avoid 'Very'.", $alert->message);
    $this->assertSame('Intensifiers weaken prose.', $alert->description);
    $this->assertSame('https://example.com/very', $alert->link);
    $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+/', (string) $this->container->get('vale.environment')->version());
  }

  /**
   * Tests that HTML fragments and untagged text are linted.
   */
  public function testHtml(): void {
    $this->assertSame(
      [['Demo.Very', Alert::ERROR, 1, 13, 16, 'very']],
      $this->summarise($this->runner()->lint('Text is <em>very</em> good.', 'html')->alerts),
    );
    $this->assertSame(
      [['Demo.Very', Alert::ERROR, 2, 9, 12, 'very']],
      $this->summarise($this->runner()->lint("<p>Fine.</p>\nText is very good.", 'html')->alerts),
    );
    // Code is not prose.
    $this->assertSame([], $this->runner()->lint('<p>Fine <code>very</code>.</p>', 'html')->alerts);
  }

  /**
   * Tests that Markdown is read as Markdown.
   */
  public function testMarkdown(): void {
    $text = "Fine.\n\n```\nvery code\n```\n\nIt is `very` and very.";
    $this->assertSame(
      [['Demo.Very', Alert::ERROR, 7, 18, 21, 'very']],
      $this->summarise($this->runner()->lint($text, 'md', 'node.page.field_text')->alerts),
    );
  }

  /**
   * Tests that a .vale.ini section acts as a field profile.
   */
  public function testProfile(): void {
    // The Demo configuration turns Demo.Very off for [*.field_callout.md].
    $this->assertSame(['Demo.Delve'], array_map(
      static fn (Alert $alert): string => $alert->rule,
      $this->runner()->lint('Very. We delve.', 'md', 'paragraph.docs_callout.field_callout')->alerts,
    ));
    $this->assertSame(['Demo.Very', 'Demo.Delve'], array_map(
      static fn (Alert $alert): string => $alert->rule,
      $this->runner()->lint('Very. We delve.', 'md', 'paragraph.docs_text.field_text')->alerts,
    ));
  }

  /**
   * Tests a configuration Vale refuses.
   */
  public function testBrokenConfiguration(): void {
    file_put_contents($this->valeDirectory . '/.vale.ini', "StylesPath = missing\n[*]\nBasedOnStyles = Demo\n");
    $result = $this->runner()->lint('Very.', 'txt');
    $this->assertFalse($result->isChecked());
    $this->assertStringContainsString('exit code 2', $result->reason);
  }

}
