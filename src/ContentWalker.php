<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Lints stored content: a bundle, a workspace's changes, or everything.
 *
 * Each entity is linted with the components it references through
 * entity_reference_revisions fields, such as paragraphs. The Drush command
 * and the workspace publish check both walk content through here.
 */
class ContentWalker {

  /**
   * How many entities are loaded at once.
   */
  private const int CHUNK = 50;

  /**
   * How deep components are followed.
   */
  private const int MAX_DEPTH = 10;

  /**
   * Constructs a ContentWalker.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $bundleInfo
   *   The bundle info service.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\vale\FieldLinter $fieldLinter
   *   The field linter.
   * @param \Drupal\vale\PublishState $publishState
   *   The publish state service, which knows what a component is.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   * @param object|null $workspaceTracker
   *   The service that lists a workspace's tracked entities, when Workspaces
   *   is on: workspaces.tracker from Drupal 11.3, workspaces.association
   *   before. ValeServiceProvider picks it.
   * @param \Drupal\Core\Cache\MemoryCache\MemoryCacheInterface|null $memoryCache
   *   The entity memory cache, cleared after each chunk so a long walk does
   *   not keep every component it loaded.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldLinter $fieldLinter,
    protected PublishState $publishState,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?object $workspaceTracker = NULL,
    protected ?MemoryCacheInterface $memoryCache = NULL,
  ) {}

  /**
   * Lints the latest revision of every entity in a bundle.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the bundle does not exist.
   */
  public function bundle(string $entity_type_id, string $bundle): array {
    return $this->lintKeys($entity_type_id, $this->ids($entity_type_id, $bundle));
  }

