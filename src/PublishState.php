<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * Decides whether an entity is being published by the save in progress.
 *
 * Setting an entity's moderation state also sets its published status, so
 * isPublished() is correct during validation. The exception is a new entity
 * saved without a state: Content Moderation applies the initial state in
 * presave, after validation. So for that entity the state decides.
 */
class PublishState {

  /**
   * Constructs a PublishState.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface|null $moderation
   *   The moderation information service, when Content Moderation is on.
   */
  public function __construct(
    protected ?ModerationInformationInterface $moderation = NULL,
  ) {}

  /**
   * Says whether a save would publish an entity.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE when the entity would be published, or when it has no published
   *   state at all.
   */
  public function isBeingPublished(EntityInterface $entity): bool {
    if (!$entity instanceof EntityPublishedInterface) {
      return TRUE;
    }
    if ($entity->isNew() && $entity instanceof ContentEntityInterface && $this->moderation?->isModeratedEntity($entity)) {
      // Without a state set, the field computes the initial one.
      $state_id = $entity->get('moderation_state')->value;
      $type = $this->moderation->getWorkflowForEntity($entity)?->getTypePlugin();
      if (is_string($state_id) && $type?->hasState($state_id)) {
        $state = $type->getState($state_id);
        if ($state instanceof ContentModerationState) {
          return $state->isPublishedState();
        }
      }
    }
    return $entity->isPublished();
  }

  /**
   * Says whether an entity is a component of another, such as a paragraph.
   *
   * A component is published by its host. Its own save is never the moment
   * of publishing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity.
   *
   * @return bool
   *   TRUE for an entity of a component type.
   */
  public function isComponent(EntityInterface $entity): bool {
    return self::isComponentType($entity->getEntityType());
  }

  /**
   * Says whether an entity type holds components.
   *
   * Entity Reference Revisions marks such a type with the
   * "entity_revision_parent_id_field" key, as Paragraphs does.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   *
   * @return bool
   *   TRUE when entities of the type belong to a parent.
   */
  public static function isComponentType(EntityTypeInterface $entity_type): bool {
    return (bool) $entity_type->get('entity_revision_parent_id_field');
  }

}
