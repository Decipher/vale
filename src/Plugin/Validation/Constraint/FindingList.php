<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Validation\Constraint;

/**
 * Joins several findings into one violation message.
 *
 * Form API keeps only the first error set on an element. So one violation
 * per finding would show an author one finding, and the next only after
 * they fixed it and saved again. One violation per value, with a line per
 * finding, shows them all.
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
   * Says whether there are no findings.
   *
   * @return bool
   *   TRUE when nothing was added.
   */
  public function isEmpty(): bool {
    return $this->lines === [];
  }

  /**
   * Gives the joined template and its arguments.
   *
   * Each finding's placeholders get its index, so "@rule" in the second
   * finding becomes "@rule1". Findings are separated by a line break, and a
   * newline keeps them apart where the markup is stripped, as in JSON:API.
   *
   * @return array{0: string, 1: array<string, string|int>}
   *   The template and the arguments.
   */
  public function message(): array {
    $templates = [];
    $arguments = [];
    foreach ($this->lines as $index => [$template, $values]) {
      $renames = [];
      foreach ($values as $name => $value) {
        $renames[$name] = $name . $index;
        $arguments[$name . $index] = $value;
      }
      // Longest names first, so "@rule" never replaces part of "@rules".
      uksort($renames, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
      $templates[] = strtr($template, $renames);
    }
    return [implode("<br>\n", $templates), $arguments];
  }

}
