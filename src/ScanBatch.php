<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;

/**
 * Batch callbacks that scan bundles into the content report.
 *
 * Batch API calls these statically, between requests, so they fetch their
 * services from the container.
 */
final class ScanBatch {

  /**
   * How many entities are linted per request.
   */
  private const int CHUNK = 25;

  /**
   * Builds the batch for some bundles.
   *
   * @param string[] $bundles
   *   Bundles, as "entity_type:bundle".
   *
   * @return array<string, mixed>
   *   The batch definition.
   */
  public static function build(array $bundles): array {
    $operations = [];
    foreach ($bundles as $bundle) {
      $operations[] = [[self::class, 'scan'], explode(':', $bundle, 2)];
    }
    return [
      'title' => new TranslatableMarkup('Checking content with Vale'),
      'operations' => $operations,
      'finished' => [self::class, 'finished'],
    ];
  }

  /**
   * Scans one chunk of a bundle.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param array<string, mixed>|\ArrayAccess<string, mixed> $context
   *   The batch context.
   */
  public static function scan(string $entity_type_id, string $bundle, array|\ArrayAccess &$context): void {
    $walker = \Drupal::service('vale.content_walker');
    $storage = \Drupal::service('vale.finding_storage');
    if (!isset($context['sandbox']['total'])) {
      $context['sandbox']['total'] = $walker->count($entity_type_id, $bundle);
      $context['sandbox']['after'] = NULL;
      $context['sandbox']['done'] = 0;
      $context['sandbox']['started'] = \Drupal::time()->getRequestTime();
    }
    // Page by id, so content added during the scan does not shift the pages.
    $keys = $walker->idsAfter($entity_type_id, $bundle, $context['sandbox']['after'], self::CHUNK);
    $findings = $keys ? $walker->lintKeys($entity_type_id, array_keys($keys)) : [];
    // Each chunk replaces its own rows, so the report stays whole while the
    // scan runs.
    $storage->replace($entity_type_id, array_values($keys), $findings);
    $context['sandbox']['done'] += count($keys);
    $context['results']['findings'] = ($context['results']['findings'] ?? 0) + count($findings);
    $context['results']['entities'] = ($context['results']['entities'] ?? 0) + count($keys);
    $total = max($context['sandbox']['total'], $context['sandbox']['done']);
    $context['message'] = new TranslatableMarkup('Checked @done of @total in @bundle.', [
      '@done' => $context['sandbox']['done'],
      '@total' => $total,
      '@bundle' => $entity_type_id . ':' . $bundle,
    ]);
    if (count($keys) < self::CHUNK) {
      // Rows the scan did not refresh belong to content that is gone.
      $storage->clearStale($entity_type_id, $bundle, $context['sandbox']['started']);
      $context['finished'] = 1;
      return;
    }
    $context['sandbox']['after'] = end($keys);
    $context['finished'] = min(0.99, $context['sandbox']['done'] / max(1, $total));
  }

  /**
   * Reports the outcome.
   *
   * @param bool $success
   *   Whether every operation ran.
   * @param array<string, int> $results
   *   The totals.
   */
  public static function finished(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    if (!$success) {
      $messenger->addError(new TranslatableMarkup('The scan did not finish. The report holds what it checked.'));
      return;
    }
    $messenger->addStatus(new TranslatableMarkup('@checked and found @findings.', [
      '@checked' => new PluralTranslatableMarkup($results['entities'] ?? 0, 'Checked 1 item', 'Checked @count items'),
      '@findings' => new PluralTranslatableMarkup($results['findings'] ?? 0, '1 finding', '@count findings'),
    ]));
  }

  /**
   * Gives the report's URL, when Views provides it.
   *
   * @return \Drupal\Core\Url
   *   The report, or the status report when Views is off.
   */
  public static function reportUrl(): Url {
    return \Drupal::service('router.route_provider')->getRoutesByNames(['view.vale_findings.page_1'])
      ? Url::fromRoute('view.vale_findings.page_1')
      : Url::fromRoute('system.status');
  }

}
