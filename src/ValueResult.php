<?php

declare(strict_types=1);

namespace Drupal\vale;

/**
 * The result of linting one value of a field, with where the value is.
 *
 * Most fields hold one text per delta. A Canvas component tree holds one
 * component instance per delta, and each instance can hold several props
 * with text in them, so those values also name the instance and the prop.
 */
final readonly class ValueResult {

  /**
   * Constructs a ValueResult.
   *
   * @param int $delta
   *   The field delta.
   * @param \Drupal\vale\LintResult $result
   *   The lint result.
   * @param string $component
   *   The UUID of the component instance, or an empty string.
   * @param string $prop
   *   The prop name, or an empty string.
   * @param string $componentLabel
   *   The component instance's label, or an empty string.
   */
  public function __construct(
    public int $delta,
    public LintResult $result,
    public string $component = '',
    public string $prop = '',
    public string $componentLabel = '',
  ) {}

  /**
   * Gives the property path of the value, relative to its field.
   *
   * Canvas's review panel links an error to a component when the path holds
   * the instance's UUID, as Canvas's own errors do.
   *
   * @return string
   *   A path such as "0.value", or "3.inputs.<uuid>.heading".
   */
  public function path(): string {
    if ($this->component === '') {
      return $this->delta . '.value';
    }
    return implode('.', [$this->delta, 'inputs', $this->component, $this->prop]);
  }

  /**
   * Names where in the field the value is, for a message.
   *
   * @return string
   *   Such as "Hero > heading", or an empty string for an ordinary value.
   */
  public function place(): string {
    return $this->component === '' ? '' : $this->componentLabel . ' > ' . $this->prop;
  }

}
