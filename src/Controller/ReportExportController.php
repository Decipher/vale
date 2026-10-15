<?php

declare(strict_types=1);

namespace Drupal\vale\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\vale\FindingStorage;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exports the content report as CSV.
 *
 * It takes the same root_bundle, level and rule filters as the report.
 */
final readonly class ReportExportController implements ContainerInjectionInterface {

  /**
   * The columns, in order, with their headings.
   */
  private const array COLUMNS = [
    'root_type' => 'Root type',
    'root_id' => 'Root ID',
    'root_bundle' => 'Root bundle',
    'root_label' => 'Root label',
    'entity_type' => 'Entity type',
    'entity_id' => 'Entity ID',
    'bundle' => 'Bundle',
    'langcode' => 'Language',
    'field' => 'Field',
    'delta' => 'Delta',
    'line' => 'Line',
    'span_start' => 'Start',
    'span_end' => 'End',
    'level' => 'Level',
    'rule' => 'Rule',
    'message' => 'Message',
    'matched' => 'Text',
    'scanned' => 'Scanned',
  ];

  /**
   * Constructs a ReportExportController.
   *
   * @param \Drupal\vale\FindingStorage $findingStorage
   *   The finding storage.
   */
  public function __construct(
    private FindingStorage $findingStorage,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self($container->get('vale.finding_storage'));
  }

  /**
   * Streams the filtered findings as CSV.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request, with optional root_bundle, level and rule filters.
   *
   * @return \Symfony\Component\HttpFoundation\StreamedResponse
   *   The CSV file.
   */
  public function export(Request $request): StreamedResponse {
    $filters = [];
    foreach (['root_bundle', 'level', 'rule'] as $key) {
      $value = $request->query->get($key);
      if (is_string($value) && $value !== '' && $value !== 'All') {
        $filters[$key] = $value;
      }
    }
    $rows = $this->findingStorage->rows($filters);
    $response = new StreamedResponse(static function () use ($rows): void {
      $out = fopen('php://output', 'w');
      if ($out === FALSE) {
        return;
      }
      fputcsv($out, array_values(self::COLUMNS), escape: '');
      foreach ($rows as $row) {
        $line = [];
        foreach (array_keys(self::COLUMNS) as $column) {
          $value = (string) ($row[$column] ?? '');
          // A spreadsheet runs a cell that starts with one of these.
          $line[] = preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
        }
        fputcsv($out, $line, escape: '');
      }
      fclose($out);
    });
    $response->headers->set('Content-Type', 'text/csv; charset=utf-8');
    $response->headers->set('Content-Disposition', 'attachment; filename="prose-findings.csv"');
    return $response;
  }

}
