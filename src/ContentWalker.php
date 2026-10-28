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
use Drupal\Core\Field\EntityReferenceFieldItemListInterface;
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
    if (PublishState::isComponentType($this->entityTypeForBundle($entity_type_id, $bundle))) {
      return $this->componentBundle($entity_type_id, $bundle);
    }
    $findings = [];
    $after = NULL;
    while ($keys = $this->idsAfter($entity_type_id, $bundle, $after, self::CHUNK)) {
      array_push($findings, ...$this->lintKeys($entity_type_id, array_keys($keys)));
      $after = end($keys);
    }
    return $findings;
  }

  /**
   * Lints a bundle of components the way their hosts lint them.
   *
   * A host lints the revision of a component that it references, in the
   * host's translations, and no deeper than walk() follows. So each host is
   * walked once and only this bundle's findings are kept. A component that
   * no host holds any more, such as one an older revision replaced, has
   * none.
   *
   * @param string $entity_type_id
   *   The component entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   */
  protected function componentBundle(string $entity_type_id, string $bundle): array {
    $storage = $this->entityTypeManager->getStorage($entity_type_id);
    $roots = [];
    $hosts = [];
    $after = NULL;
    while ($keys = $this->idsAfter($entity_type_id, $bundle, $after, self::CHUNK)) {
      $components = $storage instanceof RevisionableStorageInterface ? $storage->loadMultipleRevisions(array_keys($keys)) : $storage->loadMultiple($keys);
      foreach ($components as $component) {
        $root = $component instanceof FieldableEntityInterface ? $this->root($component, $hosts) : NULL;
        if ($root) {
          $roots[implode(':', $root)] = $root;
        }
      }
      $this->resetCaches($entity_type_id);
      $after = end($keys);
    }
    $findings = [];
    foreach ($roots as [$type, $id]) {
      $host = $this->latest($type, $id);
      foreach ($host ? $this->entity($host) : [] as $finding) {
        if ($finding->entityType === $entity_type_id && $finding->bundle === $bundle) {
          $findings[] = $finding;
        }
      }
      $this->resetCaches($type);
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
    return $this->latestKeys($this->entityTypeForBundle($entity_type_id, $bundle), $bundle, [], $after, $limit);
  }

  /**
   * Gets the entity type of a bundle, checking that the bundle exists.
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
  public function entityTypeForBundle(string $entity_type_id, string $bundle): EntityTypeInterface {
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
   * Finds the top-level entity that a component belongs to.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $component
   *   The component.
   * @param array<string, array{string, int|string}|null> $hosts
   *   The roots already found for hosts that are components, keyed by their
   *   entity type and id, so each is loaded once.
   * @param int $depth
   *   How many component hosts up the search is.
   *
   * @return array{string, int|string}|null
   *   The entity type and id of the first host that is not a component, or
   *   NULL when the component has no host, or its hosts go deeper than walk()
   *   follows.
   */
  protected function root(FieldableEntityInterface $component, array &$hosts = [], int $depth = 0): ?array {
    // A component type names the fields that record its host.
    $entity_type = $component->getEntityType();
    $host = [];
    foreach (['type', 'id'] as $part) {
      $name = (string) $entity_type->get('entity_revision_parent_' . $part . '_field');
      $host[] = $name !== '' && $component->hasField($name) ? (string) $component->get($name)->value : '';
    }
    [$type, $id] = $host;
    $definition = $type !== '' && $id !== '' ? $this->entityTypeManager->getDefinition($type, FALSE) : NULL;
    if (!$definition) {
      return NULL;
    }
    if (!PublishState::isComponentType($definition)) {
      return [$type, $id];
    }
    // A host that is itself a component leads on to its own host.
    $key = $type . ':' . $id;
    if (!array_key_exists($key, $hosts)) {
      $parent = $depth + 1 < self::MAX_DEPTH ? $this->latest($type, $id) : NULL;
      $hosts[$key] = $parent ? $this->root($parent, $hosts, $depth + 1) : NULL;
    }
    return $hosts[$key];
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
   * @param string|null $label
   *   The label for a component reached through its parent, or NULL to use
   *   the entity's own label.
   */
  protected function walk(FieldableEntityInterface $entity, ?array $host, string $langcode, array &$findings, int $depth, ?string $label = NULL): void {
    $label ??= (string) $entity->label();
    // A field that is not translatable holds the same values in every
    // translation, so only the default translation lints it.
    $translation = $entity instanceof TranslatableInterface && !$entity->isDefaultTranslation();
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      $items = $entity->get($name);
      $values = $translation && !$definition->isTranslatable() ? [] : $this->fieldLinter->lint($items);
      foreach ($values as $delta => $result) {
        $base = [
          $entity->getEntityTypeId(),
          (string) $entity->id(),
          $label,
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
      if ($definition->getType() === 'entity_reference_revisions' && $depth < self::MAX_DEPTH && $items instanceof EntityReferenceFieldItemListInterface) {
        // Entity Reference Revisions loads the referenced revisions of the
        // whole field at once.
        foreach ($items->referencedEntities() as $target) {
          $component = $this->publishState->component($target, $langcode);
          // A component without this translation is linted with the default
          // translation.
          if ($component && ($component->language()->getId() === $langcode || !$translation)) {
            $this->loaded[$component->getEntityTypeId()] = TRUE;
            // Paragraphs labels a paragraph from its host's default revision.
            // A paragraph in a newer draft is then a "previous revision". The
            // walk knows the revision that holds it, so it labels it from that
            // revision instead, in the same form.
            $this->walk($component, $host ?? Finding::describe($entity), $langcode, $findings, $depth + 1, $label . ' > ' . $definition->getLabel());
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
   *   bundle that has one no deeper than walk() follows.
   */
  public function mayHoldProse(string $entity_type_id, string $bundle): bool {
    // Search one depth at a time, so each bundle is first found by its
    // shortest path. A bundle with no prose has none from any path, so each
    // bundle is searched once.
    $seen = [$entity_type_id . ':' . $bundle => TRUE];
    $level = [[$entity_type_id, $bundle]];
    for ($depth = 0; $level; $depth++) {
      $next = [];
      foreach ($level as [$type, $name]) {
        $definitions = $this->entityFieldManager->getFieldDefinitions($type, $name);
        foreach ($definitions as $definition) {
          if ($this->fieldLinter->isEnabled($definition)) {
            return TRUE;
          }
        }
        // walk() follows components only to MAX_DEPTH.
        if ($depth === self::MAX_DEPTH) {
          continue;
        }
        foreach ($this->componentBundles($definitions) as $key => $component) {
          if (!isset($seen[$key])) {
            $seen[$key] = TRUE;
            $next[] = $component;
          }
        }
      }
      $level = $next;
    }
    return FALSE;
  }

  /**
   * Lists the component bundles that reference fields may hold.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface[] $definitions
   *   The field definitions of a bundle.
   *
   * @return array<string, array{string, string}>
   *   The entity type and bundle of each component bundle, keyed by both.
   */
  private function componentBundles(array $definitions): array {
    $bundles = [];
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
        $bundles[$target_type . ':' . $target_bundle] = [$target_type, $target_bundle];
      }
    }
    return $bundles;
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
