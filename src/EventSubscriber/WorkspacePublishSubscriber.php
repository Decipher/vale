<?php

declare(strict_types=1);

namespace Drupal\vale\EventSubscriber;

use Drupal\vale\Alert;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vale\ContentWalker;
use Drupal\vale\Finding;
use Drupal\vale\ValeEnvironment;
use Drupal\workspaces\Event\WorkspacePrePublishEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Checks a workspace's content before it is published.
 *
 * The mode is vale.settings:workspace_mode, which settings.php can override
 * per environment:
 * - block: refuse to publish while there are error-level findings, or while
 *   Vale cannot be run.
 * - advise: publish, and show and log the findings.
 * - off: do nothing.
 *
 * Core's publish form shows only "Publication failed" and logs the reason,
 * so the findings also go to the messenger.
 */
class WorkspacePublishSubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * How many findings are listed as messages.
   */
  private const int MESSAGE_LIMIT = 20;

  /**
   * Constructs a WorkspacePublishSubscriber.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Psr\Log\LoggerInterface $logger
   *   The vale logger channel.
   */
  public function __construct(
    protected ContentWalker $contentWalker,
    protected ValeEnvironment $valeEnvironment,
    protected ConfigFactoryInterface $configFactory,
    protected MessengerInterface $messenger,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [WorkspacePrePublishEvent::class => 'onPrePublish'];
  }

  /**
   * Checks the workspace's tracked content.
   *
   * @param \Drupal\workspaces\Event\WorkspacePrePublishEvent $event
   *   The event.
   */
  public function onPrePublish(WorkspacePrePublishEvent $event): void {
    $mode = $this->configFactory->get('vale.settings')->get('workspace_mode');
    if (!in_array($mode, ['block', 'advise'], TRUE)) {
      return;
    }
    $block = $mode === 'block';
    $workspace = $event->getWorkspace();
    $context = ['@workspace' => $workspace->label()];

    $reason = $this->valeEnvironment->unavailableReason();
    if ($reason !== NULL) {
      $this->report($event, $block, (string) $this->t('Prose in @workspace not checked: @reason', $context + ['@reason' => $reason]));
      return;
    }

    $findings = $this->contentWalker->workspace((string) $workspace->id());
    $errors = array_filter($findings, static fn (Finding $finding): bool => $finding->isError());
    $unchecked = array_filter($findings, static fn (Finding $finding): bool => !$finding->alert instanceof Alert);
    if ($findings === []) {
      return;
    }

    $ordered = [...$unchecked, ...$errors, ...array_diff_key($findings, $errors, $unchecked)];
    foreach (array_slice($ordered, 0, self::MESSAGE_LIMIT) as $finding) {
      $message = $this->describe($finding);
      if ($finding->isError() || $finding->alert === NULL) {
        $block ? $this->messenger->addError($message) : $this->messenger->addWarning($message);
      }
      else {
        $this->messenger->addWarning($message);
      }
    }

    $summary = (string) $this->t('@workspace: @errors, @others, @unchecked.', $context + [
      '@errors' => $this->formatPlural(count($errors), '1 prose error', '@count prose errors'),
      '@others' => $this->formatPlural(count($findings) - count($errors) - count($unchecked), '1 warning or suggestion', '@count warnings or suggestions'),
      '@unchecked' => $this->formatPlural(count($unchecked), '1 value not checked', '@count values not checked'),
    ]);
    if (count($ordered) > self::MESSAGE_LIMIT) {
      $summary .= ' ' . $this->t('@count are not listed. Run drush vale:lint --workspace=@id for all of them.', [
        '@count' => count($ordered) - self::MESSAGE_LIMIT,
        '@id' => $workspace->id(),
      ]);
    }
    if ($errors || $unchecked) {
      $this->report($event, $block, $summary);
    }
    else {
      $this->messenger->addWarning($summary);
      $this->logger->notice($summary);
    }
  }

  /**
   * Stops publishing in block mode, or advises in advise mode.
   *
   * @param \Drupal\workspaces\Event\WorkspacePrePublishEvent $event
   *   The event.
   * @param bool $block
   *   Whether to stop publishing.
   * @param string $message
   *   Why.
   */
  protected function report(WorkspacePrePublishEvent $event, bool $block, string $message): void {
    if ($block) {
      $event->stopPublishing();
      $event->setPublishingStoppedReason($message);
      $this->messenger->addError($message);
      $this->logger->error($message);
      return;
    }
    $this->messenger->addWarning($message);
    $this->logger->warning($message);
  }

  /**
   * Describes a finding for a message.
   *
   * @param \Drupal\vale\Finding $finding
   *   The finding.
   *
   * @return string
   *   The description.
   */
  protected function describe(Finding $finding): string {
    $where = sprintf('%s "%s"', $finding->entityType, $finding->label);
    if ($finding->host) {
      $where = sprintf('%s "%s", %s', $finding->host['entity_type'], $finding->host['label'], $where);
    }
    if (!$finding->alert instanceof Alert) {
      return sprintf('%s, %s: not checked. %s', $where, $finding->field, $finding->reason);
    }
    $alert = $finding->alert;
    return sprintf('%s, %s: %s: %s (line %d: "%s")', $where, $finding->field, $alert->rule, $alert->message, $alert->line, $alert->match);
  }

}
