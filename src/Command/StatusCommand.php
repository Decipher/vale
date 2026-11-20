<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\vale\ValeEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shows the Vale installation the site points at.
 */
#[AsCommand(name: 'vale:status', description: 'Shows the Vale installation the site points at.')]
final class StatusCommand extends Command {

  use OutputTrait;

  /**
   * The label of each field.
   */
  private const array LABELS = [
    'available' => 'Available',
    'binary' => 'Binary',
    'version' => 'Version',
    'config' => 'Configuration',
    'styles' => 'Styles directories',
    'rules' => 'Rules',
    'description' => 'Description',
  ];

  /**
   * Constructs a StatusCommand.
   *
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   */
  public function __construct(
    private readonly ValeEnvironment $valeEnvironment,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setHelp('Shows the binary, version, configuration and rule count. Exits with 1 when Vale cannot be run.')
      ->addOutputOptions();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $fields = $this->outputFields($input, self::LABELS);
    }
    catch (\InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }
    $status = $this->valeEnvironment->status();
    $available = $status['available'];
    $this->writeProperties($input, $output, [
      'available' => $available ? 'Yes' : 'No',
      'binary' => (string) $this->valeEnvironment->binary(),
      'version' => (string) $this->valeEnvironment->version(),
      'config' => (string) $this->valeEnvironment->configPath(),
      'styles' => implode(', ', $this->valeEnvironment->stylesPaths()),
      'rules' => $available ? count($this->valeEnvironment->rules()) : 0,
      'description' => $status['description'],
    ], self::LABELS, $fields);
    return $available ? self::SUCCESS : self::FAILURE;
  }

}
