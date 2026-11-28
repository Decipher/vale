<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\ckeditor5\Plugin\Editor\CKEditor5;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormState;
use Drupal\editor\Entity\Editor;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\filter\Entity\FilterFormat;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\SchemaCheckTestTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Hook\ValeWidgetHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationInterface;

/**
 * Tests what shows findings while an author edits.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class EditingFindingsTest extends KernelTestBase {

  use SchemaCheckTestTrait;
  use UserCreationTrait;
  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'ckeditor5',
    'editor',
    'field',
    'filter',
    'node',
    'system',
    'text',
    'user',
    'vale',
  ];

  /**
   * The widget for each field on the form.
   */
  protected const array WIDGETS = [
    'field_text' => 'text_textarea',
    'field_line' => 'text_textfield',
    'field_body' => 'text_textarea_with_summary',
    'field_title_text' => 'string_textfield',
    'field_note' => 'string_textarea',
    'field_code' => 'text_textarea',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'node', 'system', 'vale']);
    $this->setUpVale();
    $this->setSetting('vale_wasm', 'public://missing');
    // Drupal 11.5 moves the text with summary field type to its own module.
    if ($this->container->get('extension.list.module')->exists('text_with_summary')) {
      $this->enableModules(['text_with_summary']);
    }
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $types = [
      'field_text' => 'text_long',
      'field_line' => 'text',
      'field_body' => 'text_with_summary',
      'field_title_text' => 'string',
      'field_note' => 'string_long',
      'field_code' => 'text_long',
    ];
    $display = EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $types = array_intersect_key($types, $this->widgets());
    foreach ($types as $name => $type) {
      FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $type])->save();
      $field = FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => 'page', 'label' => $name]);
      // field_code is not checked by Vale.
      if ($name !== 'field_code') {
        $field->setThirdPartySetting('vale', 'enabled', TRUE);
      }
      $field->save();
      $display->setComponent($name, ['type' => static::WIDGETS[$name]]);
    }
    $display->save();
    // User 1 has every permission, so the tests use another user.
    $this->createUser();
  }

  /**
   * Gives the widgets to test, by field name.
   *
   * Drupal 12 has no text with summary field type, so that field is left out
   * where core lacks it.
   *
   * @return array<string, string>
   *   The widget plugin IDs, by field name.
   */
  protected function widgets(): array {
    $widgets = static::WIDGETS;
    if (!$this->container->get('plugin.manager.field.field_type')->hasDefinition('text_with_summary')) {
      unset($widgets['field_body']);
    }
    return $widgets;
  }

  /**
   * Gives the widget of a field on the default form display.
   *
   * @param string $name
   *   The field name.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   The widget.
   */
  protected function widget(string $name): WidgetInterface {
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    $widget = $display->getRenderer($name);
    $this->assertInstanceOf(WidgetInterface::class, $widget);
    return $widget;
  }

  /**
   * Gives the Vale part of a widget's settings form.
   *
   * @param string $name
   *   The field name.
   *
   * @return array<string, mixed>|null
   *   The element, or NULL when Vale adds none.
   */
  protected function settingsForm(string $name): ?array {
    $field = FieldConfig::load('node.page.' . $name);
    $this->assertInstanceOf(FieldConfig::class, $field);
    $widget = $this->widget($name);
    $elements = [];
    $form_state = new FormState();
    // Field UI calls the hook this way.
    $this->container->get('module_handler')->invokeAllWith(
      'field_widget_third_party_settings_form',
      function (callable $hook, string $module) use (&$elements, $widget, $field, $form_state): void {
        $elements[$module] = $hook($widget, $field, 'default', [], $form_state);
      },
    );
    return $elements['vale'] ?? NULL;
  }

  /**
   * Gives a widget's summary on the form display.
   *
   * @param string $name
   *   The field name.
   *
   * @return string[]
   *   The summary lines.
   */
  protected function summary(string $name): array {
    $widget = $this->widget($name);
    $summary = $widget->settingsSummary();
    $context = [
      'widget' => $widget,
      'field_definition' => FieldConfig::load('node.page.' . $name),
      'form_mode' => 'default',
    ];
    $this->container->get('module_handler')->alter('field_widget_settings_summary', $summary, $context);
    return array_map(strval(...), $summary);
  }

  /**
   * Tests the setting on the form display.
   */
  public function testFormDisplaySetting(): void {
    foreach (array_keys($this->widgets()) as $name) {
      $element = $this->settingsForm($name);
      if ($name === 'field_code') {
        $this->assertEmpty($element, 'A field Vale does not check gets no setting.');
        $this->assertNotContains('Findings shown while editing', $this->summary($name));
        continue;
      }
      $this->assertIsArray($element, $name);
      $this->assertSame('checkbox', $element['show_findings']['#type']);
      $this->assertSame('Show findings while editing', (string) $element['show_findings']['#title']);
      // It is on by default.
      $this->assertTrue($element['show_findings']['#default_value'], $name);
      $this->assertContains('Findings shown while editing', $this->summary($name), $name);
    }

    // A form mode turns it off.
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    $display->setComponent('field_text', [
      'type' => 'text_textarea',
      'third_party_settings' => ['vale' => ['show_findings' => FALSE]],
    ])->save();
    $this->assertFalse($this->settingsForm('field_text')['show_findings']['#default_value'] ?? NULL);
    $this->assertContains('Findings hidden while editing', $this->summary('field_text'));
    // The setting has a schema, and the display depends on the module.
    $this->assertConfigSchemaByName('core.entity_form_display.node.page.default');
    $this->assertSame(['show_findings' => FALSE], $this->config('core.entity_form_display.node.page.default')->get('content.field_text.third_party_settings.vale'));
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    $this->assertContains('vale', $display->getDependencies()['module']);
  }

  /**
   * Gives the entity form for a node.
   *
   * @param \Drupal\node\Entity\Node $node
   *   The node.
   *
   * @return array<string, mixed>
   *   The built form.
   */
  protected function nodeForm(Node $node): array {
    return $this->container->get('entity.form_builder')->getForm($node);
  }

  /**
   * Gives what a widget tells the browser about its value.
   *
   * @param array<string, mixed> $element
   *   The element that holds the value.
   *
   * @return array<string, mixed>|null
   *   The decoded data-vale-findings attribute, or NULL.
   */
  protected function findingsData(array $element): ?array {
    $value = $element['#attributes']['data-vale-findings'] ?? NULL;
    return is_string($value) ? json_decode($value, TRUE, 512, JSON_THROW_ON_ERROR) : NULL;
  }

  /**
   * Tests what each widget attaches for the browser.
   */
  public function testWidgetAttachments(): void {
    $this->setCurrentUser($this->asAccount($this->createUser(['use vale check', 'create page content', 'edit any page content'])));
    $node = Node::create(['type' => 'page', 'title' => 'Page']);
    $form = $this->nodeForm($node);

    foreach (array_keys($this->widgets()) as $name) {
      $widget = $form[$name]['widget'][0];
      $value = $widget['value'];
      $data = $this->findingsData($value);
      $libraries = $widget['#attached']['library'] ?? [];
      if ($name === 'field_code') {
        $this->assertNull($data, 'A field Vale does not check is not linted.');
        $this->assertNotContains('vale/findings', $libraries);
        continue;
      }
      // The same text, format and language as the save.
      $this->assertSame([
        'entityType' => 'node',
        'bundle' => 'page',
        'field' => $name,
        'langcode' => $node->language()->getId(),
        'id' => $node->uuid(),
        'entityId' => NULL,
        'delta' => 0,
      ], $data, $name);
      $this->assertContains('vale/findings', $libraries, $name);
      $this->assertContains('vale/browser', $libraries, $name);
      $this->assertArrayHasKey('node.page.' . $name, $widget['#attached']['drupalSettings']['vale']['fields'], $name);
    }
    // The summary waits for #17.
    if (isset($form['field_body'])) {
      $this->assertNull($this->findingsData($form['field_body']['widget'][0]['summary']));
    }

    // An entity that exists is checked as itself.
    $node->save();
    $data = $this->findingsData($this->nodeForm($node)['field_text']['widget'][0]['value']);
    $this->assertSame((int) $node->id(), (int) ($data['entityId'] ?? 0));

    // A form mode with the setting off shows nothing.
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    $display->setComponent('field_text', [
      'type' => 'text_textarea',
      'third_party_settings' => ['vale' => ['show_findings' => FALSE]],
    ])->save();
    $widget = $this->nodeForm($node)['field_text']['widget'][0];
    $this->assertNull($this->findingsData($widget['value']));
    $this->assertNotContains('vale/findings', $widget['#attached']['library'] ?? []);

    // The hook leaves alone what is no widget of a field, and a widget whose
    // element holds no text.
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeWidgetHooks::class);
    $element = ['#type' => 'textfield'];
    $hooks->singleElementFormAlter($element, new FormState(), []);
    $this->assertSame(['#type' => 'textfield'], $element);
    $element = ['#type' => 'container'];
    $hooks->singleElementFormAlter($element, new FormState(), [
      'widget' => $this->widget('field_note'),
      'items' => $node->get('field_note'),
      'delta' => 0,
    ]);
    $this->assertArrayNotHasKey('#attributes', $element);
    $this->assertArrayNotHasKey('#attached', $element);
  }

  /**
   * Tests that an author who cannot use the check endpoint gets nothing.
   */
  public function testNoPermission(): void {
    $this->setCurrentUser($this->asAccount($this->createUser(['create page content'])));
    $form = $this->nodeForm(Node::create(['type' => 'page', 'title' => 'Page']));
    $widget = $form['field_text']['widget'][0];
    $this->assertNull($this->findingsData($widget['value']));
    $this->assertNotContains('vale/findings', $widget['#attached']['library'] ?? []);
    $this->assertContains('user.permissions', $widget['#cache']['contexts'] ?? []);
  }

  /**
   * Tests the libraries.
   */
  public function testLibraries(): void {
    $discovery = $this->container->get('library.discovery');
    $module = $this->container->get('extension.list.module')->getPath('vale');
    $expected = [
      'text' => ['vale-text.js'],
      'findings' => ['vale-findings.js'],
      'ckeditor5.findings' => ['vale-card.js', 'valeFindings.js'],
    ];
    foreach ($expected as $name => $files) {
      $library = $discovery->getLibraryByName('vale', $name);
      $this->assertIsArray($library, $name);
      $this->assertSame($files, array_map(static fn (array $file): string => basename($file['data']), $library['js']), $name);
      foreach ($library['js'] as $file) {
        $this->assertFileExists($this->root . '/' . $file['data']);
      }
      foreach ($library['css'] as $file) {
        $this->assertFileExists($this->root . '/' . $file['data']);
      }
    }
    $findings = $discovery->getLibraryByName('vale', 'findings');
    $this->assertIsArray($findings);
    $this->assertContains('vale/browser', $findings['dependencies']);
    $this->assertContains('vale/text', $findings['dependencies']);
    $plugin = $discovery->getLibraryByName('vale', 'ckeditor5.findings');
    $this->assertIsArray($plugin);
    $this->assertContains('core/ckeditor5', $plugin['dependencies']);
    $this->assertContains('vale/browser', $plugin['dependencies']);
    $this->assertFileExists($this->root . '/' . $module . '/js/ckeditor5_plugins/valeFindings.js');
  }

  /**
   * Tests the CKEditor 5 plugin and its toolbar button.
   */
  public function testCkeditorPlugin(): void {
    // CKEditor 5 reads the default theme's settings.
    $this->container->get('theme_installer')->install(['stark']);
    $manager = $this->container->get('plugin.manager.ckeditor5.plugin');
    $definition = $manager->getDefinition('vale_findings');
    $this->assertSame(['valeFindings.ValeFindings'], $definition->getCKEditor5Plugins());
    $this->assertSame('vale/ckeditor5.findings', $definition->getLibrary());
    $this->assertSame('vale/ckeditor5.admin', $definition->getAdminLibrary());
    $this->assertSame(['valeFindings'], array_keys($definition->getToolbarItems()));
    $this->assertFalse($definition->hasElements());

    // A text format turns it on with its toolbar button.
    FilterFormat::create(['format' => 'rich', 'name' => 'Rich', 'filters' => []])->save();
    $editor = Editor::create([
      'editor' => 'ckeditor5',
      'format' => 'rich',
      'image_upload' => ['status' => FALSE],
      'settings' => ['toolbar' => ['items' => ['bold', 'valeFindings']], 'plugins' => []],
    ]);
    $editor->save();
    $format = FilterFormat::load('rich');
    $this->assertInstanceOf(FilterFormat::class, $format);
    $violations = array_map(static fn (ConstraintViolationInterface $violation): string => (string) $violation->getMessage(), iterator_to_array(CKEditor5::validatePair($editor, $format)));
    $this->assertSame([], $violations);
    $this->assertArrayHasKey('vale_findings', $manager->getEnabledDefinitions($editor));
    $this->assertContains('valeFindings.ValeFindings', $manager->getCKEditor5PluginConfig($editor)['plugins']);
    $this->assertContains('vale/ckeditor5.findings', $manager->getEnabledLibraries($editor));

    // Without the button, the plugin is off.
    $editor->setSettings(['toolbar' => ['items' => ['bold']], 'plugins' => []])->save();
    $this->assertArrayNotHasKey('vale_findings', $manager->getEnabledDefinitions($editor));
  }

}
