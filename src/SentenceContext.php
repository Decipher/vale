<?php

declare(strict_types=1);

namespace Drupal\vale;

/**
 * Finds the sentence an alert sits in.
 *
 * An editor, or a prompt that asks for a rewrite, needs the whole sentence,
 * not only the matched words. A sentence ends at ".", "!" or "?" followed by
 * a space, and never crosses a line.
 */
final class SentenceContext {

  /**
   * Gives the sentence around a span.
   *
   * @param string $text
   *   The text that was linted.
   * @param \Drupal\vale\Alert $alert
   *   The alert.
   *
   * @return string
   *   The sentence, with markup removed and spaces collapsed.
   */
  public static function around(string $text, Alert $alert): string {
    $lines = preg_split('/\R/u', $text) ?: [];
    $line = $lines[$alert->line - 1] ?? '';
    $characters = mb_str_split($line);
    $start = max(0, min(count($characters), $alert->start - 1));
    $end = max($start, min(count($characters), $alert->end));

    $from = $start;
    while ($from > 0 && !self::endsSentence($characters, $from - 1)) {
      $from--;
    }
    $to = $end;
    while ($to < count($characters) && !self::endsSentence($characters, $to - 1)) {
      $to++;
    }

    $sentence = implode('', array_slice($characters, $from, $to - $from));
    $sentence = html_entity_decode(strip_tags($sentence), ENT_QUOTES | ENT_HTML5);
    return trim((string) preg_replace('/\s+/u', ' ', $sentence));
  }

  /**
   * Says whether a sentence ends at a character.
   *
   * @param string[] $characters
   *   The characters of the line.
   * @param int $index
   *   The character to look at.
   *
   * @return bool
   *   TRUE when the character is ".", "!" or "?" and a space follows it.
   */
  private static function endsSentence(array $characters, int $index): bool {
    return in_array($characters[$index] ?? '', ['.', '!', '?'], TRUE)
      && preg_match('/\s/u', $characters[$index + 1] ?? '') === 1;
  }

}
