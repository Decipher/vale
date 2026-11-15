<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Builds the arguments and labels of a finding's message.
 *
 * A finding is worded the same in a save error, a form message and a
 * workspace publish message. So each of them takes its arguments from here.
 *
 * A component such as a paragraph is named by its entity type and bundle,
 * such as 'Paragraph "Docs text"'. Other entities are named by their entity
 * type and label, such as 'Content "Guide"'.
 *
 * @internal
 */
final class FindingMessage {

  use StringTranslationTrait;

  /**
   * The message for one finding in a component.
   *
   * It is the same as ValeTreeConstraint's, so it is translated once.
   */
  public const string COMPONENT_ALERT = '@type "@bundle", @field: @rule: @message (line @line: "@match")';

  /**
   * The message for text in a component that Vale could not check.
   *
   * It is the same as ValeTreeConstraint's, so it is translated once.
   */
  public const string COMPONENT_UNCHECKED = '@type "@bundle", @field: Vale could not check this text. @reason';

  /**
   * Constructs a FindingMessage.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The bundle info service.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {}

  /**
   * Gives the arguments for an alert.
   *
   * @param string $field
   *   The field's label.
   * @param \Drupal\vale\Alert $alert
   *   The alert.
   *
   * @return array<string, string|int>
   *   The @field, @rule, @message, @line and @match arguments.
   */
  public function alert(string $field, Alert $alert): array {
    return [
      '@field' => $field,
      '@rule' => $alert->rule,
      '@message' => $alert->message,
      '@line' => $alert->line,
      '@match' => $alert->match,
    ];
  }

  /**
   * Gives the arguments for text Vale could not check.
   *
   * @param string $field
   *   The field's label.
   * @param string $reason
   *   Why it was not checked.
   *
   * @return array<string, string>
   *   The @field and @reason arguments.
   */
  public function unchecked(string $field, string $reason): array {
    return ['@field' => $field, '@reason' => $reason];
  }

  /**
   * Gives the arguments that name a component, during validation.
   *
   * Nothing is loaded. A paragraph's label comes from its host, which is not
   * safe to load during validation, so the bundle names it.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $component
   *   The entity.
   *
   * @return array<string, string>
   *   The @type and @bundle arguments, or none when the entity is not a
   *   component.
   */
  public function component(FieldableEntityInterface $component): array {
    if (!PublishState::isComponentType($component->getEntityType())) {
      return [];
    }
    return [
      '@type' => (string) $component->getEntityType()->getLabel(),
      '@bundle' => $this->bundleLabel($component->getEntityTypeId(), $component->bundle()),
    ];
  }

  /**
   * Gives the arguments that name the entity that holds a finding.
   *
   * They go in the template '@type "@label"'.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $label
   *   The entity's label. A component is named by its bundle instead.
   *
   * @return array<string, string>
   *   The @type and @label arguments.
   */
  public function entity(string $entity_type_id, string $bundle, string $label): array {
    $entity_type = $this->entityTypeManager->getDefinition($entity_type_id, FALSE);
    if ($entity_type && PublishState::isComponentType($entity_type)) {
      $label = $this->bundleLabel($entity_type_id, $bundle);
    }
    return [
      '@type' => $entity_type ? (string) $entity_type->getLabel() : $entity_type_id,
      '@label' => $label,
    ];
  }

  /**
   * Names the entity that holds a finding, for a message about it.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   * @param string $label
   *   The entity's label. A component is named by its bundle instead.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The name, such as 'Paragraph "Docs text"'.
   */
  public function where(string $entity_type_id, string $bundle, string $label): TranslatableMarkup {
    return $this->t('@type "@label"', $this->entity($entity_type_id, $bundle, $label));
  }

  /**
   * Gives a bundle's label.
   *
   * @param string $entity_type_id
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return string
   *   The label, or the bundle's machine name when it has none.
   */
  protected function bundleLabel(string $entity_type_id, string $bundle): string {
    return (string) ($this->entityTypeBundleInfo->getBundleInfo($entity_type_id)[$bundle]['label'] ?? $bundle);
  }

}
