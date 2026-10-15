<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Batch callbacks that scan bundles into the content report.
 *
 * Batch API calls these statically, between requests, so they fetch their
 * services from the container.
 */
final class ScanBatch {

  use StringTranslationTrait;

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
      'title' => t('Checking content with Vale'),
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
    if (!isset($context['sandbox']['keys'])) {
      $storage->clearBundle($entity_type_id, $bundle);
      $context['sandbox']['keys'] = $walker->ids($entity_type_id, $bundle);
      $context['sandbox']['done'] = 0;
    }
    $keys = $context['sandbox']['keys'];
    $chunk = array_values(array_slice($keys, $context['sandbox']['done'], self::CHUNK));
    $findings = $chunk ? $walker->lintKeys($entity_type_id, $chunk) : [];
    $storage->add($findings);
    $context['sandbox']['done'] += count($chunk);
    $context['results']['findings'] = ($context['results']['findings'] ?? 0) + count($findings);
    $context['results']['entities'] = ($context['results']['entities'] ?? 0) + count($chunk);
    $context['message'] = t('Checked @done of @total in @bundle.', [
      '@done' => $context['sandbox']['done'],
      '@total' => count($keys),
      '@bundle' => $entity_type_id . ':' . $bundle,
    ]);
    $context['finished'] = $keys ? $context['sandbox']['done'] / count($keys) : 1;
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
      $messenger->addError(t('The scan did not finish. The report holds what it checked.'));
      return;
    }
    $messenger->addStatus(t('Checked @entities items and found @findings findings.', [
      '@entities' => $results['entities'] ?? 0,
      '@findings' => $results['findings'] ?? 0,
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
