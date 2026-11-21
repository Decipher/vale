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
   *   For a component such as a paragraph or a Canvas component instance,
   *   the top-level entity it belongs to. NULL for a top-level entity.
   * @param string $langcode
   *   The language of the translation that holds the value.
   * @param string $component
   *   For a value in a Canvas component tree, the UUID of its component
   *   instance. Otherwise an empty string.
   * @param string $prop
   *   For a value in a Canvas component tree, the prop that holds it.
   *   Otherwise an empty string.
   * @param string $componentLabel
   *   For a value in a Canvas component tree, the component instance's
   *   label. Otherwise an empty string.
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
    public string $langcode = '',
    public string $component = '',
    public string $prop = '',
    public string $componentLabel = '',
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
   * Names the field, with the component and prop for a component tree.
   *
   * @param string $field
   *   The field's label or name.
   *
   * @return string
   *   Such as "Components > Hero > heading", or the field alone.
   */
  public function place(string $field): string {
    return $this->component === '' ? $field : implode(' > ', [$field, $this->componentLabel, $this->prop]);
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
