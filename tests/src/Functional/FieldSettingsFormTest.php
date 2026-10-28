<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Vale settings on the field edit form.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FieldSettingsFormTest extends BrowserTestBase {

  use ValeTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field_ui', 'node', 'text', 'vale'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalCreateContentType(['type' => 'page']);
    FieldStorageConfig::create(['field_name' => 'field_text', 'entity_type' => 'node', 'type' => 'text_long'])->save();
    FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'node', 'bundle' => 'page'])->save();
    $this->drupalLogin($this->asAccount($this->drupalCreateUser(['administer content types', 'administer node fields'])));
    $this->setUpVale();
  }

  /**
   * Gives the field's stored Vale settings.
   *
   * @return array<string, mixed>
   *   The third-party settings.
   *
   * @phpstan-impure
   */
  protected function storedSettings(): array {
    $this->container->get('entity_type.manager')->getStorage('field_config')->resetCache();
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    return $field->getThirdPartySettings('vale');
  }

  /**
   * Tests saving and clearing the settings.
   */
  public function testSettingsForm(): void {
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->assertSession()->pageTextContains('Prose checks');
    $this->assertSession()->pageTextContains('Lint as this name instead of node.page.field_text.');
    $this->assertSession()->pageTextContains('Demo: 3 rules');
    $this->assertSession()->pageTextContains('Intensifiers weaken prose.');
    // A rule without a description shows its message, without placeholders.
    $this->assertSession()->pageTextContains("'…' is an AI tell.");

    $this->submitForm([
      'third_party_settings[vale][enabled]' => TRUE,
      'third_party_settings[vale][min_level]' => 'warning',
      'third_party_settings[vale][rules][Demo][table][Very][level]' => 'off',
      'third_party_settings[vale][rules][Demo][table][Delve][level]' => 'error',
      'third_party_settings[vale][profile]' => 'docs_text',
    ], 'edit-submit');
    $this->assertSame([
      'enabled' => TRUE,
      'min_level' => 'warning',
      'disabled_rules' => ['Demo.Very'],
      'level_overrides' => [['rule' => 'Demo.Delve', 'level' => 'error']],
      'profile' => 'docs_text',
    ], $this->storedSettings());

    // The form shows the choices, and how many rules are changed.
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->assertSession()->pageTextContains('Demo: 3 rules, 2 changed');
    $this->assertSession()->fieldValueEquals('third_party_settings[vale][rules][Demo][table][Very][level]', 'off');
    $this->assertSession()->fieldValueEquals('third_party_settings[vale][rules][Demo][table][Delve][level]', 'error');
    $this->assertSession()->fieldValueEquals('third_party_settings[vale][rules][Demo][table][Really][level]', '');

    // Back to the default.
    $this->submitForm(['third_party_settings[vale][rules][Demo][table][Very][level]' => ''], 'edit-submit');
    $this->assertSame([], $this->storedSettings()['disabled_rules']);

    // A profile that is not a name is refused.
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->submitForm(['third_party_settings[vale][profile]' => '../docs'], 'edit-submit');
    $this->assertSession()->pageTextContains('Use only letters, digits, dots, hyphens and underscores.');

    // A field that is no longer checked keeps no settings.
    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->submitForm(['third_party_settings[vale][enabled]' => FALSE], 'edit-submit');
    $this->assertSame([], $this->storedSettings());
  }

  /**
   * Tests a stored rule that Vale no longer loads.
   */
  public function testStaleRule(): void {
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'enabled', TRUE)
      ->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Gone', 'Demo.Very'])
      ->save();

    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->assertSession()->pageTextContains('Vale no longer loads these rules, so saving removes them: Demo.Gone.');
    $this->submitForm([], 'edit-submit');
    $this->assertSame(['Demo.Very'], $this->storedSettings()['disabled_rules']);
  }

  /**
   * Tests that the rule settings are kept while Vale is unavailable.
   */
  public function testUnavailableKeepsRules(): void {
    $field = FieldConfig::load('node.page.field_text');
    $this->assertInstanceOf(FieldConfig::class, $field);
    $field->setThirdPartySetting('vale', 'enabled', TRUE)
      ->setThirdPartySetting('vale', 'disabled_rules', ['Demo.Very'])
      ->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Delve', 'level' => 'error']])
      ->save();
    $this->setValeSetting('vale_binary', NULL);

    $this->drupalGet('admin/structure/types/manage/page/fields/node.page.field_text');
    $this->assertSession()->pageTextContains('No rules are loaded, so the rule settings are kept as they are.');
    $this->submitForm(['third_party_settings[vale][min_level]' => 'error'], 'edit-submit');
    $stored = $this->storedSettings();
    $this->assertSame('error', $stored['min_level']);
    $this->assertSame(['Demo.Very'], $stored['disabled_rules']);
    $this->assertSame([['rule' => 'Demo.Delve', 'level' => 'error']], $stored['level_overrides']);
  }

}
