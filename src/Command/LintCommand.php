<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\vale\Alert;
use Drupal\vale\ContentWalker;
use Drupal\vale\Finding;
use Drupal\vale\FindingStorage;
use Drupal\vale\ReportRefresher;
use Drupal\vale\ValeEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lints the prose in opted-in fields of stored content.
 */
#[AsCommand(name: 'vale:lint', description: 'Lints the prose in opted-in fields of stored content.')]
final class LintCommand extends Command {

  use OutputTrait;

  /**
   * How many entities --save lints before it writes to the report.
   */
  private const int CHUNK = 50;

  /**
   * The label of each field.
   */
  private const array LABELS = [
    'host' => 'Host',
    'entity' => 'Entity',
    'entity_type' => 'Entity type',
    'id' => 'ID',
    'bundle' => 'Bundle',
    'label' => 'Label',
    'language' => 'Language',
    'host_type' => 'Host type',
    'host_id' => 'Host ID',
    'host_label' => 'Host label',
    'field' => 'Field',
    'delta' => 'Delta',
    'line' => 'Line',
    'start' => 'Start',
    'end' => 'End',
    'level' => 'Level',
    'rule' => 'Rule',
    'message' => 'Message',
    'text' => 'Text',
  ];

  /**
   * The fields a table shows. It leaves out the message, so rules do not wrap.
   */
  private const array TABLE_FIELDS = ['host', 'entity', 'language', 'field', 'line', 'level', 'rule', 'text'];

  /**
   * Constructs a LintCommand.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\vale\FindingStorage $findingStorage
   *   The finding storage for the content report.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\vale\ReportRefresher $reportRefresher
   *   The report refresher, which says when the report cannot be written.
   */
  public function __construct(
    private readonly ContentWalker $contentWalker,
    private readonly ValeEnvironment $valeEnvironment,
    private readonly FindingStorage $findingStorage,
    private readonly TimeInterface $time,
    private readonly ReportRefresher $reportRefresher,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setHelp("Exits with 1 when an error-level finding is reported or a value could not be checked, so a CI job can gate a deploy on it.\n\nThe table leaves out each rule's message, so rule names do not wrap. JSON output and --fields include it.")
      ->addArgument('scope', InputArgument::OPTIONAL, 'An entity type and bundle, such as node:page. Leave it out to lint all content.')
      ->addOption('workspace', NULL, InputOption::VALUE_REQUIRED, 'Lint only the revisions this workspace tracks, as they read in it.')
      ->addOption('save', NULL, InputOption::VALUE_NONE, "Store the alerts in the content report, replacing that scope's rows. Not with --workspace.")
      ->addOutputOptions()
      ->addUsage('node:page')
      ->addUsage('--workspace=stage')
      ->addUsage('--format=json')
      ->addUsage('--save');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $scope = $input->getArgument('scope');
    $scope = is_string($scope) ? $scope : NULL;
    $workspace = $input->getOption('workspace');
    $workspace = is_string($workspace) ? $workspace : NULL;
    $save = (bool) $input->getOption('save');
    try {
      $fields = $this->outputFields($input, self::LABELS, self::TABLE_FIELDS);
    }
    catch (\InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }

    $reason = $this->valeEnvironment->unavailableReason();
    if ($reason !== NULL) {
      $this->writeError($input, $output, 'Vale could not be run. ' . $reason);
      return self::FAILURE;
    }

    try {
      if ($workspace !== NULL && $scope !== NULL) {
        throw new \InvalidArgumentException('Give either a bundle or --workspace, not both.');
      }
      if ($workspace !== NULL && $save) {
        throw new \InvalidArgumentException('The report describes Live, so --save cannot be used with --workspace.');
      }
      // An active workspace would replace Live's rows with its own.
      $refusal = $save ? $this->reportRefresher->refusal() : NULL;
      if ($refusal instanceof TranslatableMarkup) {
        throw new \InvalidArgumentException((string) $refusal);
      }
      if ($workspace !== NULL) {
        $findings = $this->contentWalker->workspace($workspace);
      }
      elseif ($scope !== NULL) {
        [$entity_type, $bundle] = explode(':', $scope, 2) + [1 => ''];
        // Refuse a bundle that does not exist.
        $this->contentWalker->entityTypeForBundle($entity_type, $bundle);
        // An empty table would read as a pass.
        if (!$this->contentWalker->mayHoldProse($entity_type, $bundle)) {
          throw new \InvalidArgumentException(sprintf('No field in %s is checked with Vale.', $scope));
        }
        $findings = $save
          ? $this->saveBundle($entity_type, $bundle)
          : $this->contentWalker->bundle($entity_type, $bundle);
      }
      elseif (!$save) {
        $findings = $this->contentWalker->all();
      }
      else {
        $findings = [];
        foreach (array_keys($this->contentWalker->scannableBundles()) as $key) {
          array_push($findings, ...$this->saveBundle(...explode(':', $key, 2)));
        }
        // Rows of bundles that no longer hold prose are stale too.
        $this->findingStorage->clearStale(NULL, NULL, $this->time->getRequestTime());
      }
    }
    catch (\InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }

    $rows = [];
    $failed = FALSE;
    foreach ($findings as $finding) {
      if ($finding->alert === NULL) {
        $this->writeError($input, $output, sprintf('%s %s, %s: not checked. %s', $finding->entityType, $finding->entityId, $finding->field, $finding->reason));
        $failed = TRUE;
        continue;
      }
      $failed = $failed || $finding->isError();
      $rows[] = $this->row($finding);
    }
    $this->writeRows($input, $output, $rows, self::LABELS, $fields);
    return $failed ? self::FAILURE : self::SUCCESS;
  }

