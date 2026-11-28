<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\Controller\BrowserConfigController;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

/**
 * Tests that the browser build gives the alerts the CLI gives.
 *
 * The same values are linted by the server, with the Vale binary, and by
 * the vale/browser library's code in Node, with Vale's browser build and
 * the configuration the module serves to browsers. It runs only when
 * VALE_BINARY points at Vale 3.26.0 or later, VALE_WASM at a directory that
 * holds the browser build of the same version, and Node is installed.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class BrowserParityTest extends KernelTestBase {

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
   * The directory that holds vale.wasm and wasm_exec.js.
   */
  protected string $wasm;

  /**
   * The Node binary.
   */
  protected string $node;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $binary = getenv('VALE_BINARY');
    $wasm = getenv('VALE_WASM');
    if (!is_string($binary) || $binary === '' || !is_executable($binary) || !is_string($wasm) || !is_file($wasm . '/vale.wasm') || !is_file($wasm . '/wasm_exec.js')) {
      $this->markTestSkipped('Set VALE_BINARY to Vale and VALE_WASM to its browser build to run this test.');
    }
    $node = (new Process(['sh', '-c', 'command -v node']));
    $node->run();
    if (!$node->isSuccessful()) {
      $this->markTestSkipped('This test needs Node.');
    }
    $this->node = trim($node->getOutput());
    $this->wasm = $wasm;
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'node', 'vale']);
    $this->setUpVale($binary);
    mkdir($this->valeDirectory . '/tmp');
    $this->setSetting('file_temp_path', $this->valeDirectory . '/tmp');
    $this->container->set('file_system', new FileSystem($this->container->get('stream_wrapper_manager'), Settings::getInstance()));
    $this->createValeContentModel();
    $this->config('vale.settings')->set('formats', ['markdown' => 'md'])->save();
    $version = (string) $this->container->get('vale.environment')->version();
    if (version_compare($version, '3.26.0', '<')) {
      $this->markTestSkipped('Vale ' . $version . ' has no browser build.');
    }
  }

  /**
   * Lints values with the browser build.
   *
   * @param array<int, array{text: string, format: string|null}> $values
   *   The values of node.page.field_text.
   *
   * @return array<int, array<int, array<string, mixed>>>
   *   The alerts for each value, without the entity's details.
   */
  protected function lintInBrowser(array $values): array {
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $settings = $this->container->get('vale.browser')->attachments($field)['drupalSettings']['vale'];
    $controller = BrowserConfigController::create($this->container);
    $zip = $this->valeDirectory . '/styles.zip';
    file_put_contents($zip, (string) $controller->styles(new Request())->getContent());
    $module = dirname(__DIR__, 3);
    $input = [
      'loader' => $this->wasm . '/wasm_exec.js',
      'module' => $this->wasm . '/vale.wasm',
      'engine' => $module . '/js/vale-engine.js',
      'common' => $module . '/js/vale-common.js',
      'ini' => (string) $controller->config(new Request(), 'en')->getContent(),
      'packages' => [$zip],
      'formats' => ['formats' => $settings['formats'], 'defaultExtension' => $settings['defaultExtension']],
      'values' => array_map(static fn (array $value): array => $value + [
        'field' => $settings['fields']['node.page.field_text'],
        'context' => ['entity_type' => 'node', 'bundle' => 'page', 'field' => 'field_text', 'delta' => 0],
      ], $values),
    ];
    file_put_contents($this->valeDirectory . '/input.json', json_encode($input, JSON_THROW_ON_ERROR));
    $process = new Process([$this->node, $module . '/tests/js/browser-lint.cjs', $this->valeDirectory . '/input.json'], NULL, NULL, NULL, 60);
    $process->run();
    $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
    $results = json_decode($process->getOutput(), TRUE, 512, JSON_THROW_ON_ERROR);
    $keys = array_flip(['rule', 'level', 'message', 'line', 'start', 'end', 'match', 'description', 'link']);
    return array_map(static fn (array $alerts): array => array_map(static fn (array $alert): array => array_intersect_key($alert, $keys), $alerts), $results);
  }

  /**
   * Lints values with the CLI, as a save does.
   *
   * @param array<int, array{text: string, format: string|null}> $values
   *   The values of node.page.field_text.
   *
   * @return array<int, array<int, array<string, mixed>>>
   *   The alerts for each value.
   */
  protected function lintOnServer(array $values): array {
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $linter = $this->container->get('vale.field_linter');
    return array_map(static fn (array $value): array => array_map(
      static fn (Alert $alert): array => $alert->toArray(),
      $linter->lintText($field, $value['text'], $value['format'], FALSE)->alerts,
    ), $values);
  }

  /**
   * Tests that both give the same alerts.
   */
  public function testParity(): void {
    $values = [
      ['text' => "This is very good.\nWe delve, really.\nCafé, naïve and very.", 'format' => NULL],
      ['text' => 'Text is <em>very</em> good.', 'format' => 'basic_html'],
      ['text' => "<p>Fine.</p>\nText is very good.", 'format' => 'basic_html'],
      ['text' => '<p>Fine <code>very</code>.</p>', 'format' => 'basic_html'],
      ['text' => "Fine.\n\n```\nvery code\n```\n\nIt is `very` and very.", 'format' => 'markdown'],
      ['text' => '', 'format' => NULL],
    ];
    $server = $this->lintOnServer($values);
    $this->assertNotSame([], array_merge(...$server));
    $this->assertSame($server, $this->lintInBrowser($values));

    // The field's settings apply in the browser too. The section for
    // *.field_callout.md does not match the profile's name by itself, on
    // either side.
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'profile', 'field_callout')
      ->setThirdPartySetting('vale', 'min_level', Alert::WARNING)
      ->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Delve', 'level' => Alert::ERROR]])
      ->save();
    $values = [['text' => 'It is very good, we delve, really.', 'format' => 'markdown']];
    $server = $this->lintOnServer($values);
    $this->assertSame([['Demo.Very', Alert::ERROR], ['Demo.Delve', Alert::ERROR]], array_map(static fn (array $alert): array => [$alert['rule'], $alert['level']], $server[0]));
    $this->assertSame($server, $this->lintInBrowser($values));

    // A name the section matches turns Demo.Very off on both sides.
    $field->setThirdPartySetting('vale', 'profile', 'docs.field_callout')->save();
    $server = $this->lintOnServer($values);
    $this->assertSame([['Demo.Delve', Alert::ERROR]], array_map(static fn (array $alert): array => [$alert['rule'], $alert['level']], $server[0]));
    $this->assertSame($server, $this->lintInBrowser($values));
  }

}
