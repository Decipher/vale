<?php

declare(strict_types=1);

namespace Drupal\vale;

/**
 * One finding that Vale reported for a piece of text.
 *
 * The line and the span refer to the text that was linted. Lines count from
 * 1. The span is the first and the last column of the match on that line,
 * both counted from 1 and in characters, not bytes, as Vale reports them.
 */
final readonly class Alert {

  public const string ERROR = 'error';

  public const string WARNING = 'warning';

  public const string SUGGESTION = 'suggestion';

  /**
   * The levels in order of weight, the lowest first.
   */
  public const array LEVELS = [self::SUGGESTION, self::WARNING, self::ERROR];

  /**
   * Constructs an Alert.
   *
   * @param string $rule
   *   The rule that raised the alert, such as "Demo.Very".
   * @param string $level
   *   The level: error, warning or suggestion.
   * @param string $message
   *   The rule's message, as Vale filled it in.
   * @param int $line
   *   The line of the match.
   * @param int $start
   *   The first column of the match.
   * @param int $end
   *   The last column of the match.
   * @param string $match
   *   The text that matched.
   * @param string $description
   *   The rule's description, or an empty string.
   * @param string $link
   *   A link that explains the rule, or an empty string.
   */
  public function __construct(
    public string $rule,
    public string $level,
    public string $message,
    public int $line,
    public int $start,
    public int $end,
    public string $match = '',
    public string $description = '',
    public string $link = '',
  ) {
    if (!in_array($level, self::LEVELS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown alert level "%s".', $level));
    }
  }

  /**
   * Gives the weight of a level, so levels can be compared.
   *
   * @param string $level
   *   A level.
   *
   * @return int
   *   0 for a suggestion, 1 for a warning and 2 for an error. -1 for anything
   *   else.
   */
  public static function weight(string $level): int {
    $weight = array_search($level, self::LEVELS, TRUE);
    return $weight === FALSE ? -1 : $weight;
  }

  /**
   * Makes a copy of this alert at another level.
   *
   * @param string $level
   *   The new level.
   *
   * @return static
   *   The copy.
   */
  public function withLevel(string $level): static {
    return new self($this->rule, $level, $this->message, $this->line, $this->start, $this->end, $this->match, $this->description, $this->link);
  }

  /**
   * Makes an alert from one item of Vale's JSON output.
   *
   * @param array<mixed> $item
   *   The decoded item.
   *
   * @return static|null
   *   The alert, or NULL when the item does not have the shape Vale gives.
   */
  public static function fromVale(array $item): ?static {
    $span = $item['Span'] ?? NULL;
    if (
      !is_string($item['Check'] ?? NULL)
      || !in_array($item['Severity'] ?? NULL, self::LEVELS, TRUE)
      || !is_string($item['Message'] ?? NULL)
      || !is_int($item['Line'] ?? NULL)
      || !is_array($span) || !is_int($span[0] ?? NULL) || !is_int($span[1] ?? NULL)
    ) {
      return NULL;
    }
    return new self(
      $item['Check'],
      $item['Severity'],
      $item['Message'],
      $item['Line'],
      $span[0],
      $span[1],
      is_string($item['Match'] ?? NULL) ? $item['Match'] : '',
      is_string($item['Description'] ?? NULL) ? $item['Description'] : '',
      is_string($item['Link'] ?? NULL) ? $item['Link'] : '',
    );
  }

  /**
   * Gives the alert as an array, for the cache and for JSON.
   *
   * @return array{rule: string, level: string, message: string, line: int, start: int, end: int, match: string, description: string, link: string}
   *   The alert's values.
   */
  public function toArray(): array {
    return [
      'rule' => $this->rule,
      'level' => $this->level,
      'message' => $this->message,
      'line' => $this->line,
      'start' => $this->start,
      'end' => $this->end,
      'match' => $this->match,
      'description' => $this->description,
      'link' => $this->link,
    ];
  }

  /**
   * Makes an alert from the array that toArray() gives.
   *
   * @param array{rule: string, level: string, message: string, line: int, start: int, end: int, match: string, description: string, link: string} $values
   *   The alert's values.
   *
   * @return static
   *   The alert.
   */
  public static function fromArray(array $values): static {
    return new self(
      $values['rule'],
      $values['level'],
      $values['message'],
      $values['line'],
      $values['start'],
      $values['end'],
      $values['match'],
      $values['description'],
      $values['link'],
    );
  }

}
