<?php

declare(strict_types=1);

namespace Drupal\vale;

/**
 * Lints text with Vale.
 */
interface ValeRunnerInterface {

  /**
   * Lints one piece of text.
   *
   * @param string $text
   *   The text.
   * @param string $extension
   *   The file extension that tells Vale how to read the text, such as "md"
   *   or "html".
   * @param string $name
   *   The name the text is linted under, without the extension. Sections in
   *   .vale.ini match on "<name>.<extension>", so fields are linted as
   *   "<entity_type>.<bundle>.<field>".
   *
   * @return \Drupal\vale\LintResult
   *   Every alert Vale reported, at every level.
   */
  public function lint(string $text, string $extension = 'html', string $name = 'text'): LintResult;

  /**
   * Gives the file extension for a text format.
   *
   * @param string|null $format
   *   A text format id, or NULL for a field with no format.
   *
   * @return string
   *   The extension the site maps the format to, or the default extension.
   */
  public function extensionForFormat(?string $format): string;

}
