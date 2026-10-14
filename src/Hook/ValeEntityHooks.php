<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\field\FieldConfigInterface;
use Drupal\vale\FieldSettings;

/**
 * Adds the Vale constraints to entities and fields.
 *
 * The constraints run in entity validation, so every form, JSON:API and
 * REST write, and import that validates an entity is checked the same way.
 */
class ValeEntityHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entity_types
   *   The entity types.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    foreach ($entity_types as $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface && $entity_type->entityClassImplements(FieldableEntityInterface::class)) {
        $entity_type->addConstraint('ValeTree');
      }
    }
  }

  /**
   * Implements hook_entity_bundle_field_info_alter().
   *
   * @param array<string, \Drupal\Core\Field\FieldDefinitionInterface> $fields
   *   The bundle's fields.
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   */
  #[Hook('entity_bundle_field_info_alter')]
  public function entityBundleFieldInfoAlter(array &$fields, EntityTypeInterface $entity_type, string $bundle): void {
    foreach ($fields as $field) {
      if ($field instanceof FieldConfigInterface && FieldSettings::forField($field)->enabled) {
        $field->addConstraint('ValeText');
      }
    }
  }

}
