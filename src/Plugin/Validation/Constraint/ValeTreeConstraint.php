<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Refuses publishing an entity that has error-level findings.
 *
 * It applies only in publish-only mode, where drafts save freely and
 * publication is the moment the errors must be fixed. It covers the entity's
 * own fields and its components, such as paragraphs, and reports on the
 * entity, because publishing is a decision about the whole entity.
 */
#[Constraint(
  id: 'ValeTree',
  label: new TranslatableMarkup('Vale prose check of components', [], ['context' => 'Validation']),
)]
class ValeTreeConstraint extends SymfonyConstraint {

  /**
   * The message for one finding in a component.
   *
   * @var string
   */
  public $message = '@type "@bundle", @field: @rule: @message (line @line: "@match")';

  /**
   * The message for one finding in the entity's own field.
   *
   * @var string
   */
  public $fieldMessage = '@field: @rule: @message (line @line: "@match")';

  /**
   * The message for text in a component that Vale could not check.
   *
   * @var string
   */
  public $uncheckedMessage = '@type "@bundle", @field: Vale could not check this text. @reason';

  /**
   * The message for text in the entity's own field that Vale could not check.
   *
   * @var string
   */
  public $uncheckedFieldMessage = '@field: Vale could not check this text. @reason';

}