  /**
   * Lists what to load to lint a bundle, so it can be linted in chunks.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return list<int|string>
   *   For a revisionable entity type, the revision id of each entity's latest
   *   revision. Otherwise, entity ids.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the bundle does not exist.
   */
  public function ids(string $entity_type_id, string $bundle): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$entity_type || !$entity_type->entityClassImplements(FieldableEntityInterface::class)
      || !isset($this->bundleInfo->getBundleInfo($entity_type_id)[$bundle])) {
      throw new \InvalidArgumentException(sprintf('There is no %s bundle "%s".', $entity_type_id, $bundle));
    }
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()->accessCheck(FALSE);
    if ($entity_type->hasKey('bundle')) {
      $query->condition((string) $entity_type->getKey('bundle'), $bundle);
    }
    if (!$entity_type->isRevisionable()) {
      return array_values($query->execute());
    }

    // Outside a workspace, a workspace's revisions are not this content
    // yet, but latestRevision() would pick them. So take the newest revision
    // that belongs to no workspace, which still includes a forward draft
    // made in Live.
    $workspace_key = $entity_type instanceof ContentEntityTypeInterface ? $entity_type->getRevisionMetadataKey('workspace') : NULL;
    if (is_string($workspace_key) && $this->workspaceManager && !$this->workspaceManager->hasActiveWorkspace()) {
      $revision_key = (string) $entity_type->getKey('revision');
      $id_key = (string) $entity_type->getKey('id');
      $aggregate = $this->entityTypeManager->getStorage($entity_type_id)->getAggregateQuery()
        ->accessCheck(FALSE)
        ->allRevisions()
        ->notExists($workspace_key)
        ->groupBy($id_key)
        ->aggregate($revision_key, 'MAX');
      if ($entity_type->hasKey('bundle')) {
        $aggregate->condition((string) $entity_type->getKey('bundle'), $bundle);
      }
      // QueryInterface::execute() is typed for plain queries. An aggregate
      // query returns a row per group.
      /** @var list<array<string, string>> $rows */
      $rows = $aggregate->execute();
      return array_column($rows, $revision_key . '_max');
    }

    $query->latestRevision();
    return array_keys($query->execute());
  }

  /**
   * Lints a chunk of what ids() listed.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param list<int|string> $keys
   *   Revision ids for a revisionable entity type, otherwise entity ids.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   */
  public function lintKeys(string $entity_type_id, array $keys): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    // lintIds() reads revision ids from the keys, and entity ids from the
    // values.
    $by_revision = $entity_type->isRevisionable();
    return $this->lintIds($entity_type, $by_revision ? array_flip($keys) : $keys, $by_revision);
  }

  /**
   * Lints every entity that has opted-in fields, or components that might.
   *
   * Components are linted through the entities that reference them, so they
   * are not walked by themselves.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   */
  public function all(): array {
    $findings = [];
    foreach (array_keys($this->scannableBundles()) as $key) {
      [$entity_type_id, $bundle] = explode(':', $key, 2);
      array_push($findings, ...$this->bundle($entity_type_id, $bundle));
    }
    return $findings;
  }

  /**
   * Lists the bundles a scan of all content covers.
   *
   * @return array<string, string>
   *   Labels, such as "Content: Basic page", keyed by "entity_type:bundle".
   */
  public function scannableBundles(): array {
    $bundles = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(FieldableEntityInterface::class)
        || method_exists($entity_type->getClass(), 'getParentEntity')) {
        continue;
      }
      foreach ($this->bundleInfo->getBundleInfo($entity_type_id) as $bundle => $info) {
        if ($this->mayHoldProse($entity_type_id, (string) $bundle)) {
          $bundles[$entity_type_id . ':' . $bundle] = $entity_type->getLabel() . ': ' . ($info['label'] ?? $bundle);
        }
      }
    }
    return $bundles;
  }

  /**
   * Lints the entities a workspace tracks, as they read in that workspace.
   *
   * @param string $workspace_id
   *   The workspace.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   *
   * @throws \InvalidArgumentException
   *   When Workspaces is off or the workspace does not exist.
   */
  public function workspace(string $workspace_id): array {
    if (!$this->workspaceManager || !$this->workspaceTracker || !method_exists($this->workspaceTracker, 'getTrackedEntities')) {
      throw new \InvalidArgumentException('The Workspaces module is not installed.');
    }
    if (!$this->entityTypeManager->getStorage('workspace')->load($workspace_id)) {
      throw new \InvalidArgumentException(sprintf('There is no workspace "%s".', $workspace_id));
    }
    $tracked = $this->workspaceTracker->getTrackedEntities($workspace_id);
    $findings = [];
    $this->workspaceManager->executeInWorkspace($workspace_id, function () use ($tracked, &$findings): void {
      foreach ($tracked as $entity_type_id => $revisions) {
        $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
        // A tracked component is linted through its tracked host.
        if (!$entity_type || method_exists($entity_type->getClass(), 'getParentEntity')) {
          continue;
        }
        // Inside the workspace, loading an entity gives the workspace's own
        // revision.
        array_push($findings, ...$this->lintIds($entity_type, array_values(array_unique($revisions)), FALSE));
      }
    });
    return $findings;
  }

  /**
   * Lints one entity and its components.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   */
  public function entity(FieldableEntityInterface $entity): array {
    $host = NULL;
    if ($this->publishState->isComponent($entity) && method_exists($entity, 'getParentEntity')) {
      $parent = $entity->getParentEntity();
      $host = $parent instanceof FieldableEntityInterface ? Finding::describe($parent) : NULL;
    }
    $findings = [];
    $this->walk($entity, $host, $findings, 0);
    return $findings;
  }

  /**
   * Loads entities in chunks and lints them.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param array<int|string, int|string> $ids
   *   Entity ids. When $by_revision is TRUE, they are keyed by revision id,
   *   as a query with latestRevision() gives them, so a forward draft is
   *   linted rather than the default revision.
   * @param bool $by_revision
   *   Whether to load the revisions named by the keys.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   */
  protected function lintIds(EntityTypeInterface $entity_type, array $ids, bool $by_revision): array {
    $storage = $this->entityTypeManager->getStorage($entity_type->id());
    $by_revision = $by_revision && $storage instanceof RevisionableStorageInterface;
    $keys = $by_revision ? array_keys($ids) : array_values($ids);
    $findings = [];
    foreach (array_chunk($keys, self::CHUNK) as $chunk) {
      $entities = $by_revision ? $storage->loadMultipleRevisions($chunk) : $storage->loadMultiple($chunk);
      foreach ($entities as $entity) {
        if ($entity instanceof FieldableEntityInterface) {
          array_push($findings, ...$this->entity($entity));
        }
      }
      $storage->resetCache();
      $this->memoryCache?->deleteAll();
    }
    return $findings;
  }

  /**
   * Lints an entity's opted-in fields and walks into its components.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param array{entity_type: string, id: string, bundle: string, label: string}|null $host
   *   The top-level entity, for a component.
   * @param \Drupal\vale\Finding[] $findings
   *   The findings so far.
   * @param int $depth
   *   How deep the entity is.
   */
  protected function walk(FieldableEntityInterface $entity, ?array $host, array &$findings, int $depth): void {
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      foreach ($this->fieldLinter->lint($entity->get($name)) as $delta => $result) {
        $base = [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          (string) $entity->label(),
          $entity->bundle(),
          $name,
          $delta,
        ];
        if (!$result->isChecked()) {
          $findings[] = new Finding(...$base, alert: NULL, reason: $result->reason, host: $host);
          continue;
        }
        foreach ($result->alerts as $alert) {
          $findings[] = new Finding(...$base, alert: $alert, host: $host);
        }
      }
      if ($definition->getType() === 'entity_reference_revisions' && $depth < self::MAX_DEPTH) {
        foreach ($entity->get($name) as $item) {
          $component = $item->get('entity')->getValue();
          if ($component instanceof FieldableEntityInterface) {
            $this->walk($component, $host ?? Finding::describe($entity), $findings, $depth + 1);
          }
        }
      }
    }
  }

  /**
   * Says whether a bundle has opted-in fields or components.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE when the bundle has an opted-in field or an
   *   entity_reference_revisions field.
   */
  public function mayHoldProse(string $entity_type_id, string $bundle): bool {
    foreach ($this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle) as $definition) {
      if ($definition->getType() === 'entity_reference_revisions' || $this->fieldLinter->isEnabled($definition)) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
