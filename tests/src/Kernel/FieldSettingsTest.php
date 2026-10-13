<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\FieldSettings;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the per-field settings and how they narrow the alerts.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FieldSettingsTest extends KernelTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'filter', 'node', 'system', 'text', 'user', 'vale'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['vale']);
    $this->setUpVale();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Adds a text field to the page bundle.
   *
   * @param string $name
   *   The field name.
   * @param array<string, mixed> $settings
   *   The Vale third-party settings, or an empty array for none.
   * @param string $type
   *   The field type.
   */
  protected function addField(string $name, array $settings = [], string $type = 'text_long'): FieldConfig {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
    ])->save();
    $field = FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => 'page',
    ]);
    foreach ($settings as $key => $value) {
      $field->setThirdPartySetting('vale', $key, $value);
    }
    $field->save();
    return FieldConfig::load('node.page.' . $name);
  }

  /**
   * Lints text as a field would be linted.
   *
   * @return string[]
   *   "<rule>:<level>" for each alert that is reported.
   */
  protected function lintAs(FieldConfig $field, string $text): array {
    $settings = FieldSettings::forField($field);
    $result = $this->container->get('vale.runner')->lint($text, 'md', $settings->name($field));
    return array_map(static fn (Alert $alert): string => $alert->rule . ':' . $alert->level, $settings->filter($result->alerts));
  }

  /**
   * Tests that settings are stored and read back.
   */
  public function testStoredSettings(): void {
    $field = $this->addField('field_callout', [
      'enabled' => TRUE,
      'min_level' => Alert::WARNING,
      'disabled_rules' => ['Demo.Very'],
      'level_overrides' => [['rule' => 'Demo.Delve', 'level' => Alert::ERROR]],
      'profile' => 'docs_callout',
    ]);

    $settings = FieldSettings::forField($field);
    $this->assertTrue($settings->enabled);
    $this->assertSame(Alert::WARNING, $settings->minLevel);
    $this->assertSame(['Demo.Very'], $settings->disabledRules);
    $this->assertSame(['Demo.Delve' => Alert::ERROR], $settings->levelOverrides);
    $this->assertSame('docs_callout', $settings->name($field));
    $this->assertContains('vale', $field->getDependencies()['module']);

    // A field with no settings is not checked, and is linted under its own
    // name.
    $plain = $this->addField('field_text');
    $this->assertFalse(FieldSettings::forField($plain)->enabled);
    $this->assertSame('node.page.field_text', FieldSettings::forField($plain)->name($plain));

    // A field that cannot hold prose is never checked.
    FieldStorageConfig::create(['field_name' => 'field_count', 'entity_type' => 'node', 'type' => 'integer'])->save();
    $count = FieldConfig::create(['field_name' => 'field_count', 'entity_type' => 'node', 'bundle' => 'page']);
    $count->setThirdPartySetting('vale', 'enabled', TRUE);
    $this->assertFalse(FieldSettings::forField($count)->enabled);
  }

  /**
   * Tests a rule turned off on one field.
   */
  public function testRuleTurnedOff(): void {
    $callout = $this->addField('field_callout', ['enabled' => TRUE, 'disabled_rules' => ['Demo.Very']]);
    $text = $this->addField('field_text', ['enabled' => TRUE]);

    $this->assertSame([], $this->lintAs($callout, 'This is very good.'));
    $this->assertSame(['Demo.Very:error'], $this->lintAs($text, 'This is very good.'));
  }

  /**
   * Tests a rule downgraded on one field.
   */
  public function testRuleDowngraded(): void {
    $section = $this->addField('field_section', [
      'enabled' => TRUE,
      'level_overrides' => [['rule' => 'Demo.Very', 'level' => Alert::WARNING]],
    ]);
    $this->assertSame(['Demo.Very:warning'], $this->lintAs($section, 'This is very good.'));
  }

  /**
   * Tests the minimum level, applied after the level changes.
   */
  public function testMinimumLevel(): void {
    $field = $this->addField('field_text', [
      'enabled' => TRUE,
      'min_level' => Alert::WARNING,
      'level_overrides' => [['rule' => 'Demo.Really', 'level' => Alert::WARNING]],
    ]);
    $this->assertSame(
      ['Demo.Very:error', 'Demo.Delve:warning', 'Demo.Really:warning'],
      $this->lintAs($field, "This is very good.\nWe delve, really."),
    );

    $strict = $this->addField('field_strict', ['enabled' => TRUE, 'min_level' => Alert::ERROR]);
    $this->assertSame(['Demo.Very:error'], $this->lintAs($strict, "This is very good.\nWe delve, really."));
  }

  /**
   * Tests that a field setting cannot add a rule.
   */
  public function testUnknownRules(): void {
    $settings = new FieldSettings(TRUE, Alert::SUGGESTION, ['Demo.Very', 'Demo.Nope'], ['Other.Rule' => Alert::ERROR, 'Demo.Delve' => Alert::ERROR]);
    $rules = $this->container->get('vale.environment')->rules();
    $this->assertSame(['Demo.Nope', 'Other.Rule'], $settings->unknownRules($rules));

    // An override of a rule Vale does not load matches no alert, so it can
    // never add one.
    $alerts = $this->container->get('vale.runner')->lint('Fine text.')->alerts;
    $this->assertSame([], $settings->filter($alerts));
  }

}
