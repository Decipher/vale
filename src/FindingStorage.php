<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Stores findings for the content report.
 *
 * Rows belong to a root: the top-level entity a finding is in, such as the
 * page that holds a paragraph. A scan replaces its roots' rows, so the report
 * shows each piece of content as it was last checked.
 */
class FindingStorage {

  /**
   * The table.
   */
  public const string TABLE = 'vale_finding';

  /**
   * How many findings one insert holds.
   */
  private const int INSERT_BATCH = 500;

  /**
   * Constructs a FindingStorage.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The database connection.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    protected Connection $database,
    protected TimeInterface $time,
  ) {}

  /**
   * Removes the rows that a scan did not refresh.
   *
   * @param string|null $entity_type
   *   The root entity type, or NULL for every type.
   * @param string|null $bundle
   *   The root bundle, or NULL for every bundle.
   * @param int $before
   *   When the scan started, as a Unix timestamp.
   */
  public function clearStale(?string $entity_type, ?string $bundle, int $before): void {
    $delete = $this->database->delete(self::TABLE)->condition('scanned', $before, '<');
    if ($entity_type !== NULL) {
      $delete->condition('root_type', $entity_type);
    }
    if ($bundle !== NULL) {
      $delete->condition('root_bundle', $bundle);
    }
    $delete->execute();
  }

  /**
   * Removes the rows of every bundle not in a list.
   *
   * @param list<string> $bundles
   *   The bundles to keep, as "entity_type:bundle".
   */
  public function clearBundlesExcept(array $bundles): void {
    $stored = $this->database->select(self::TABLE, 'f')
      ->fields('f', ['root_type', 'root_bundle'])
      ->distinct()
      ->execute();
    foreach ($stored as $row) {
      if (!in_array($row->root_type . ':' . $row->root_bundle, $bundles, TRUE)) {
        $this->database->delete(self::TABLE)
          ->condition('root_type', $row->root_type)
          ->condition('root_bundle', $row->root_bundle)
          ->execute();
      }
    }
  }

  /**
   * Removes every row, before everything is scanned again.
   */
  public function clearAll(): void {
    $this->database->truncate(self::TABLE)->execute();
  }

  /**
   * Replaces the rows of some roots with their current findings.
   *
   * @param string $entity_type
   *   The roots' entity type.
   * @param array<int|string> $ids
   *   The roots' ids, including those that now have no findings.
   * @param \Drupal\vale\Finding[] $findings
   *   Their findings.
   */
  public function replace(string $entity_type, array $ids, array $findings): void {
    $transaction = $this->database->startTransaction();
    if ($ids) {
      $this->database->delete(self::TABLE)
        ->condition('root_type', $entity_type)
        ->condition('root_id', array_map(strval(...), $ids), 'IN')
        ->execute();
    }
    $this->add($findings);
    unset($transaction);
  }

  /**
   * Adds findings.
   *
   * @param \Drupal\vale\Finding[] $findings
   *   The findings. Ones without an alert record a value that could not be
   *   checked, with level "unchecked".
   */
  public function add(array $findings): void {
    if (!$findings) {
      return;
    }
    $now = $this->time->getRequestTime();
    // Each row binds 18 values, and a database caps the values in one
    // query, so a large scan is inserted in batches.
    foreach (array_chunk($findings, self::INSERT_BATCH) as $batch) {
      $this->insert($batch, $now);
    }
  }

  /**
   * Inserts one batch of findings.
   *
   * @param \Drupal\vale\Finding[] $findings
   *   The findings.
   * @param int $now
   *   When they were found, as a Unix timestamp.
   */
  protected function insert(array $findings, int $now): void {
    $insert = $this->database->insert(self::TABLE)->fields([
      'root_type', 'root_id', 'root_bundle', 'root_label',
      'entity_type', 'entity_id', 'bundle', 'label', 'langcode',
      'field', 'delta', 'rule', 'level', 'message', 'matched', 'line', 'span_start', 'span_end', 'scanned',
    ]);
    foreach ($findings as $finding) {
      $root = $finding->root();
      $alert = $finding->alert;
      $insert->values([
        $root['entity_type'], $root['id'], $root['bundle'], mb_substr($root['label'], 0, 255),
        $finding->entityType, $finding->entityId, $finding->bundle, mb_substr($finding->label, 0, 255),
        $finding->langcode,
        $finding->field, $finding->delta,
        $alert->rule ?? '',
        $alert->level ?? 'unchecked',
        // A text column holds 64 KB, and a character can take four bytes.
        mb_substr($alert->message ?? $finding->reason, 0, 16000),
        mb_substr($alert->match ?? '', 0, 255),
        $alert->line ?? 0, $alert->start ?? 0, $alert->end ?? 0,
        $now,
      ]);
    }
    $insert->execute();
  }

  /**
   * Removes the rows of a root that was deleted.
   *
   * @param string $entity_type
   *   The root's entity type.
   * @param int|string $id
   *   The root's id.
   */
  public function delete(string $entity_type, int|string $id): void {
    $this->database->delete(self::TABLE)
      ->condition('root_type', $entity_type)
      ->condition('root_id', (string) $id)
      ->execute();
  }

  /**
   * Gives the stored rows, one at a time.
   *
   * The filters match as the report's do: the bundle and the level exactly,
   * and the rule as part of its name.
   *
   * @param array{root_bundle?: string, level?: string, rule?: string} $filters
   *   Values to filter on.
   *
   * @return \Generator<int, array<string, mixed>>
   *   The rows, ordered by root.
   */
  public function rows(array $filters = []): \Generator {
    $query = $this->database->select(self::TABLE, 'f')->fields('f');
    foreach (['root_bundle', 'level'] as $column) {
      if (($filters[$column] ?? '') !== '') {
        $query->condition('f.' . $column, $filters[$column]);
      }
    }
    if (($filters['rule'] ?? '') !== '') {
      $query->condition('f.rule', '%' . $this->database->escapeLike($filters['rule']) . '%', 'LIKE');
    }
    $query->orderBy('f.root_type')->orderBy('f.root_id')->orderBy('f.fid');
    foreach ($query->execute() as $record) {
      yield (array) $record;
    }
  }

  /**
   * Gives the levels a finding can have, for a Views filter.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Labels, keyed by level.
   */
  public static function levelOptions(): array {
    return [
      Alert::ERROR => new TranslatableMarkup('Error'),
      Alert::WARNING => new TranslatableMarkup('Warning'),
      Alert::SUGGESTION => new TranslatableMarkup('Suggestion'),
      'unchecked' => new TranslatableMarkup('Not checked'),
    ];
  }

  /**
   * Gives the bundles that have rows, for a Views filter.
   *
   * @return array<string, string>
   *   Bundle ids, keyed by bundle id.
   */
  public static function bundleOptions(): array {
    $bundles = \Drupal::database()->select(self::TABLE, 'f')
      ->fields('f', ['root_bundle'])
      ->distinct()
      ->orderBy('root_bundle')
      ->execute()
      ->fetchCol();
    return array_combine($bundles, $bundles);
  }

}