  /**
   * Lints a bundle into the content report, a chunk at a time.
   *
   * @param string $entity_type
   *   The entity type.
   * @param string $bundle
   *   The bundle.
   *
   * @return \Drupal\vale\Finding[]
   *   The findings.
   *
   * @throws \InvalidArgumentException
   *   When the entity type or the bundle does not exist.
   */
  private function saveBundle(string $entity_type, string $bundle): array {
    // The first page also checks that the bundle exists.
    $keys = $this->contentWalker->idsAfter($entity_type, $bundle, NULL, self::CHUNK);
    // The report keeps rows by top-level content. A paragraph's findings
    // belong to the page that holds it, so its bundle cannot be saved.
    if (!isset($this->contentWalker->scannableBundles()[$entity_type . ':' . $bundle])) {
      throw new \InvalidArgumentException(sprintf('The report holds top-level content, so --save cannot be used with %s:%s. Save the bundle that holds it instead.', $entity_type, $bundle));
    }
    $findings = [];
    $after = NULL;
    for (; $keys; $keys = $this->contentWalker->idsAfter($entity_type, $bundle, $after, self::CHUNK)) {
      $chunk = $this->contentWalker->lintKeys($entity_type, array_keys($keys));
      $this->findingStorage->replace($entity_type, array_values($keys), $chunk);
      array_push($findings, ...$chunk);
      $after = end($keys);
    }
    $this->findingStorage->clearStale($entity_type, $bundle, $this->time->getRequestTime());
    return $findings;
  }

  /**
   * Gives the output row for a finding.
   *
   * @param \Drupal\vale\Finding $finding
   *   The finding, which has an alert.
   *
   * @return array<string, string|int>
   *   The row.
   */
  private function row(Finding $finding): array {
    $alert = $finding->alert;
    assert($alert instanceof Alert);
    $host = $finding->host;
    return [
      'host' => $host ? sprintf('%s %s: %s', $host['entity_type'], $host['id'], $host['label']) : '',
      'entity' => sprintf('%s %s (%s)', $finding->entityType, $finding->entityId, $finding->bundle),
      'entity_type' => $finding->entityType,
      'id' => $finding->entityId,
      'bundle' => $finding->bundle,
      'label' => $finding->label,
      'language' => $finding->langcode,
      'host_type' => $host['entity_type'] ?? '',
      'host_id' => $host['id'] ?? '',
      'host_label' => $host['label'] ?? '',
      'field' => $finding->field,
      'delta' => $finding->delta,
      'line' => $alert->line,
      'start' => $alert->start,
      'end' => $alert->end,
      'level' => $alert->level,
      'rule' => $alert->rule,
      'message' => $alert->message,
      'text' => $alert->match,
    ];
  }

}
