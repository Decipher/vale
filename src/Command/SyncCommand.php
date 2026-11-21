<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\vale\ValeEnvironment;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * Downloads the styles that the site's .vale.ini names.
 */
#[AsCommand(name: 'vale:sync', description: "Downloads the styles that the site's .vale.ini names.")]
final class SyncCommand extends Command {

  use OutputTrait;

  /**
   * How many seconds a sync may take.
   */
  private const int TIMEOUT = 300;

  /**
   * Constructs a SyncCommand.
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
      ->setHelp("Runs Vale's sync with the site's configuration, so the packages land in its StylesPath. Run it where the site is built, as for vale:install. Exits with 1 when the styles could not be downloaded.");
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $reason = $this->valeEnvironment->unavailableReason();
    if ($reason !== NULL) {
      $this->writeError($input, $output, 'Vale could not be run. ' . $reason);
      return self::FAILURE;
    }
    // A download takes longer than a lint, so a lint's time limit is too
    // short.
    $process = $this->valeEnvironment->process(['sync'])->setTimeout(self::TIMEOUT);
    try {
      $process->run();
    }
    catch (ProcessException $e) {
      $this->writeError($input, $output, 'Vale could not sync the styles. ' . $e->getMessage());
      return self::FAILURE;
    }
    if (!$process->isSuccessful()) {
      $this->writeError($input, $output, 'Vale could not sync the styles. ' . trim($process->getErrorOutput() ?: $process->getOutput()));
      return self::FAILURE;
    }
    $output->write($process->getOutput(), FALSE, OutputInterface::OUTPUT_RAW);
    // The sync may have made the styles directory, so ask Vale for it again.
    $this->valeEnvironment->resetStylesPaths();
    $count = count($this->valeEnvironment->rules());
    (new SymfonyStyle($input, $output))->success($count === 1 ? 'Vale now loads 1 rule.' : sprintf('Vale now loads %d rules.', $count));
    return self::SUCCESS;
  }

}
