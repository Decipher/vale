<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\vale\ValeInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Downloads Vale, checks its checksum and installs it.
 */
#[AsCommand(name: 'vale:install', description: 'Downloads Vale, checks its checksum and installs it.')]
final class InstallCommand extends Command {

  use OutputTrait;

  /**
   * Constructs an InstallCommand.
   *
   * @param \Drupal\vale\ValeInstaller $valeInstaller
   *   The Vale installer.
   */
  public function __construct(
    private readonly ValeInstaller $valeInstaller,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setHelp("Checks the download against the SHA-256 the release publishes, or against --sha256. Without --vale-version it installs the latest release, so give --vale-version and --sha256 for the same binary on every build. Run it where the site is built, such as a container build step.")
      ->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'The directory to install into. Defaults to bin/ beside the web root.')
      ->addOption('vale-version', NULL, InputOption::VALUE_REQUIRED, 'The Vale version, such as 3.24.0. Defaults to the latest release.')
      ->addOption('sha256', NULL, InputOption::VALUE_REQUIRED, "The archive's SHA-256, to check against instead of the release's checksums file.")
      ->addUsage('--vale-version=3.24.0')
      ->addUsage('--dir=/usr/local/bin');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $directory = $input->getOption('dir');
    $version = $input->getOption('vale-version');
    $sha256 = $input->getOption('sha256');
    try {
      $binary = $this->valeInstaller->install(
        is_string($directory) ? $directory : dirname(DRUPAL_ROOT) . '/bin',
        is_string($version) ? $version : NULL,
        is_string($sha256) ? $sha256 : NULL,
      );
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }
    $io = new SymfonyStyle($input, $output);
    $io->success(sprintf('Installed Vale at %s.', $binary));
    $io->writeln('Add this to settings.php, with the path to your .vale.ini:');
    // Beside the web root, the path is given from $app_root, so it holds
    // wherever the site is deployed.
    $project = dirname(DRUPAL_ROOT) . '/';
    $setting = str_starts_with($binary, $project)
      ? '$app_root . ' . var_export('/../' . substr($binary, strlen($project)), TRUE)
      : var_export($binary, TRUE);
    $io->writeln(OutputFormatter::escape(sprintf("  \$settings['vale_binary'] = %s;", $setting)));
    $io->writeln("  \$settings['vale_config'] = \$app_root . '/../.vale.ini';");
    return self::SUCCESS;
  }

}
