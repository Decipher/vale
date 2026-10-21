<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\DestructableInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Refreshes the content report after content is saved.
 *
 * A save only notes what changed. The rows are refreshed once the response
 * is sent, so the editor does not wait for Vale. A large or syncing save,
 * such as a migration or a config import, goes to the cron queue instead, as
 * does every save while Vale is unavailable. So an outage never replaces
 * checked rows with unchecked ones.
 */
class ReportRefresher implements DestructableInterface {

  /**
   * The queue that holds refreshes for cron.
   */
  public const string QUEUE = 'vale_report_refresh';

  /**
   * How many entities are refreshed at the end of a request.
   */
  private const int LIMIT = 20;

  /**
   * The entities to refresh, keyed by "entity_type:id".
   *
   * @var array<string, array{0: string, 1: string}>
   */
  protected array $pending = [];

  /**
   * The entities to send to the queue, keyed by "entity_type:id".
   *
   * @var array<string, array{0: string, 1: string}>
   */
  protected array $deferred = [];

  /**
   * Constructs a ReportRefresher.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\FindingStorage $findingStorage
   *   The finding storage.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\Core\Queue\QueueFactory $queueFactory
   *   The queue factory.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   */
  public function __construct(
    protected ContentWalker $contentWalker,
    protected FindingStorage $findingStorage,
    protected ValeEnvironment $valeEnvironment,
    protected QueueFactory $queueFactory,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {}

  /**
   * Says why the report cannot be written now, if it cannot.
   *
   * Every writer that replaces rows asks first: the report describes Live,
   * and replacing rows without Vale would leave them unchecked.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup|null
   *   The reason, or NULL when the report can be written. It stays markup, so
   *   a message that holds it does not escape it again.
   */
  public function refusal(): ?TranslatableMarkup {
    if ($this->workspaceManager?->hasActiveWorkspace()) {
      return new TranslatableMarkup('Switch to Live to check content for the report.');
    }
    $reason = $this->valeEnvironment->unavailableReason();
    return $reason === NULL ? NULL : new TranslatableMarkup('Vale is not available, so content cannot be checked. @reason', ['@reason' => $reason]);
  }

  /**
   * Notes that an entity's rows need refreshing.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int|string $id
   *   The entity id.
   * @param bool $defer
   *   Whether to leave the refresh to cron.
   */
  public function add(string $entity_type, int|string $id, bool $defer = FALSE): void {
    $key = $entity_type . ':' . $id;
    if ($defer) {
      unset($this->pending[$key]);
      $this->deferred[$key] = [$entity_type, (string) $id];
    }
    elseif (!isset($this->deferred[$key])) {
      $this->pending[$key] = [$entity_type, (string) $id];
    }
  }

  /**
   * Forgets an entity that was deleted.
   *
   * @param string $entity_type
   *   The entity type.
   * @param int|string $id
   *   The entity id.
   */
  public function remove(string $entity_type, int|string $id): void {
    unset($this->pending[$entity_type . ':' . $id], $this->deferred[$entity_type . ':' . $id]);
  }

  /**
   * Replaces an entity's rows with those of its newest revision.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $id
   *   The entity id.
   *
   * @return bool
   *   FALSE when Vale is unavailable, so nothing was changed.
   */
  public function refresh(string $entity_type, string $id): bool {
    if ($this->valeEnvironment->unavailableReason() !== NULL) {
      return FALSE;
    }
    $latest = $this->contentWalker->latest($entity_type, $id);
    $this->findingStorage->replace($entity_type, [$id], $latest ? $this->contentWalker->entity($latest) : []);
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function destruct(): void {
    $deferred = $this->deferred;
    $pending = $this->pending;
    $this->deferred = [];
    $this->pending = [];
    if (count($pending) > self::LIMIT || $this->valeEnvironment->unavailableReason() !== NULL) {
      $deferred += $pending;
      $pending = [];
    }
    foreach ($pending as [$entity_type, $id]) {
      $this->refresh($entity_type, $id);
    }
    if ($deferred) {
      $queue = $this->queueFactory->get(self::QUEUE);
      foreach ($deferred as $item) {
        $queue->createItem($item);
      }
    }
  }

}
