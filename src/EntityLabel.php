<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Names an entity in a finding without loading anything else.
 *
 * A component's own label usually comes from its host: Paragraph::label()
 * loads the parent and every revision it references. Doing that during
 * validation is slow, and inside a workspace it disturbed core's workspace
 * checks on the paragraph being saved. So a component is named by its
 * bundle.
 */
final class EntityLabel {

  /**
   * Gives the name of an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The bundle's label for a component, such as "Docs text", or the
   *   entity's label otherwise.
   */
  public static function of(FieldableEntityInterface $entity): string {
    if (!method_exists($entity, 'getParentEntity')) {
      return (string) $entity->label();
    }
    return self::bundle($entity);
  }

  /**
   * Gives the label of an entity's bundle.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string
   *   The bundle's label, or its id when it has no label.
   */
  public static function bundle(FieldableEntityInterface $entity): string {
    $bundle_key = $entity->getEntityType()->getKey('bundle');
    $bundle = $bundle_key && $entity->hasField($bundle_key) ? $entity->get($bundle_key)->entity : NULL;
    return $bundle instanceof EntityInterface ? (string) $bundle->label() : $entity->bundle();
  }

}
