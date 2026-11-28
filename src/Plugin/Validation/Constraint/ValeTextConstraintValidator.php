<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\vale\Alert;
use Drupal\vale\AlertCollector;
use Drupal\vale\FieldLinter;
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
   * @param \Drupal\Core\Routing\RouteMatchInterface $routeMatch
   *   The current route match.
   */
  public function __construct(
    protected FieldLinter $fieldLinter,
    protected AlertCollector $alertCollector,
    protected PublishState $publishState,
    protected ConfigFactoryInterface $configFactory,
    protected RouteMatchInterface $routeMatch,
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
      $container->get('current_route_match'),
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

    foreach ($this->fieldLinter->lintValues($value) as $located) {
      $this->alertCollector->markLinted();
      $result = $located->result;
      // A value in a component tree is named by its component and prop, and
      // the violation points at them, so Canvas links it to the component.
      $place = $located->place();
      $named = $place === '' ? $label : $label . ' > ' . $place;
      if (!$result->isChecked()) {
        if (!$fail_closed || ($publish_only && !$publishing)) {
          $this->alertCollector->addUnchecked($result->reason);
        }
        elseif (!$publish_only) {
          $unchecked = FindingList::forRoute($this->routeMatch);
          $unchecked->add($constraint->uncheckedMessage, [
            '@field' => $named,
            '@reason' => $result->reason,
          ]);
          $unchecked->addTo($this->context, $constraint, $located->path());
        }
        continue;
      }
      $errors = FindingList::forRoute($this->routeMatch);
      foreach ($result->alerts as $alert) {
        if ($alert->level !== Alert::ERROR || ($publish_only && !$publishing)) {
          $this->alertCollector->add($entity, $field, $located->delta, $alert, $place);
        }
        elseif (!$publish_only) {
          $errors->add($constraint->message, [
            '@field' => $named,
            '@rule' => $alert->rule,
            '@message' => $alert->message,
            '@line' => $alert->line,
            '@match' => $alert->match,
          ]);
        }
      }
      $errors->addTo($this->context, $constraint, $located->path());
    }
  }

}
