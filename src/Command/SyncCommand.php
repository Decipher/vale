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
 * Downloads the styles that .vale.ini names, and the site's language models.
 */
#[AsCommand(name: 'vale:sync', description: "Downloads the styles that the site's .vale.ini names, and a model for each of the site's languages.")]
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
      ->setHelp("Runs Vale's sync with the site's configuration, so the packages land in its StylesPath. With Vale 3.27.0 or later, it also downloads the model for each of the site's languages, so .vale.ini need not name them. Run it where the site is built, as for vale:install. Exits with 1 when the styles or models could not be downloaded.");
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
    if (!$this->sync($input, $output, NULL, 'styles')) {
      return self::FAILURE;
    }
    $io = new SymfonyStyle($input, $output);
    $languages = $this->valeEnvironment->siteLanguages();
    if ($languages !== [] && !$this->valeEnvironment->supportsLanguages()) {
      $io->warning(sprintf('Vale %s reads every value as English. Vale %s or later downloads a model for %s.', $this->valeEnvironment->version(), ValeEnvironment::LANG_MINIMUM, implode(', ', $languages)));
    }
    try {
      $models = $this->valeEnvironment->modelsConfigPath();
    }
    catch (\RuntimeException $e) {
      $this->writeError($input, $output, 'Vale could not sync the language models. ' . $e->getMessage());
      return self::FAILURE;
    }
    if ($models !== NULL && !$this->sync($input, $output, $models, 'language models')) {
      return self::FAILURE;
    }
    $count = count($this->valeEnvironment->rules());
    $io->success($count === 1 ? 'Vale now loads 1 rule.' : sprintf('Vale now loads %d rules.', $count));
    return self::SUCCESS;
  }

  /**
   * Runs Vale's sync with a configuration, and prints what it says.
   *
   * @param \Symfony\Component\Console\Input\InputInterface $input
   *   The input.
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   *   The output.
   * @param string|null $config
   *   The configuration, or NULL for the site's.
   * @param string $what
   *   What is downloaded, for the error message.
   *
   * @return bool
   *   TRUE when the sync succeeded.
   */
  private function sync(InputInterface $input, OutputInterface $output, ?string $config, string $what): bool {
    // A download takes longer than a lint, so a lint's time limit is too
    // short.
    $process = $this->valeEnvironment->process(['sync'], NULL, $config)->setTimeout(self::TIMEOUT);
    try {
      $process->run();
    }
    catch (ProcessException $e) {
      $this->writeError($input, $output, sprintf('Vale could not sync the %s. %s', $what, $e->getMessage()));
      return FALSE;
    }
    if (!$process->isSuccessful()) {
      $this->writeError($input, $output, sprintf('Vale could not sync the %s. %s', $what, trim($process->getErrorOutput() ?: $process->getOutput())));
      return FALSE;
    }
    $output->write($process->getOutput(), FALSE, OutputInterface::OUTPUT_RAW);
    // The sync may have made the styles directory, so ask Vale for it again.
    $this->valeEnvironment->resetStylesPaths();
    return TRUE;
  }

}
