<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vale\Alert;
use Drupal\vale\AlertCollector;
use Drupal\vale\FieldLinter;
use Drupal\vale\PublishState;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Lints an entity and its components when the entity is published.
 *
 * The violations are on the entity, not on a field. JSON:API keeps entity
 * violations on a PATCH that names only some fields, so a PATCH that only
 * changes the status is still refused.
 */
final class ValeTreeConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * How deep components are followed.
   */
  private const int MAX_DEPTH = 10;

  /**
   * Constructs a ValeTreeConstraintValidator.
   *
   * @param \Drupal\vale\FieldLinter $fieldLinter
   *   The field linter.
   * @param \Drupal\vale\AlertCollector $alertCollector
   *   The alert collector.
   * @param \Drupal\vale\PublishState $publishState
   *   The publish state service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    protected FieldLinter $fieldLinter,
    protected AlertCollector $alertCollector,
    protected PublishState $publishState,
    protected ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('vale.field_linter'),
      $container->get('vale.alert_collector'),
      $container->get('vale.publish_state'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    if (
      !$value instanceof FieldableEntityInterface
      || !$constraint instanceof ValeTreeConstraint
      || !$this->configFactory->get('vale.settings')->get('publish_only')
      || $this->publishState->isComponent($value)
      || !$this->publishState->isBeingPublished($value)
    ) {
      return;
    }
    // ValeText has already collected the warnings in the entity's own
    // fields, and left their errors to this constraint.
    $errors = new FindingList();
    foreach ($value->getFieldDefinitions() as $name => $definition) {
      foreach ($this->fieldLinter->lint($value->get($name)) as $result) {
        // ValeText has already noted unchecked text in the entity's own
        // fields, unless it refuses the save.
        if (!$result->isChecked() && $this->configFactory->get('vale.settings')->get('fail_closed')) {
          $errors->add($constraint->uncheckedFieldMessage, [
            '@field' => (string) $definition->getLabel(),
            '@reason' => $result->reason,
          ]);
        }
        foreach ($result->isChecked() ? $result->alerts : [] as $alert) {
          if ($alert->level === Alert::ERROR) {
            $errors->add($constraint->fieldMessage, [
              '@field' => (string) $definition->getLabel(),
              '@rule' => $alert->rule,
              '@message' => $alert->message,
              '@line' => $alert->line,
              '@match' => $alert->match,
            ]);
          }
        }
      }
    }
    $this->checkComponents($value, $constraint, $errors, 1);
    if (!$errors->isEmpty()) {
      [$template, $arguments] = $errors->message();
      $this->context->addViolation($template, $arguments);
    }
  }

  /**
   * Lints the components inside an entity.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param \Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint $constraint
   *   The constraint.
   * @param \Drupal\vale\Plugin\Validation\Constraint\FindingList $errors
   *   The error-level findings so far.
   * @param int $depth
   *   How deep the components are.
   */
  protected function checkComponents(FieldableEntityInterface $entity, ValeTreeConstraint $constraint, FindingList $errors, int $depth): void {
    if ($depth > self::MAX_DEPTH) {
      foreach ($this->componentFields($entity) as $name) {
        if (!$entity->get($name)->isEmpty()) {
          $reason = (string) new TranslatableMarkup('Components nested more than @depth levels deep were not checked.', ['@depth' => self::MAX_DEPTH]);
          // With fail_closed, unchecked text refuses the save, however deep.
          if ($this->configFactory->get('vale.settings')->get('fail_closed')) {
            $errors->add($constraint->uncheckedFieldMessage, [
              '@field' => $this->alertCollector->label($entity),
              '@reason' => $reason,
            ]);
          }
          else {
            $this->alertCollector->addUnchecked($reason);
          }
          return;
        }
      }
      return;
    }
    foreach ($this->componentFields($entity) as $name) {
      foreach ($entity->get($name) as $item) {
        // Only components are published with the entity. A translation's
        // components are checked in its language.
        $component = $this->publishState->component($item->get('entity')->getValue(), $entity->language()->getId());
        if ($component) {
          $this->checkComponent($component, $constraint, $errors);
          $this->checkComponents($component, $constraint, $errors, $depth + 1);
        }
      }
    }
  }

  /**
   * Lints the fields of one component.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $component
   *   The component.
   * @param \Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint $constraint
   *   The constraint.
   * @param \Drupal\vale\Plugin\Validation\Constraint\FindingList $errors
   *   The error-level findings so far.
   */
  protected function checkComponent(FieldableEntityInterface $component, ValeTreeConstraint $constraint, FindingList $errors): void {
    foreach ($component->getFieldDefinitions() as $name => $definition) {
      foreach ($this->fieldLinter->lint($component->get($name)) as $delta => $result) {
        $this->alertCollector->markLinted();
        if (!$result->isChecked()) {
          if ($this->configFactory->get('vale.settings')->get('fail_closed')) {
            $errors->add($constraint->uncheckedMessage, [
              '@type' => (string) $component->getEntityType()->getLabel(),
              '@bundle' => $this->alertCollector->label($component),
              '@field' => (string) $definition->getLabel(),
              '@reason' => $result->reason,
            ]);
          }
          else {
            $this->alertCollector->addUnchecked($result->reason);
          }
          continue;
        }
        foreach ($result->alerts as $alert) {
          if ($alert->level !== Alert::ERROR) {
            $this->alertCollector->add($component, $name, $delta, $alert);
            continue;
          }
          $errors->add($constraint->message, [
            '@type' => (string) $component->getEntityType()->getLabel(),
            '@bundle' => $this->alertCollector->label($component),
            '@field' => (string) $definition->getLabel(),
            '@rule' => $alert->rule,
            '@message' => $alert->message,
            '@line' => $alert->line,
            '@match' => $alert->match,
          ]);
        }
      }
    }
  }

  /**
   * Lists the fields of an entity that hold components.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   *
   * @return string[]
   *   The names of its entity_reference_revisions fields.
   */
  protected function componentFields(FieldableEntityInterface $entity): array {
    $names = [];
    foreach ($entity->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() === 'entity_reference_revisions') {
        $names[] = $name;
      }
    }
    return $names;
  }

}
