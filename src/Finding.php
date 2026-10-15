<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * One finding in stored content, with where it is.
 *
 * A finding without an alert records a value that could not be checked.
 */
final readonly class Finding {

  /**
   * Constructs a Finding.
   *
   * @param string $entityType
   *   The entity type of the entity that holds the value.
   * @param string $entityId
   *   Its id.
   * @param string $label
   *   Its label.
   * @param string $bundle
   *   Its bundle.
   * @param string $field
   *   The field name.
   * @param int $delta
   *   The value's delta.
   * @param \Drupal\vale\Alert|null $alert
   *   The alert, or NULL when the value could not be checked.
   * @param string $reason
   *   Why the value could not be checked, or an empty string.
   * @param array{entity_type: string, id: string, bundle: string, label: string}|null $host
   *   For a component such as a paragraph, the top-level entity it belongs
   *   to. NULL for a top-level entity.
   */
  public function __construct(
    public string $entityType,
    public string $entityId,
    public string $label,
    public string $bundle,
    public string $field,
    public int $delta,
    public ?Alert $alert,
    public string $reason = '',
    public ?array $host = NULL,
  ) {}

  /**
   * Describes an entity for the host of a finding.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return array{entity_type: string, id: string, bundle: string, label: string}
   *   Its type, id and label.
   */
  public static function describe(FieldableEntityInterface $entity): array {
    return [
      'entity_type' => $entity->getEntityTypeId(),
      'id' => (string) $entity->id(),
      'bundle' => $entity->bundle(),
      'label' => (string) $entity->label(),
    ];
  }

  /**
   * Gives the top-level entity the finding belongs to.
   *
   * @return array{entity_type: string, id: string, bundle: string, label: string}
   *   The host for a component, or the entity itself.
   */
  public function root(): array {
    return $this->host ?? [
      'entity_type' => $this->entityType,
      'id' => $this->entityId,
      'bundle' => $this->bundle,
      'label' => $this->label,
    ];
  }

  /**
   * Says whether the finding is an error-level alert.
   *
   * @return bool
   *   TRUE for an error.
   */
  public function isError(): bool {
    return $this->alert?->level === Alert::ERROR;
  }

}
