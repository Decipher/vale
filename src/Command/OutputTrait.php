<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Prints a command's results as a table, JSON or CSV.
 *
 * The commands run under core's dr and under Drush 12 to 14, so they render
 * their own output rather than rely on Drush's formatters.
 */
trait OutputTrait {

  /**
   * Adds the --format and --fields options.
   */
  protected function addOutputOptions(): static {
    return $this
      ->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'The output format: table, json or csv.', 'table')
      ->addOption('fields', NULL, InputOption::VALUE_REQUIRED, 'The fields to show, by name or label, separated by commas.');
  }

  /**
   * Gives the fields to print, from --format and --fields.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param array<string, string> $labels
   *   The label of each field, keyed by field name.
   * @param string[]|null $table_fields
   *   The fields a table shows when --fields is not given. NULL shows all.
   *
   * @return string[]
   *   The field names, in order.
   *
   * @throws \InvalidArgumentException
   *   When the format or a field is not known.
   */
  protected function outputFields(InputInterface $input, array $labels, ?array $table_fields = NULL): array {
    $format = $input->getOption('format');
    if (!in_array($format, ['table', 'json', 'csv'], TRUE)) {
      throw new \InvalidArgumentException(sprintf('The format "%s" is not known. Use table, json or csv.', is_string($format) ? $format : ''));
    }
    $fields = $input->getOption('fields');
    if (!is_string($fields) || trim($fields) === '') {
      return $format === 'table' && $table_fields !== NULL ? $table_fields : array_keys($labels);
    }
    $names = [];
    foreach (explode(',', $fields) as $field) {
      $field = trim($field);
      $name = isset($labels[$field]) ? $field : array_search($field, $labels, TRUE);
      if (!is_string($name)) {
        throw new \InvalidArgumentException(sprintf('The field "%s" is not known. Use one of %s.', $field, implode(', ', array_keys($labels))));
      }
      $names[] = $name;
    }
    // A field named twice, such as by name and by label, is printed once.
    return array_values(array_unique($names));
  }

  /**
   * Prints rows, one per record.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param array<int|string, array<string, mixed>> $rows
   *   The rows. Their keys are kept in JSON.
   * @param array<string, string> $labels
   *   The label of each field, keyed by field name.
   * @param string[] $fields
   *   The fields to print, from outputFields().
   */
  protected function writeRows(InputInterface $input, OutputInterface $output, array $rows, array $labels, array $fields): void {
    $rows = array_map(static fn (array $row): array => self::pick($row, $fields), $rows);
    $headers = array_map(static fn (string $field): string => $labels[$field], $fields);
    switch ($input->getOption('format')) {
      case 'json':
        $output->writeln(self::json($rows), OutputInterface::OUTPUT_RAW);
        break;

      case 'csv':
        $output->write(self::csv([$headers, ...array_values($rows)]), FALSE, OutputInterface::OUTPUT_RAW);
        break;

      default:
        // An empty table prints nothing, as Drush's did.
        if ($rows) {
          (new SymfonyStyle($input, $output))->table(
            array_map(OutputFormatter::escape(...), $headers),
            array_map(static fn (array $row): array => array_map(static fn (mixed $value): string => OutputFormatter::escape((string) $value), array_values($row)), array_values($rows)),
          );
        }
    }
  }

  /**
   * Prints the properties of one record.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param array<string, mixed> $properties
   *   The properties, keyed by field name.
   * @param array<string, string> $labels
   *   The label of each field, keyed by field name.
   * @param string[] $fields
   *   The fields to print, from outputFields().
   */
  protected function writeProperties(InputInterface $input, OutputInterface $output, array $properties, array $labels, array $fields): void {
    $properties = self::pick($properties, $fields);
    switch ($input->getOption('format')) {
      case 'json':
        $output->writeln(self::json($properties), OutputInterface::OUTPUT_RAW);
        break;

      case 'csv':
        $headers = array_map(static fn (string $field): string => $labels[$field], $fields);
        $output->write(self::csv([$headers, $properties]), FALSE, OutputInterface::OUTPUT_RAW);
        break;

      default:
        $width = max(0, ...array_map(static fn (string $field): int => mb_strlen($labels[$field]), $fields));
        foreach ($properties as $field => $value) {
          $label = $labels[$field] . str_repeat(' ', $width - mb_strlen($labels[$field]));
          $output->writeln(sprintf(' %s : %s', OutputFormatter::escape($label), OutputFormatter::escape((string) $value)));
        }
    }
  }

  /**
   * Prints an error to the error output.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param string $message
   *   The error.
   */
  protected function writeError(InputInterface $input, OutputInterface $output, string $message): void {
    $error_output = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    (new SymfonyStyle($input, $error_output))->error($message);
  }

  /**
   * Gives the fields of a row, in the order given.
   *
   * @param array<string, mixed> $row
   *   The row.
   * @param string[] $fields
   *   The field names.
   *
   * @return array<string, mixed>
   *   The row with only those fields.
   */
  private static function pick(array $row, array $fields): array {
    $picked = [];
    foreach ($fields as $field) {
      $picked[$field] = $row[$field] ?? '';
    }
    return $picked;
  }

  /**
   * Encodes data as JSON, as Drush printed it.
   *
   * @param array<mixed> $data
   *   The data.
   *
   * @return string
   *   The JSON.
   */
  private static function json(array $data): string {
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
  }

  /**
   * Encodes rows as CSV.
   *
   * @param array<array<mixed>> $rows
   *   The rows, the first of them the header.
   *
   * @return string
   *   The CSV.
   */
  private static function csv(array $rows): string {
    $stream = fopen('php://temp', 'r+');
    assert($stream !== FALSE);
    foreach ($rows as $row) {
      fputcsv($stream, array_map(static fn (mixed $value): string => (string) $value, array_values($row)), ',', '"', '');
    }
    rewind($stream);
    $csv = (string) stream_get_contents($stream);
    fclose($stream);
    return $csv;
  }

}
