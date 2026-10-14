<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Traits;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\ParagraphsType;

/**
 * Builds a docs-like content model: pages that hold text paragraphs.
 *
 * - node.page.field_text: opted-in text.
 * - node.page.field_code: text that is not opted in.
 * - node.page.field_content: paragraphs.
 * - paragraph.docs_text.field_text: opted-in text.
 */
trait ValeContentTrait {

  /**
   * Creates the page type, the docs_text paragraph type and their fields.
   */
  protected function createValeContentModel(): void {
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    ParagraphsType::create(['id' => 'docs_text', 'label' => 'Docs text'])->save();

    $this->createValeTextField('node', 'page', 'field_text', TRUE);
    $this->createValeTextField('node', 'page', 'field_code', FALSE);
    $this->createValeTextField('paragraph', 'docs_text', 'field_text', TRUE);

    FieldStorageConfig::create([
      'field_name' => 'field_content',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'cardinality' => -1,
      'settings' => ['target_type' => 'paragraph'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_content',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Content',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['docs_text' => 'docs_text']],
      ],
    ])->save();
  }

  /**
   * Shows the page's text fields on its edit form.
   */
  protected function showValeFieldsOnForm(): void {
    $display = EntityFormDisplay::load('node.page.default') ?? EntityFormDisplay::create([
      'targetEntityType' => 'node',
      'bundle' => 'page',
      'mode' => 'default',
      'status' => TRUE,
    ]);
    $display
      ->setComponent('field_text', ['type' => 'text_textarea'])
      ->setComponent('field_code', ['type' => 'text_textarea'])
      ->setComponent('field_content', ['type' => 'paragraphs'])
      ->save();
    EntityFormDisplay::create([
      'targetEntityType' => 'paragraph',
      'bundle' => 'docs_text',
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('field_text', ['type' => 'text_textarea'])->save();
  }

  /**
   * Creates a long text field, opted in to Vale or not.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $name
   *   The field name.
   * @param bool $enabled
   *   Whether the field is checked.
   */
  protected function createValeTextField(string $entity_type, string $bundle, string $name, bool $enabled): void {
    if (!FieldStorageConfig::loadByName($entity_type, $name)) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => $entity_type,
        'type' => 'text_long',
      ])->save();
    }
    $field = FieldConfig::create([
      'field_name' => $name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'label' => ucfirst(str_replace(['field_', '_'], ['', ' '], $name)),
    ]);
    if ($enabled) {
      $field->setThirdPartySetting('vale', 'enabled', TRUE);
    }
    $field->save();
  }

}
