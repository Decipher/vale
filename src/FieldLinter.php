<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Lints the values of a field, with that field's settings applied.
 *
 * Validation, the check endpoint, Drush and the workspace check all lint
 * through here, so a finding is the same wherever it is seen.
 */
class FieldLinter {

  /**
   * Field types that hold plain text, which is linted as text.
   */
  protected const array PLAIN_TYPES = ['string', 'string_long'];

  /**
   * Constructs a FieldLinter.
   *
   * @param \Drupal\vale\ValeRunnerInterface $runner
   *   The Vale runner.
   */
  /**
   * How many failed runs are remembered for a request.
   */
  protected const int REMEMBERED = 50;

  /**
   * Results of Vale runs that failed in this request, keyed by their input.
   *
   * A successful result is cached by the runner, but a failed one is not. A
   * publish-only save checks some values twice, so this stops a failing Vale
   * running again for the same text in the same request.
   *
   * @var array<string, \Drupal\vale\LintResult>
   */
  protected array $failed = [];

  public function __construct(
    protected ValeRunnerInterface $runner,
  ) {}

  /**
   * Says whether a field is linted.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return bool
   *   TRUE when the field has opted in.
   */
  public function isEnabled(FieldDefinitionInterface $field): bool {
    return FieldSettings::forField($field)->enabled;
  }

  /**
   * Lints a piece of text as a value of a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param string $text
   *   The text.
   * @param string|null $format
   *   The text format, or NULL for a field without one.
   * @param bool $store
   *   Whether to cache the result.
   *
   * @return \Drupal\vale\LintResult
   *   The result, with only the alerts the field's settings report.
   */
  public function lintText(FieldDefinitionInterface $field, string $text, ?string $format, bool $store = TRUE): LintResult {
    $settings = FieldSettings::forField($field);
    $extension = in_array($field->getType(), self::PLAIN_TYPES, TRUE) ? 'txt' : $this->runner->extensionForFormat($format);
    $name = $settings->name($field);
    $key = hash('sha256', $name . "\0" . $extension . "\0" . $text);
    $result = $this->failed[$key] ?? $this->runner->lint($text, $extension, $name, $store);
    if ($result->status === LintResult::FAILED) {
      $this->failed[$key] = $result;
      if (count($this->failed) > self::REMEMBERED) {
        array_shift($this->failed);
      }
    }
    return $result->withAlerts($settings->filter($result->alerts));
  }

  /**
   * Lints every value of a field.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field's values.
   *
   * @return array<int, \Drupal\vale\LintResult>
   *   The results, keyed by delta. Empty when the field has not opted in.
   */
  public function lint(FieldItemListInterface $items): array {
    $field = $items->getFieldDefinition();
    if (!$this->isEnabled($field)) {
      return [];
    }
    $results = [];
    foreach ($items as $delta => $item) {
      $value = $item->get('value')->getValue();
      if (!is_string($value) || trim($value) === '') {
        continue;
      }
      $format = $item->getProperties()['format'] ?? NULL;
      $format = $format?->getValue();
      $results[$delta] = $this->lintText($field, $value, is_string($format) ? $format : NULL);
    }
    return $results;
  }

}
