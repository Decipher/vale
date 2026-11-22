<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\vale\ValeEnvironment;
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
 *
 * From Vale 3.26.0 it also installs Vale's browser build, at the same
 * version.
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
      ->setHelp("Checks the download against the SHA-256 the release publishes, or against --sha256. Without --vale-version it installs the latest release, so give --vale-version and --sha256 for the same binary on every build. From Vale 3.26.0 it also installs Vale's browser build, vale.wasm and wasm_exec.js, at the same version, into libraries/vale under the web root. Run it where the site is built, such as a container build step.")
      ->addOption('dir', NULL, InputOption::VALUE_REQUIRED, 'The directory to install into. Defaults to bin/ beside the web root.')
      ->addOption('vale-version', NULL, InputOption::VALUE_REQUIRED, 'The Vale version, such as 3.24.0. Defaults to the latest release.')
      ->addOption('sha256', NULL, InputOption::VALUE_REQUIRED, "The archive's SHA-256, to check against instead of the release's checksums file.")
      ->addOption('wasm-dir', NULL, InputOption::VALUE_REQUIRED, "The directory to install the browser build into. Defaults to libraries/vale under the web root.")
      ->addOption('wasm-sha256', NULL, InputOption::VALUE_REQUIRED, "The browser build archive's SHA-256, to check against instead of the release's checksums file.")
      ->addOption('no-wasm', NULL, InputOption::VALUE_NONE, 'Install the binary only, without the browser build.')
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
      // The browser build is installed at the binary's version, so the
      // latest release is found once, for both.
      $version = is_string($version) ? $version : $this->valeInstaller->latestVersion();
      $binary = $this->valeInstaller->install(
        is_string($directory) ? $directory : dirname(DRUPAL_ROOT) . '/bin',
        $version,
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
    $io->writeln(OutputFormatter::escape(sprintf("  \$settings['vale_binary'] = %s;", $this->setting($binary, dirname(DRUPAL_ROOT)))));
    $io->writeln("  \$settings['vale_config'] = \$app_root . '/../.vale.ini';");
    if ($input->getOption('no-wasm')) {
      return self::SUCCESS;
    }

    $wasm = $input->getOption('wasm-dir');
    $wasm = is_string($wasm) ? rtrim($wasm, '/') : DRUPAL_ROOT . '/' . ValeEnvironment::WASM_DIRECTORY;
    $wasmSha256 = $input->getOption('wasm-sha256');
    try {
      $installed = $this->valeInstaller->installWasm($wasm, $version, is_string($wasmSha256) ? $wasmSha256 : NULL);
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }
    if (!$installed) {
      $io->note(sprintf('Vale %s has no browser build, so it was not installed. It needs Vale %s or later.', $version, ValeEnvironment::WASM_MINIMUM));
      return self::SUCCESS;
    }
    $io->success(sprintf("Installed Vale's browser build in %s.", $wasm));
    if ($wasm !== DRUPAL_ROOT . '/' . ValeEnvironment::WASM_DIRECTORY) {
      $io->writeln('Add this to settings.php:');
      $io->writeln(OutputFormatter::escape(sprintf("  \$settings['vale_wasm'] = %s;", $this->setting($wasm, DRUPAL_ROOT, ''))));
    }
    return self::SUCCESS;
  }

  /**
   * Gives a path as PHP for settings.php.
   *
   * Under the given root, the path is given from $app_root, so it holds
   * wherever the site is deployed.
   *
   * @param string $path
   *   The path.
   * @param string $root
   *   The directory $app_root is given from.
   * @param string $prefix
   *   What leads from $app_root to the root.
   *
   * @return string
   *   The PHP.
   */
  private function setting(string $path, string $root, string $prefix = '/..'): string {
    return str_starts_with($path, $root . '/')
      ? '$app_root . ' . var_export($prefix . '/' . substr($path, strlen($root) + 1), TRUE)
      : var_export($path, TRUE);
  }

}
