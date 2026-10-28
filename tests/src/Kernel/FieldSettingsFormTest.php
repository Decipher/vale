<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Hook\ValeFieldUiHooks;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Prose checks element on the field edit form.
 *
 * The functional FieldSettingsFormTest submits the form over HTTP. This
 * builds and validates the element directly.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FieldSettingsFormTest extends KernelTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'field_ui', 'filter', 'node', 'system', 'text', 'user', 'vale'];

  /**
   * The field being edited.
   */
  protected FieldConfig $field;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'node', 'vale']);
    $this->setUpVale();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    FieldStorageConfig::create(['field_name' => 'field_text', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    $field = FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'node', 'bundle' => 'page']);
    $field->setThirdPartySetting('vale', 'enabled', TRUE)
      ->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Very', 'Demo.Gone'])
      ->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Delve', 'level' => 'error']])
      ->save();
    $this->field = $field;
  }

  /**
   * Builds the Prose checks element for the field.
   *
   * @return array<string, mixed>
   *   The element.
   */
  protected function element(): array {
    $form = ['third_party_settings' => []];
    $form_state = new FormState();
    $form_state->setFormObject($this->container->get('entity_type.manager')->getFormObject('field_config', 'edit')->setEntity($this->field));
    $this->container->get('class_resolver')->getInstanceFromDefinition(ValeFieldUiHooks::class)->fieldConfigEditFormAlter($form, $form_state);
    return $form['third_party_settings']['vale'];
  }

  /**
   * Runs the element's validation on submitted values.
   *
   * @param array<string, mixed> $values
   *   The submitted values.
   * @param array<string, mixed>|null $element
   *   The element, or NULL to build it.
   *
   * @return array{0: mixed, 1: string[]}
   *   The stored value, and the error messages.
   */
  protected function submit(array $values, ?array $element = NULL): array {
    $element ??= $this->element();
    $element['#parents'] = ['third_party_settings', 'vale'];
    $element['profile']['#parents'] = ['third_party_settings', 'vale', 'profile'];
    $form_state = new FormState();
    $form_state->setValue(['third_party_settings', 'vale'], $values);
    $complete = ['third_party_settings' => ['vale' => $element]];
    $form_state->setCompleteForm($complete);
    ValeFieldUiHooks::validateSettings($element, $form_state);
    return [$form_state->getValue(['third_party_settings', 'vale']), array_map(strval(...), $form_state->getErrors())];
  }

  /**
   * Tests the element: a table per style, with the field's choices.
   */
  public function testElement(): void {
    $element = $this->element();
    $this->assertTrue($element['enabled']['#default_value']);
    $table = $element['rules']['Demo']['table'];
    $this->assertSame('off', $table['Very']['level']['#default_value']);
    $this->assertSame('error', $table['Delve']['level']['#default_value']);
    $this->assertSame('', $table['Really']['level']['#default_value']);
    $this->assertTrue($element['rules']['Demo']['#open']);
    $this->assertSame('Demo: 3 rules, 2 changed', (string) $element['rules']['Demo']['#title']);
    $this->assertSame("'…' is an AI tell.", $table['Delve']['rule']['#context']['description']);
    $this->assertStringContainsString('Demo.Gone', (string) $element['rules']['stale']['#markup']);

    // Vale unavailable: no table, and a note.
    $this->setSetting('vale_binary', NULL);
    $this->container->get('cache.vale')->deleteAll();
    $element = $this->element();
    $this->assertArrayHasKey('unavailable', $element);
    $this->assertArrayHasKey('none', $element['rules']);
  }

  /**
   * Tests turning the submitted choices into stored settings.
   */
  public function testValidate(): void {
    [$value, $errors] = $this->submit([
      'enabled' => 1,
      'min_level' => 'warning',
      'profile' => 'docs',
      'rules' => [
        'Demo' => [
          'table' => [
            'Very' => ['level' => ''],
            'Delve' => ['level' => 'off'],
            'Really' => ['level' => 'error'],
          ],
        ],
      ],
    ]);
    $this->assertSame([], $errors);
    $this->assertSame([
      'enabled' => TRUE,
      'min_level' => 'warning',
      'disabled_rules' => ['Demo.Delve'],
      'level_overrides' => [['rule' => 'Demo.Really', 'level' => 'error']],
      'profile' => 'docs',
    ], $value);

    [, $errors] = $this->submit(['enabled' => 1, 'profile' => '../docs']);
    $this->assertSame(['Use only letters, digits, dots, hyphens and underscores.'], array_values($errors));

    // A level that does not exist falls back to suggestion.
    [$value] = $this->submit(['enabled' => 1, 'min_level' => 'loud']);
    $this->assertSame('suggestion', $value['min_level']);

    // Not checked: nothing is stored.
    [$value] = $this->submit(['enabled' => 0]);
    $this->assertNull($value);
  }

  /**
   * Tests that the stored choices are kept while Vale is unavailable.
   */
  public function testValidateWithoutRules(): void {
    $this->setSetting('vale_binary', NULL);
    $this->container->get('cache.vale')->deleteAll();
    [$value] = $this->submit(['enabled' => 1, 'min_level' => 'error']);
    $this->assertSame(['Demo.Very', 'Demo.Gone'], $value['disabled_rules']);
    $this->assertSame([['rule' => 'Demo.Delve', 'level' => 'error']], $value['level_overrides']);
  }

}
