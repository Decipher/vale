<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Refuses a field value that has an error-level Vale finding.
 */
#[Constraint(
  id: 'ValeText',
  label: new TranslatableMarkup('Vale prose check', [], ['context' => 'Validation']),
)]
class ValeTextConstraint extends SymfonyConstraint {

  /**
   * The message for one finding.
   *
   * @var string
   */
  public $message = '@field: @rule: @message (line @line: "@match")';

  /**
   * The message for text Vale could not check, when that refuses the save.
   *
   * @var string
   */
  public $uncheckedMessage = '@field: Vale could not check this text. @reason';

}
