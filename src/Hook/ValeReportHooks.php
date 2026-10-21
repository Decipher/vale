<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\vale\ContentWalker;
use Drupal\vale\FindingStorage;
use Drupal\vale\PublishState;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Keeps the content report current as content changes.
 *
 * The report describes Live. Saving a top-level entity's default revision
 * outside a workspace replaces its rows, and deleting it removes them. A
 * component, such as a paragraph, is reported through its host's save.
 */
class ValeReportHooks {

  /**
   * Constructs a ValeReportHooks.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\FindingStorage $findingStorage
   *   The finding storage.
   * @param \Drupal\vale\PublishState $publishState
   *   The publish state service, which knows what a component is.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   */
  public function __construct(
    protected ContentWalker $contentWalker,
    protected FindingStorage $findingStorage,
    protected PublishState $publishState,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Implements hook_entity_insert().
   */
  #[Hook('entity_insert')]
  public function entityInsert(EntityInterface $entity): void {
    $this->refresh($entity);
  }

  /**
   * Implements hook_entity_update().
   */
  #[Hook('entity_update')]
  public function entityUpdate(EntityInterface $entity): void {
    $this->refresh($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity instanceof FieldableEntityInterface && !$this->publishState->isComponent($entity) && $entity->id() !== NULL) {
      $this->findingStorage->delete($entity->getEntityTypeId(), $entity->id());
    }
  }

  /**
   * Replaces a top-level entity's rows with its current findings.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   */
  protected function refresh(EntityInterface $entity): void {
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->id() === NULL
      || $this->publishState->isComponent($entity)
      || ($entity instanceof RevisionableInterface && !$entity->isDefaultRevision())
      || $this->workspaceManager?->hasActiveWorkspace()
      || !$this->contentWalker->mayHoldProse($entity->getEntityTypeId(), $entity->bundle())
    ) {
      return;
    }
    $this->findingStorage->replace($entity->getEntityTypeId(), [$entity->id()], $this->contentWalker->entity($entity));
  }

}
