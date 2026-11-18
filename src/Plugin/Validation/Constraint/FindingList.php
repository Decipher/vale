<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Joins several findings into one violation message.
 *
 * Form API keeps only the first error set on an element. So one violation
 * per finding would show an author one finding, and the next only after
 * they fixed it and saved again. One violation per value, with a line per
 * finding, shows them all.
 *
 * @internal
 */
final class FindingList {

  /**
   * The findings, each a message template and its arguments.
   *
   * @var list<array{0: string, 1: array<string, string|int>}>
   */
  private array $lines = [];

  /**
   * Adds a finding.
   *
   * @param string $template
   *   The message template, with @ placeholders.
   * @param array<string, string|int> $arguments
   *   The placeholder values.
   */
  public function add(string $template, array $arguments): void {
    $this->lines[] = [$template, $arguments];
  }

  /**
   * Adds the findings to the context as one violation.
   *
   * One finding is an ordinary violation, so its template is translated as
   * it is. Several findings are each translated by themselves, with their own
   * placeholders, and then joined. So each template is translated once,
   * whatever findings it is shown with. Findings are separated by a line
   * break, and a newline keeps them apart where the markup is stripped, as
   * in JSON:API.
   *
   * @param \Symfony\Component\Validator\Context\ExecutionContextInterface $context
   *   The validation context.
   * @param \Symfony\Component\Validator\Constraint $constraint
   *   The constraint the findings break.
   * @param string $path
   *   The property path of the violation, relative to the context's path.
   */
  public function addTo(ExecutionContextInterface $context, Constraint $constraint, string $path = ''): void {
    if ($this->lines === []) {
      return;
    }
    if (count($this->lines) === 1) {
      [$template, $arguments] = $this->lines[0];
      $context->buildViolation($template, $arguments)->atPath($path)->addViolation();
      return;
    }
    $templates = [];
    $parts = [];
    foreach ($this->lines as $index => [$template, $arguments]) {
      $templates[] = $template;
      // Each template is a constraint message, a literal string.
      // phpcs:ignore Drupal.Semantics.FunctionT.NotLiteralString
      $parts['@finding' . $index] = new TranslatableMarkup($template, $arguments);
    }
    // The joined message is already translated. A violation builder would
    // translate it again, as one new string for each set of findings, so the
    // violation is added directly.
    $context->getViolations()->add(new ConstraintViolation(
      new FormattableMarkup(implode("<br>\n", array_keys($parts)), $parts),
      implode("<br>\n", $templates),
      [],
      $context->getRoot(),
      $context->getPropertyPath($path),
      $context->getValue(),
      NULL,
      NULL,
      $constraint,
    ));
  }

}
