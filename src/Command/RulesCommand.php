<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\vale\ValeEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lists the rules Vale loads, for use in field settings.
 */
#[AsCommand(name: 'vale:rules', description: 'Lists the rules Vale loads, for use in field settings.')]
final class RulesCommand extends Command {

  use OutputTrait;

  /**
   * The label of each field.
   */
  private const array LABELS = [
    'rule' => 'Rule',
    'level' => 'Level',
    'message' => 'Message',
    'description' => 'Description',
    'link' => 'Link',
  ];

  /**
   * Constructs a RulesCommand.
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
      ->setHelp('Lists every rule with its level and message.')
      ->addOutputOptions();
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $fields = $this->outputFields($input, self::LABELS, ['rule', 'level', 'message']);
    }
    catch (\InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }
    $rows = [];
    foreach ($this->valeEnvironment->rules() as $rule => $definition) {
      $rows[$rule] = ['rule' => $rule] + $definition;
    }
    $this->writeRows($input, $output, $rows, self::LABELS, $fields);
    return self::SUCCESS;
  }

}
