<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\vale\ContentWalker;
use Drupal\vale\FindingStorage;
use Drupal\vale\PublishState;
use Drupal\vale\ReportRefresher;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Keeps the content report current as content changes.
 *
 * The report describes Live. Saving a top-level entity outside a workspace
 * refreshes its rows from its newest revision, through ReportRefresher, and
 * deleting it removes them. A component, such as a paragraph, is reported
 * through its host's save. A bundle whose fields are no longer checked loses
 * its rows.
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
   * @param \Drupal\vale\ReportRefresher $reportRefresher
   *   The report refresher.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   */
  public function __construct(
    protected ContentWalker $contentWalker,
    protected FindingStorage $findingStorage,
    protected PublishState $publishState,
    protected ReportRefresher $reportRefresher,
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
   * Implements hook_entity_revision_delete().
   *
   * The report describes the newest revision, which can be a forward draft,
   * so deleting a revision can change what it should say.
   */
  #[Hook('entity_revision_delete')]
  public function entityRevisionDelete(EntityInterface $entity): void {
    $this->refresh($entity);
  }

  /**
   * Implements hook_entity_delete().
   */
  #[Hook('entity_delete')]
  public function entityDelete(EntityInterface $entity): void {
    if ($entity instanceof FieldableEntityInterface && !$this->publishState->isComponent($entity) && $entity->id() !== NULL) {
      $this->reportRefresher->remove($entity->getEntityTypeId(), $entity->id());
      $this->findingStorage->delete($entity->getEntityTypeId(), $entity->id());
    }
  }

  /**
   * Implements hook_ENTITY_TYPE_update() for field_config.
   *
   * Turning Vale off on a field can leave a bundle with nothing checked. Its
   * saves no longer refresh its rows, so they are removed here.
   */
  #[Hook('field_config_update')]
  public function fieldConfigUpdate(): void {
    $this->findingStorage->clearBundlesExcept(array_keys($this->contentWalker->scannableBundles()));
  }

  /**
   * Implements hook_ENTITY_TYPE_delete() for field_config.
   */
  #[Hook('field_config_delete')]
  public function fieldConfigDelete(): void {
    $this->fieldConfigUpdate();
  }

  /**
   * Notes that a top-level entity's rows need refreshing.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The saved entity.
   */
  protected function refresh(EntityInterface $entity): void {
    if (
      !$entity instanceof FieldableEntityInterface
      || $entity->id() === NULL
      || $this->publishState->isComponent($entity)
      || $this->workspaceManager?->hasActiveWorkspace()
      || !$this->contentWalker->mayHoldProse($entity->getEntityTypeId(), $entity->bundle())
    ) {
      return;
    }
    $this->reportRefresher->add($entity->getEntityTypeId(), $entity->id(), $entity instanceof SynchronizableInterface && $entity->isSyncing());
  }

}
