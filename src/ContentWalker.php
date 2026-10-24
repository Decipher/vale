<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\RevisionableStorageInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\workspaces\WorkspaceManagerInterface;

/**
 * Lints stored content: a bundle, a workspace's changes, or everything.
 *
 * Each entity is linted in every translation, with the components it
 * references through entity_reference_revisions fields, such as paragraphs,
 * read in the same language. Outside a workspace, the revision linted is the
 * newest one that belongs to no workspace, which includes a forward draft:
 * what an editor is about to publish. The Drush command, the content report
 * and the workspace publish check all walk content through here.
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
   * The entity types loaded since the last cache reset.
   *
   * @var array<string, true>
   */
  protected array $loaded = [];

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
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $bundleInfo,
    protected EntityFieldManagerInterface $entityFieldManager,
    protected FieldLinter $fieldLinter,
    protected PublishState $publishState,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
    protected ?object $workspaceTracker = NULL,
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
    $findings = [];
    $after = NULL;
    while ($keys = $this->idsAfter($entity_type_id, $bundle, $after, self::CHUNK)) {
      array_push($findings, ...$this->lintKeys($entity_type_id, array_keys($keys)));
      $after = end($keys);
    }
    return $findings;
  }

  /**
   * Lists the next chunk of a bundle to lint, in entity id order.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param int|string|null $after
   *   The last entity id of the previous chunk, or NULL to start.
   * @param int $limit
   *   How many entities to list.
   *
   * @return array<int|string, int|string>
   *   Entity ids, keyed by what to load: the revision id of the latest
   *   revision for a revisionable entity type, the entity id otherwise.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the bundle does not exist.
   */
  public function idsAfter(string $entity_type_id, string $bundle, int|string|null $after, int $limit): array {
    return $this->latestKeys($this->bundleType($entity_type_id, $bundle), $bundle, [], $after, $limit);
  }

  /**
   * Checks that a bundle of a fieldable entity type exists.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\Core\Entity\EntityTypeInterface
   *   The entity type.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the bundle does not exist.
   */
  public function bundleType(string $entity_type_id, string $bundle): EntityTypeInterface {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$entity_type || !$entity_type->entityClassImplements(FieldableEntityInterface::class)
      || !isset($this->bundleInfo->getBundleInfo($entity_type_id)[$bundle])) {
      throw new \InvalidArgumentException(sprintf('There is no %s bundle "%s".', $entity_type_id, $bundle));
    }
    return $entity_type;
  }

  /**
   * Counts the entities in a bundle, for progress.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return int
   *   The number of entities.
   */
  public function count(string $entity_type_id, string $bundle): int {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id);
    $query = $this->entityTypeManager->getStorage($entity_type_id)->getQuery()->accessCheck(FALSE);
    if ($entity_type->hasKey('bundle')) {
      $query->condition((string) $entity_type->getKey('bundle'), $bundle);
    }
    return (int) $query->count()->execute();
  }

  /**
   * Loads the revision of an entity that the report describes.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param int|string $id
   *   The entity id.
   *
   * @return \Drupal\Core\Entity\FieldableEntityInterface|null
   *   The newest revision outside any workspace, or NULL when there is none.
   */
  public function latest(string $entity_type_id, int|string $id): ?FieldableEntityInterface {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if (!$entity_type) {
      return NULL;
    }
    $keys = $this->latestKeys($entity_type, NULL, [$id], NULL, 1);
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $key = array_key_first($keys);
    if ($key === NULL) {
      return NULL;
    }
    $entity = $entity_type->isRevisionable() && $storage instanceof RevisionableStorageInterface
      ? $storage->loadRevision($key)
      : $storage->load($key);
    return $entity instanceof FieldableEntityInterface ? $entity : NULL;
  }

  /**
   * Lints a chunk of what idsAfter() listed.
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
        || PublishState::isComponentType($entity_type)) {
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
        if (!$entity_type || PublishState::isComponentType($entity_type)) {
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
   * Lints one entity, in every translation, and its components.
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
    $translations = [$entity];
    if ($entity instanceof TranslatableInterface && $entity->isTranslatable()) {
      $translations = [];
      foreach (array_keys($entity->getTranslationLanguages()) as $langcode) {
        $translations[] = $entity->getTranslation($langcode);
      }
    }
    $findings = [];
    foreach ($translations as $translation) {
      $this->walk($translation, $host, $translation->language()->getId(), $findings, 0);
    }
    return $findings;
  }

  /**
   * Lists the newest revision outside any workspace of some entities.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param string|null $bundle
   *   A bundle to limit to, or NULL.
   * @param array<int|string> $ids
   *   Entity ids to limit to, or an empty array.
   * @param int|string|null $after
   *   Only entities with a higher id, or NULL.
   * @param int|null $limit
   *   How many entities, or NULL for all.
   *
   * @return array<int|string, int|string>
   *   Entity ids, keyed by revision id for a revisionable type, or by entity
   *   id otherwise, in entity id order.
   */
  protected function latestKeys(EntityTypeInterface $entity_type, ?string $bundle, array $ids, int|string|null $after, ?int $limit): array {
    $storage = $this->entityTypeManager->getStorage($entity_type->id());
    $id_key = (string) $entity_type->getKey('id');
    $workspace_key = $entity_type instanceof ContentEntityTypeInterface ? $entity_type->getRevisionMetadataKey('workspace') : NULL;
    $live_only = $entity_type->isRevisionable() && is_string($workspace_key)
      && $this->workspaceManager && !$this->workspaceManager->hasActiveWorkspace();

    $limit_query = static function (QueryInterface $query) use ($entity_type, $id_key, $bundle, $ids, $after, $limit): void {
      $query->sort($id_key);
      if ($bundle !== NULL && $entity_type->hasKey('bundle')) {
        $query->condition((string) $entity_type->getKey('bundle'), $bundle);
      }
      if ($ids) {
        $query->condition($id_key, $ids, 'IN');
      }
      if ($after !== NULL) {
        $query->condition($id_key, $after, '>');
      }
      if ($limit !== NULL) {
        $query->range(0, $limit);
      }
    };

    if ($live_only) {
      // Outside a workspace, a workspace's revisions are not this content
      // yet, but latestRevision() would pick them. So take the newest
      // revision that belongs to no workspace, which still includes a
      // forward draft.
      $revision_key = (string) $entity_type->getKey('revision');
      $query = $storage->getAggregateQuery()->accessCheck(FALSE)->allRevisions();
      $limit_query($query);
      $query->notExists($workspace_key)
        ->groupBy($id_key)
        ->aggregate($revision_key, 'MAX');
      // QueryInterface::execute() is typed for plain queries. An aggregate
      // query returns a row per group.
      /** @var list<array<string, string>> $rows */
      $rows = $query->execute();
      return array_column($rows, $id_key, $revision_key . '_max');
    }
    $query = $storage->getQuery()->accessCheck(FALSE);
    $limit_query($query);
    if ($entity_type->isRevisionable()) {
      $query->latestRevision();
    }
    $result = $query->execute();
    return $entity_type->isRevisionable() ? $result : array_combine($result, $result);
  }

  /**
   * Loads entities in chunks and lints them.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param array<int|string, int|string> $ids
   *   Entity ids. When $by_revision is TRUE, they are keyed by revision id,
   *   so a forward draft is linted rather than the default revision.
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
      $this->resetCaches($entity_type->id());
    }
    return $findings;
  }

  /**
   * Resets the static caches of the storages a chunk loaded from.
   *
   * Only those storages are reset, so objects other code holds from other
   * storages, such as a publisher's, are left alone.
   *
   * @param string $entity_type_id
   *   The entity type the chunk was of.
   */
  protected function resetCaches(string $entity_type_id): void {
    foreach (array_keys([$entity_type_id => TRUE] + $this->loaded) as $loaded) {
      $this->entityTypeManager->getStorage($loaded)->resetCache();
    }
    $this->loaded = [];
  }

  /**
   * Lints an entity's opted-in fields and walks into its components.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity, in the language being linted.
   * @param array{entity_type: string, id: string, bundle: string, label: string}|null $host
   *   The top-level entity, for a component.
   * @param string $langcode
   *   The language being linted.
   * @param \Drupal\vale\Finding[] $findings
   *   The findings so far.
   * @param int $depth
   *   How deep the entity is.
   */
  protected function walk(FieldableEntityInterface $entity, ?array $host, string $langcode, array &$findings, int $depth): void {
    // A field that is not translatable holds the same values in every
    // translation, so only the default translation lints it.
    $translation = $entity instanceof TranslatableInterface && !$entity->isDefaultTranslation();
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      $values = $translation && !$definition->isTranslatable() ? [] : $this->fieldLinter->lint($entity->get($name));
      foreach ($values as $delta => $result) {
        $base = [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          (string) $entity->label(),
          $entity->bundle(),
          $name,
          $delta,
        ];
        if (!$result->isChecked()) {
          $findings[] = new Finding(...$base, alert: NULL, reason: $result->reason, host: $host, langcode: $langcode);
          continue;
        }
        foreach ($result->alerts as $alert) {
          $findings[] = new Finding(...$base, alert: $alert, host: $host, langcode: $langcode);
        }
      }
      if ($definition->getType() === 'entity_reference_revisions' && $depth < self::MAX_DEPTH) {
        foreach ($entity->get($name) as $item) {
          $component = $this->publishState->component($item->get('entity')->getValue(), $langcode);
          // A component without this translation is linted with the default
          // translation.
          if ($component && ($component->language()->getId() === $langcode || !$translation)) {
            $this->loaded[$component->getEntityTypeId()] = TRUE;
            $this->walk($component, $host ?? Finding::describe($entity), $langcode, $findings, $depth + 1);
          }
        }
      }
    }
  }

  /**
   * Says whether a bundle has an opted-in field, itself or in its components.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return bool
   *   TRUE when the bundle has an opted-in field, or references a component
   *   bundle that has one.
   */
  public function mayHoldProse(string $entity_type_id, string $bundle): bool {
    $seen = [];
    return $this->reachesProse($entity_type_id, $bundle, $seen);
  }

  /**
   * Says whether a bundle, or a component it may hold, has an opted-in field.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param array<string, true> $seen
   *   The bundles already searched. A bundle with no prose has none from any
   *   path, so the set is shared across the whole search.
   *
   * @return bool
   *   TRUE when an opted-in field is reachable.
   */
  private function reachesProse(string $entity_type_id, string $bundle, array &$seen): bool {
    $seen[$entity_type_id . ':' . $bundle] = TRUE;
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    foreach ($definitions as $definition) {
      if ($this->fieldLinter->isEnabled($definition)) {
        return TRUE;
      }
    }
    foreach ($definitions as $definition) {
      if ($definition->getType() !== 'entity_reference_revisions') {
        continue;
      }
      $target_type = (string) $definition->getSetting('target_type');
      // Only components are linted with their host, as walk() does.
      $target = $this->entityTypeManager->getDefinition($target_type, FALSE);
      if (!$target || !PublishState::isComponentType($target)) {
        continue;
      }
      foreach ($this->targetBundles($definition, $target_type) as $target_bundle) {
        if (!isset($seen[$target_type . ':' . $target_bundle]) && $this->reachesProse($target_type, $target_bundle, $seen)) {
          return TRUE;
        }
      }
    }
    return FALSE;
  }

  /**
   * Lists the bundles a reference field may point to.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $definition
   *   The reference field.
   * @param string $target_type
   *   Its target entity type.
   *
   * @return list<string>
   *   The bundles. A field with no bundle list may point to every bundle, and
   *   Paragraphs can list the bundles it excludes instead.
   */
  private function targetBundles(FieldDefinitionInterface $definition, string $target_type): array {
    $all = array_map(strval(...), array_keys($this->bundleInfo->getBundleInfo($target_type)));
    $settings = $definition->getSetting('handler_settings');
    $settings = is_array($settings) ? $settings : [];
    $listed = array_map(strval(...), array_keys(array_filter((array) ($settings['target_bundles'] ?? []))));
    if (!$listed) {
      return $all;
    }
    return array_values(empty($settings['negate']) ? array_intersect($all, $listed) : array_diff($all, $listed));
  }

}
