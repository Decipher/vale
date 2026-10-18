<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\QueueWorker;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\Queue\SuspendQueueException;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vale\ReportRefresher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Refreshes content report rows that a save left to cron.
 */
#[QueueWorker(
  id: ReportRefresher::QUEUE,
  title: new TranslatableMarkup('Refresh the prose linting report'),
  cron: ['time' => 30],
)]
final class ReportRefresh extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a ReportRefresh.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\vale\ReportRefresher $reportRefresher
   *   The report refresher.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ReportRefresher $reportRefresher,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('vale.report_refresher'));
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    if (!is_array($data) || !is_string($data[0] ?? NULL) || !is_string($data[1] ?? NULL)) {
      return;
    }
    if (!$this->reportRefresher->refresh($data[0], $data[1])) {
      // The item stays queued until Vale is back.
      throw new SuspendQueueException('Vale is unavailable.');
    }
  }

}
