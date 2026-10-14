<?php

declare(strict_types=1);

namespace Drupal\vale;

/**
 * The outcome of linting one piece of text.
 *
 * A text that was not checked is never a pass. Callers ask isChecked() before
 * they read the alerts.
 */
final readonly class LintResult {

  /**
   * Vale ran and its output was read.
   */
  public const string CHECKED = 'checked';

  /**
   * Vale is not configured, or the binary cannot be run.
   */
  public const string UNAVAILABLE = 'unavailable';

  /**
   * Vale ran but failed, ran out of time, or gave output that is not Vale's.
   */
  public const string FAILED = 'failed';

  /**
   * Constructs a LintResult.
   *
   * @param string $status
   *   One of the status constants.
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts, when the text was checked.
   * @param string $reason
   *   Why the text was not checked, or an empty string.
   */
  private function __construct(
    public string $status,
    public array $alerts = [],
    public string $reason = '',
  ) {}

  /**
   * Makes the result of a run that was read.
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts.
   *
   * @return static
   *   The result.
   */
  public static function checked(array $alerts): static {
    return new self(self::CHECKED, array_values($alerts));
  }

  /**
   * Makes the result for when Vale cannot be run.
   *
   * @param string $reason
   *   Why.
   *
   * @return static
   *   The result.
   */
  public static function unavailable(string $reason): static {
    return new self(self::UNAVAILABLE, [], $reason);
  }

  /**
   * Makes the result for when Vale ran but gave no usable answer.
   *
   * @param string $reason
   *   Why.
   *
   * @return static
   *   The result.
   */
  public static function failed(string $reason): static {
    return new self(self::FAILED, [], $reason);
  }

  /**
   * Says whether the text was checked.
   *
   * @return bool
   *   TRUE when Vale ran and its output was read.
   */
  public function isChecked(): bool {
    return $this->status === self::CHECKED;
  }

  /**
   * Gives a copy of the result with other alerts.
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts.
   *
   * @return static
   *   The copy.
   */
  public function withAlerts(array $alerts): static {
    return new self($this->status, array_values($alerts), $this->reason);
  }

}
