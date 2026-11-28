<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Functional;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the "Show findings while editing" setting on the form display.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FormDisplaySettingTest extends BrowserTestBase {

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
    FieldConfig::create(['field_name' => 'field_text', 'entity_type' => 'node', 'bundle' => 'page'])
      ->setThirdPartySetting('vale', 'enabled', TRUE)
      ->save();
    $this->container->get('entity_display.repository')
      ->getFormDisplay('node', 'page')
      ->setComponent('field_text', ['type' => 'text_textarea'])
      ->save();
    $this->drupalLogin($this->asAccount($this->drupalCreateUser([
      'administer content types',
      'administer node fields',
      'administer node form display',
      'create page content',
      'use vale check',
    ])));
    $this->setUpVale();
  }

  /**
   * Gives the stored Vale settings of the field's widget.
   *
   * @return array<string, mixed>
   *   The third-party settings.
   *
   * @phpstan-impure
   */
  protected function storedSettings(): array {
    $this->container->get('entity_type.manager')->getStorage('entity_form_display')->resetCache();
    $display = EntityFormDisplay::load('node.page.default');
    $this->assertInstanceOf(EntityFormDisplay::class, $display);
    return $display->getComponent('field_text')['third_party_settings']['vale'] ?? [];
  }

  /**
   * Tests turning the setting off, and what the edit form then shows.
   */
  public function testSetting(): void {
    $session = $this->assertSession();
    $this->drupalGet('node/add/page');
    $session->elementExists('css', 'textarea[name="field_text[0][value]"][data-vale-findings]');

    $this->drupalGet('admin/structure/types/manage/page/form-display');
    $session->pageTextContains('Findings shown while editing');
    $this->submitForm([], 'field_text_settings_edit');
    $session->checkboxChecked('fields[field_text][settings_edit_form][third_party_settings][vale][show_findings]');
    $this->submitForm([
      'fields[field_text][settings_edit_form][third_party_settings][vale][show_findings]' => FALSE,
    ], 'Update');
    $this->submitForm([], 'Save');
    $session->pageTextContains('Findings hidden while editing');
    $this->assertSame(['show_findings' => FALSE], $this->storedSettings());

    $this->drupalGet('node/add/page');
    $session->elementExists('css', 'textarea[name="field_text[0][value]"]');
    $session->elementNotExists('css', '[data-vale-findings]');
  }

}
