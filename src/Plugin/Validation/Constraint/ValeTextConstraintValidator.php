<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\vale\Alert;
use Drupal\vale\AlertCollector;
use Drupal\vale\FieldLinter;
use Drupal\vale\FindingMessage;
use Drupal\vale\PublishState;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Lints an opted-in field and turns error-level findings into violations.
 */
final class ValeTextConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs a ValeTextConstraintValidator.
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
    if (!$value instanceof FieldItemListInterface || !$constraint instanceof ValeTextConstraint) {
      return;
    }
    $entity = $value->getEntity();
    $field = $value->getFieldDefinition()->getName();
    // Each message names its field. Drupal drops a message that repeats
    // another, so the same alert in two fields would otherwise show once.
    $label = (string) $value->getFieldDefinition()->getLabel();
    // A component is named too, as it is when its host is published.
    $component = $this->findingMessage->component($entity);
    $message = $component ? FindingMessage::COMPONENT_ALERT : $constraint->message;
    $unchecked_message = $component ? FindingMessage::COMPONENT_UNCHECKED : $constraint->uncheckedMessage;

    // In publish-only mode an error stops only the save that publishes, and
    // ValeTree reports it on the entity rather than here. JSON:API drops
    // field violations for fields a PATCH does not name, so a PATCH that only
    // publishes would never see them. A component is usually published by its
    // host, so its errors are reported when its host is published. But a
    // component saved by itself can change the text its host shows.
    $publish_only = (bool) $this->configFactory->get('vale.settings')->get('publish_only');
    $publishing = $publish_only && $this->publishState->isPublishingSave($entity);
    // With fail_closed, text Vale could not check is refused as an error is.
    $fail_closed = (bool) $this->configFactory->get('vale.settings')->get('fail_closed');

    foreach ($this->fieldLinter->lint($value) as $delta => $result) {
      $this->alertCollector->markLinted();
      if (!$result->isChecked()) {
        if (!$fail_closed || ($publish_only && !$publishing)) {
          $this->alertCollector->addUnchecked($result->reason);
        }
        elseif (!$publish_only) {
          $this->context->buildViolation($unchecked_message, $component + $this->findingMessage->unchecked($label, $result->reason))->atPath($delta . '.value')->addViolation();
        }
        continue;
      }
      $errors = new FindingList();
      foreach ($result->alerts as $alert) {
        if ($alert->level !== Alert::ERROR || ($publish_only && !$publishing)) {
          $this->alertCollector->add($entity, $field, $delta, $alert);
        }
        elseif (!$publish_only) {
          $errors->add($message, $component + $this->findingMessage->alert($label, $alert));
        }
      }
      $errors->addTo($this->context, $constraint, $delta . '.value');
    }
  }

}
