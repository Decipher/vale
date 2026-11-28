<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\File\FileSystemInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the vale/browser library and what it is given.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class BrowserLibraryTest extends KernelTestBase {

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
    // A built site may have a real browser build in libraries/vale.
    $this->setSetting('vale_wasm', 'public://missing');
    $this->createValeContentModel();
  }

  /**
   * Tests the library's definition.
   */
  public function testLibrary(): void {
    $library = $this->container->get('library.discovery')->getLibraryByName('vale', 'browser');
    $this->assertIsArray($library);
    $files = array_map(static fn (array $file): string => basename($file['data']), $library['js']);
    $this->assertSame(['vale-common.js', 'vale-browser.js'], $files);
    $this->assertContains('core/drupal', $library['dependencies']);
    $this->assertContains('core/drupalSettings', $library['dependencies']);
    // The worker loads the engine itself, so the page never does.
    $module = $this->container->get('extension.list.module')->getPath('vale');
    foreach (['vale-browser.js', 'vale-common.js', 'vale-engine.js', 'vale-worker.js'] as $file) {
      $this->assertFileExists($this->root . '/' . $module . '/js/' . $file);
    }
  }

  /**
   * Tests the settings an editor plugin attaches for a field.
   */
  public function testAttachments(): void {
    $this->config('vale.settings')->set('formats', ['markdown' => 'md'])->save();
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'min_level', Alert::WARNING)
      ->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Really'])
      ->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Delve', 'level' => Alert::ERROR]])
      ->save();

    $attachments = $this->container->get('vale.browser')->attachments($field);
    $this->assertSame(['vale/browser'], $attachments['library']);
    $vale = $attachments['drupalSettings']['vale'];
    $this->assertSame(['markdown' => 'md'], $vale['formats']);
    $this->assertSame('html', $vale['defaultExtension']);
    $this->assertEquals([
      'name' => 'node.page.field_text',
      'plain' => FALSE,
      'minLevel' => Alert::WARNING,
      'disabledRules' => ['Demo.Really'],
      'levelOverrides' => (object) ['Demo.Delve' => Alert::ERROR],
    ], $vale['fields']['node.page.field_text']);

    // The routes, with LANGCODE where the library puts the langcode.
    $urls = $vale['browser'];
    $this->assertStringEndsWith('/vale/config/LANGCODE', $urls['config']);
    // The zips are asked for by the styles' fingerprint, so a browser
    // keeps them until the styles change.
    $kept = '?v=' . $this->container->get('vale.environment')->fingerprint();
    $this->assertStringEndsWith('/vale/models/LANGCODE' . $kept, $urls['models']);
    $this->assertStringEndsWith('/vale/styles' . $kept, $urls['styles']);
    // No language of the site has a model, so the library asks for none.
    $this->assertSame([], $urls['modelLanguages']);
    $this->assertStringEndsWith('/vale/check', $urls['check']);
    $this->assertStringEndsWith('/session/token', $urls['token']);
    // The worker loads its scripts itself, so each is versioned, as the
    // browser build is. A checkout has no version, so it gets core's query
    // string, which changes when the caches are cleared.
    $query = '?v=' . $this->container->get('asset.query_string')->get();
    $this->assertStringEndsWith('/js/vale-worker.js' . $query, $urls['worker']);
    $this->assertStringEndsWith('/js/vale-engine.js' . $query, $urls['engine']);
    $this->assertStringEndsWith('/js/vale-common.js' . $query, $urls['common']);
    // With no browser build, the library checks values on the server.
    $this->assertNull($urls['module']);
    $this->assertNull($urls['loader']);

    // With one, it loads it from where it is.
    $directory = 'public://vale';
    $this->container->get('file_system')->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY);
    file_put_contents($directory . '/vale.wasm', "\nmod\tgithub.com/vale-cli/vale/v3\tv3.28.1\n");
    file_put_contents($directory . '/wasm_exec.js', '');
    $this->setSetting('vale_wasm', $directory);
    $urls = $this->container->get('vale.browser')->attachments($field)['drupalSettings']['vale']['browser'];
    $this->assertStringEndsWith('/vale/vale.wasm?v=3.28.1', (string) $urls['module']);
    $this->assertStringEndsWith('/vale/wasm_exec.js?v=3.28.1', (string) $urls['loader']);

    // A field Vale does not check gets no settings.
    $code = FieldConfig::load('node.page.field_code');
    $this->assertInstanceOf(FieldConfig::class, $code);
    $this->assertArrayNotHasKey('fields', $this->container->get('vale.browser')->attachments($code)['drupalSettings']['vale']);
  }

}
