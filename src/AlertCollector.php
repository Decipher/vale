<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Holds the findings of this request that did not stop a save.
 *
 * Warnings and suggestions never become violations, and in publish-only mode
 * neither do errors on a draft. The validators put them here, and a form
 * handler or the JSON:API response subscriber shows them.
 */
class AlertCollector {

  /**
   * The findings, keyed so a value validated twice is listed once.
   *
   * @var array<string, array{entity: \Drupal\Core\Entity\FieldableEntityInterface, field: string, delta: int, alert: \Drupal\vale\Alert}>
   */
  protected array $alerts = [];

  /**
   * Why values were not checked, keyed by reason.
   *
   * @var array<string, string>
   */
  protected array $unchecked = [];

  /**
   * Whether any opted-in value was linted in this request.
   */
  protected bool $linted = FALSE;

  /**
   * Records that an opted-in value was linted, or was meant to be.
   */
  public function markLinted(): void {
    $this->linted = TRUE;
  }

  /**
   * Says whether any opted-in value was linted.
   *
   * @return bool
   *   TRUE when one was.
   */
  public function hasLinted(): bool {
    return $this->linted;
  }

  /**
   * Adds a finding that did not stop the save.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity that holds the value.
   * @param string $field
   *   The field name.
   * @param int $delta
   *   The value's delta.
   * @param \Drupal\vale\Alert $alert
   *   The finding.
   */
  public function add(FieldableEntityInterface $entity, string $field, int $delta, Alert $alert): void {
    $this->linted = TRUE;
    $key = implode("\0", [
      $entity->getEntityTypeId(),
      $entity->uuid(),
      $field,
      $delta,
      $alert->rule,
      $alert->line,
      $alert->start,
    ]);
    // Nothing is loaded here. This runs inside entity validation, and on a
    // site whose workspace manager is decorated, loading an entity at this
    // point made core's workspace conflict check refuse a paragraph that a
    // form validated by itself. Labels are worked out in alerts(), once
    // validation has finished.
    $this->alerts[$key] = [
      'entity' => $entity,
      'field' => $field,
      'delta' => $delta,
      'alert' => $alert,
    ];
  }

  /**
   * Records that a value could not be checked.
   *
   * @param string $reason
   *   Why.
   */
  public function addUnchecked(string $reason): void {
    $this->linted = TRUE;
    $this->unchecked[$reason] = $reason;
  }

  /**
   * Gives the findings.
   *
   * @return list<array{entity_type: string, bundle: string, uuid: string, label: string, field: string, field_label: string, delta: int, alert: \Drupal\vale\Alert}>
   *   The findings, in the order they were found.
   */
  public function alerts(): array {
    $alerts = [];
    foreach ($this->alerts as $finding) {
      $entity = $finding['entity'];
      $alerts[] = [
        'entity_type' => $entity->getEntityTypeId(),
        'bundle' => $entity->bundle(),
        'uuid' => (string) $entity->uuid(),
        'label' => EntityLabel::of($entity),
        'field' => $finding['field'],
        'field_label' => (string) $entity->getFieldDefinition($finding['field'])?->getLabel(),
        'delta' => $finding['delta'],
        'alert' => $finding['alert'],
      ];
    }
    return $alerts;
  }

  /**
   * Gives the reasons values were not checked.
   *
   * @return string[]
   *   The reasons.
   */
  public function unchecked(): array {
    return array_values($this->unchecked);
  }

  /**
   * Forgets everything collected, once it has been shown.
   */
  public function reset(): void {
    $this->alerts = [];
    $this->unchecked = [];
    $this->linted = FALSE;
  }

}
