<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\vale\Canvas\ComponentTreeProse;

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
  public const array PLAIN_TYPES = ['string', 'string_long'];

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

  /**
   * Constructs a FieldLinter.
   *
   * @param \Drupal\vale\ValeRunnerInterface $runner
   *   The Vale runner.
   * @param \Drupal\vale\Canvas\ComponentTreeProse|null $componentTreeProse
   *   The reader of Canvas component trees, when Canvas is installed.
   */
  public function __construct(
    protected ValeRunnerInterface $runner,
    protected ?ComponentTreeProse $componentTreeProse = NULL,
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
   * @param string|null $langcode
   *   The langcode of the text, or NULL for English.
   *
   * @return \Drupal\vale\LintResult
   *   The result, with only the alerts the field's settings report.
   */
  public function lintText(FieldDefinitionInterface $field, string $text, ?string $format, bool $store = TRUE, ?string $langcode = NULL): LintResult {
    return $this->lintWith(FieldSettings::forField($field), $field, $text, $format, $store, langcode: $langcode);
  }

  /**
   * Lints text with a field's settings already read.
   *
   * @param \Drupal\vale\FieldSettings $settings
   *   The field's settings.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   * @param string $text
   *   The text.
   * @param string|null $format
   *   The text format, or NULL for plain text.
   * @param bool $store
   *   Whether to cache the result.
   * @param bool $plain
   *   Whether the text is plain text, whatever the field type.
   * @param string|null $langcode
   *   The langcode of the text, or NULL for English.
   *
   * @return \Drupal\vale\LintResult
   *   The result.
   */
  protected function lintWith(FieldSettings $settings, FieldDefinitionInterface $field, string $text, ?string $format, bool $store = TRUE, bool $plain = FALSE, ?string $langcode = NULL): LintResult {
    $extension = $plain || in_array($field->getType(), self::PLAIN_TYPES, TRUE) ? 'txt' : $this->runner->extensionForFormat($format);
    $name = $settings->name($field);
    $key = hash('sha256', $name . "\0" . $extension . "\0" . $langcode . "\0" . $text);
    $result = $this->failed[$key] ?? $this->runner->lint($text, $extension, $name, $store, $langcode);
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
   *   The results, keyed by delta. Empty when the field has not opted in, or
   *   holds more than one text per delta, as a component tree does.
   */
  public function lint(FieldItemListInterface $items): array {
    $field = $items->getFieldDefinition();
    $settings = FieldSettings::forField($field);
    if (!$settings->enabled || $field->getType() === FieldSettings::COMPONENT_TREE) {
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
      // Each value is read in the language of its translation.
      $results[$delta] = $this->lintWith($settings, $field, $value, is_string($format) ? $format : NULL, langcode: $items->getLangcode());
    }
    return $results;
  }

  /**
   * Lints every value of a field, with where each value is.
   *
   * Unlike lint(), this covers a Canvas component tree, where one delta can
   * hold several texts.
   *
   * @param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface> $items
   *   The field's values.
   *
   * @return list<\Drupal\vale\ValueResult>
   *   The results. Empty when the field has not opted in.
   */
  public function lintValues(FieldItemListInterface $items): array {
    $field = $items->getFieldDefinition();
    if ($field->getType() !== FieldSettings::COMPONENT_TREE) {
      $results = [];
      foreach ($this->lint($items) as $delta => $result) {
        $results[] = new ValueResult($delta, $result);
      }
      return $results;
    }
    $settings = FieldSettings::forField($field);
    if (!$settings->enabled || !$this->componentTreeProse) {
      return [];
    }
    $results = [];
    foreach ($this->componentTreeProse->values($items, $settings->skippedProps) as $value) {
      $result = $this->lintWith($settings, $field, $value['text'], $value['format'], plain: $value['format'] === NULL, langcode: $items->getLangcode());
      $results[] = new ValueResult($value['delta'], $result, $value['uuid'], $value['prop'], $value['component']);
    }
    return $results;
  }

}
