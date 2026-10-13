<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\content_moderation\Plugin\WorkflowType\ContentModerationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;

/**
 * Decides whether an entity is being published by the save in progress.
 *
 * Validation runs before presave, where Content Moderation sets the status.
 * So for a moderated entity the target moderation state decides, not
 * isPublished().
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
    if ($this->moderation && $entity instanceof ContentEntityInterface && $this->moderation->isModeratedEntity($entity)) {
      $workflow = $this->moderation->getWorkflowForEntity($entity);
      if ($workflow) {
        $type = $workflow->getTypePlugin();
        $state_id = $entity->hasField('moderation_state') ? $entity->get('moderation_state')->value : NULL;
        $state = is_string($state_id) && $type->hasState($state_id) ? $type->getState($state_id) : NULL;
        if ($state === NULL) {
          $state = $type instanceof ContentModerationInterface ? $type->getInitialState($entity) : $type->getInitialState();
        }
        if ($state instanceof ContentModerationState) {
          return $state->isPublishedState();
        }
      }
    }
    if ($entity instanceof EntityPublishedInterface) {
      return $entity->isPublished();
    }
    return TRUE;
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
   *   TRUE for an entity that has a parent entity.
   */
  public function isComponent(EntityInterface $entity): bool {
    return method_exists($entity, 'getParentEntity');
  }

}
