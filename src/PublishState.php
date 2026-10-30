<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\content_moderation\ContentModerationState;
use Drupal\content_moderation\ModerationInformationInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

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
   * How deep components are followed up to their host.
   */
  private const int MAX_DEPTH = 10;

  /**
   * Constructs a PublishState.
   *
   * @param \Drupal\content_moderation\ModerationInformationInterface|null $moderation
   *   The moderation information service, when Content Moderation is on.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   * @param \Drupal\workspaces\WorkspaceInformationInterface|null $workspaceInformation
   *   The workspace information service. Drupal 11.3 offers it; earlier
   *   versions have none, and there every publishable revision is tracked.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface|null $entityTypeManager
   *   The entity type manager, to load the revisions a host shows.
   */
  public function __construct(
    protected ?ModerationInformationInterface $moderation = NULL,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?WorkspaceInformationInterface $workspaceInformation = NULL,
    protected ?EntityTypeManagerInterface $entityTypeManager = NULL,
  ) {}

  /**
   * Whether the request builds a form.
   */
  protected bool $inForm = FALSE;

  /**
   * Notes that the request builds a form for a host.
   *
   * Such a form validates a component for its host, as the Paragraphs widget
   * and the Layout Paragraphs component form do. The host's save then decides
   * what is published.
   */
  public function setInForm(): void {
    $this->inForm = TRUE;
  }

  /**
   * Says whether a save publishes, in the sense of publish-only mode.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE when the save makes the entity's text public.
   */
  public function isPublishingSave(EntityInterface $entity): bool {
    if ($this->isComponent($entity)) {
      return $this->isLiveRewrite($entity);
    }
    return $this->isBeingPublished($entity);
  }

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
    if ($this->isWorkspaceDraft($entity)) {
      // A save the workspace tracks publishes nothing live: the revision it
      // tracks stays unseen until the workspace is published, and that
      // publish, not this save, is the publishing moment. The workspace
      // publish check covers its errors. Types a workspace does not track
      // still save straight to Live, so they keep their publishing refusal.
      return FALSE;
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
   * Says whether a save of the entity drafts into the active workspace.
   *
   * A save of content a workspace tracks lands in the workspace, not Live.
   * Types it does not track, such as those an ignored workspace handler
   * keeps out, still save straight to Live. Drupal before 11.3, which has
   * no workspace information service, tracks every publishable revision.
   *
   * @param \Drupal\Core\Entity\EntityPublishedInterface $entity
   *   The entity being saved.
   *
   * @return bool
   *   TRUE when a workspace is active and it tracks the entity.
   */
  protected function isWorkspaceDraft(EntityPublishedInterface $entity): bool {
    if (!$this->workspaceManager?->hasActiveWorkspace()) {
      return FALSE;
    }
    if ($this->workspaceInformation instanceof WorkspaceInformationInterface) {
      return $this->workspaceInformation->isEntitySupported($entity);
    }
    return $entity->getEntityType()->isRevisionable();
  }

  /**
   * Says whether a component's own save changes published text.
   *
   * A component is usually published by its host. But a component saved by
   * itself, such as over JSON:API, often makes no new revision. Then the save
   * changes the revision that the host's live revision shows. That save
   * publishes the text.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The component being saved.
   *
   * @return bool
   *   TRUE when the save changes a revision that published content shows.
   */
  protected function isLiveRewrite(EntityInterface $entity): bool {
    if ($this->inForm || !$entity instanceof ContentEntityInterface || $entity->isNew() || $entity->isNewRevision()) {
      return FALSE;
    }
    if ($entity instanceof EntityPublishedInterface && $this->isWorkspaceDraft($entity)) {
      return FALSE;
    }
    // Find the host. A component can be inside another component, so this
    // follows the parents up. Only their ids matter here: the parents'
    // default revisions need not be the ones the host shows.
    $host = $entity;
    for ($depth = 0; $this->isComponent($host); $depth++) {
      $host = $depth < self::MAX_DEPTH && method_exists($host, 'getParentEntity') ? $host->getParentEntity() : NULL;
      if (!$host instanceof ContentEntityInterface) {
        return FALSE;
      }
    }
    if ($host instanceof EntityPublishedInterface && !$host->isPublished()) {
      return FALSE;
    }
    // The live revision of the host must hold the revision being saved.
    return $this->holds($host, $entity->getEntityTypeId(), (string) $entity->getLoadedRevisionId(), $entity->language()->getId(), 0);
  }

  /**
   * Says whether an entity holds a component revision, at any depth.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity, in the revision to search.
   * @param string $entity_type_id
   *   The component's entity type.
   * @param string $revision_id
   *   The component's revision id.
   * @param string $langcode
   *   The component's language.
   * @param int $depth
   *   How deep the entity is.
   *
   * @return bool
   *   TRUE when the revision is in the entity's components.
   */
  protected function holds(ContentEntityInterface $entity, string $entity_type_id, string $revision_id, string $langcode, int $depth): bool {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $target_type = (string) $definition->getSetting('target_type');
      $revision_ids = array_map(strval(...), array_column($entity->get($name)->getValue(), 'target_revision_id'));
      if ($target_type === $entity_type_id && in_array($revision_id, $revision_ids, TRUE)) {
        return TRUE;
      }
      if ($depth + 1 >= self::MAX_DEPTH || !$revision_ids || !$this->entityTypeManager) {
        continue;
      }
      // The stored revision ids decide. A field item can hold an entity object
      // that was changed and saved as a newer revision since it was loaded.
      $storage = $this->entityTypeManager->getStorage($target_type);
      assert($storage instanceof RevisionableStorageInterface);
      foreach ($storage->loadMultipleRevisions($revision_ids) as $target) {
        $component = $this->component($target, $langcode);
        if ($component instanceof ContentEntityInterface && $this->holds($component, $entity_type_id, $revision_id, $langcode, $depth + 1)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Says whether an entity is a component of another, such as a paragraph.
   *
   * A component is published by its host. Its own save is the moment of
   * publishing only when it changes the revision the host shows. See
   * isLiveRewrite().
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

  /**
   * Gives a referenced component in its host's language.
   *
   * Entity Reference Revisions loads the referenced revision in its default
   * language, so a translation's components read in the wrong language
   * unless they are switched.
   *
   * @param mixed $target
   *   The referenced entity.
   * @param string $langcode
   *   The host's language.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The component, in that language when it has the translation. NULL when
   *   the target is not a component, such as a node referenced through an
   *   entity_reference_revisions field.
   */
  public function component(mixed $target, string $langcode): ?FieldableEntityInterface {
    if (!$target instanceof FieldableEntityInterface || !$this->isComponent($target)) {
      return NULL;
    }
    if ($target instanceof TranslatableInterface && $target->hasTranslation($langcode)) {
      $target = $target->getTranslation($langcode);
    }
    return $target;
  }

}
