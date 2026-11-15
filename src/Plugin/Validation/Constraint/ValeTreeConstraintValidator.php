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
use Drupal\vale\FindingMessage;
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
   * @param \Drupal\vale\FindingMessage $findingMessage
   *   The finding message builder.
   */
  public function __construct(
    protected FieldLinter $fieldLinter,
    protected AlertCollector $alertCollector,
    protected PublishState $publishState,
    protected ConfigFactoryInterface $configFactory,
    protected FindingMessage $findingMessage,
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
      $container->get('vale.finding_message'),
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
      || !$this->publishState->isPublishingSave($value)
    ) {
      return;
    }
    // ValeText collects the warnings in the entity's own fields, and leaves
    // their errors to this constraint. It runs after this one, as Drupal
    // validates the entity before its fields; FieldLinter remembers a failed
    // run, so the text is not run twice.
    $errors = new FindingList();
    // A component saved by itself is named, as it is when its host is
    // published.
    $component = $this->findingMessage->component($value);
    foreach ($value->getFieldDefinitions() as $name => $definition) {
      foreach ($this->fieldLinter->lint($value->get($name)) as $result) {
        // ValeText notes unchecked text in the entity's own fields, unless
        // it refuses the save.
        if (!$result->isChecked() && $this->configFactory->get('vale.settings')->get('fail_closed')) {
          $errors->add($component ? $constraint->uncheckedMessage : $constraint->uncheckedFieldMessage, $component + $this->findingMessage->unchecked((string) $definition->getLabel(), $result->reason));
        }
        foreach ($result->isChecked() ? $result->alerts : [] as $alert) {
          if ($alert->level === Alert::ERROR) {
            $errors->add($component ? $constraint->message : $constraint->fieldMessage, $component + $this->findingMessage->alert((string) $definition->getLabel(), $alert));
          }
        }
      }
    }
    $this->checkComponents($value, $constraint, $errors, 1);
    $errors->addTo($this->context, $constraint);
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
        // Only a component would have been checked, as ContentWalker::walk()
        // follows no other reference.
        if ($this->holdsComponent($entity, $name)) {
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
            $errors->add($constraint->uncheckedMessage, $this->findingMessage->component($component) + $this->findingMessage->unchecked((string) $definition->getLabel(), $result->reason));
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
          // Validating the component by itself, as the Paragraphs widget
          // does, listed this error as a warning. Here it refuses the save.
          $this->alertCollector->remove($component, $name, $delta, $alert);
          $errors->add($constraint->message, $this->findingMessage->component($component) + $this->findingMessage->alert((string) $definition->getLabel(), $alert));
        }
      }
    }
  }

  /**
   * Says whether a reference field of an entity holds a component.
   *
   * @param \Drupal\Core\Entity\FieldableEntityInterface $entity
   *   The entity.
   * @param string $name
   *   The name of an entity_reference_revisions field.
   *
   * @return bool
   *   TRUE when an item references a component, such as a paragraph.
   */
  protected function holdsComponent(FieldableEntityInterface $entity, string $name): bool {
    foreach ($entity->get($name) as $item) {
      if ($this->publishState->component($item->get('entity')->getValue(), $entity->language()->getId())) {
        return TRUE;
      }
    }
    return FALSE;
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
