<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\Action;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Action\ActionBase;
use Drupal\Core\Action\Attribute\Action;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\vale\ContentWalker;
use Drupal\vale\FindingStorage;
use Drupal\workspaces\WorkspaceManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Checks the prose of selected content and stores it in the report.
 */
#[Action(
  id: 'vale_check_prose',
  label: new TranslatableMarkup('Check prose with Vale'),
)]
final class CheckProseAction extends ActionBase implements ContainerFactoryPluginInterface {

  /**
   * Constructs a CheckProseAction.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\FindingStorage $findingStorage
   *   The finding storage.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user.
   * @param \Drupal\workspaces\WorkspaceManagerInterface|null $workspaceManager
   *   The workspace manager, when Workspaces is on.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected ContentWalker $contentWalker,
    protected FindingStorage $findingStorage,
    MessengerInterface $messenger,
    protected AccountInterface $account,
    protected ?WorkspaceManagerInterface $workspaceManager = NULL,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->setMessenger($messenger);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('vale.content_walker'),
      $container->get('vale.finding_storage'),
      $container->get('messenger'),
      $container->get('current_user'),
      $container->get('workspaces.manager', ContainerInterface::NULL_ON_INVALID_REFERENCE),
    );
  }

  /**
   * {@inheritdoc}
   *
   * @param array<int, mixed> $entities
   *   The selected entities.
   */
  public function executeMultiple(array $entities): void {
    // The report describes Live. A workspace's content is checked when the
    // workspace is published.
    if ($this->workspaceManager?->hasActiveWorkspace()) {
      $this->messenger()->addError($this->t('Switch to Live to check content for the report.'));
      return;
    }
    $errors = 0;
    $others = 0;
    foreach ($entities as $entity) {
      if (!$entity instanceof FieldableEntityInterface) {
        continue;
      }
      // Views lists the default revision. The report holds the newest one.
      $latest = $this->contentWalker->latest($entity->getEntityTypeId(), $entity->id()) ?? $entity;
      $findings = $this->contentWalker->entity($latest);
      $this->findingStorage->replace($entity->getEntityTypeId(), [$entity->id()], $findings);
      foreach ($findings as $finding) {
        $finding->isError() ? $errors++ : $others++;
      }
    }
    $this->messenger()->addStatus($this->t('Checked @count items: @errors errors and @others other findings. See the prose findings report.', [
      '@count' => count($entities),
      '@errors' => $errors,
      '@others' => $others,
    ]));
  }

  /**
   * {@inheritdoc}
   */
  public function execute(mixed $entity = NULL): void {
    $this->executeMultiple([$entity]);
  }

  /**
   * {@inheritdoc}
   */
  public function access($object, ?AccountInterface $account = NULL, $return_as_object = FALSE): bool|AccessResultInterface {
    $result = AccessResult::allowedIfHasPermission($account ?? $this->account, 'scan content with vale');
    if ($object instanceof FieldableEntityInterface) {
      $result = $result->andIf($object->access('view', $account, TRUE));
    }
    return $return_as_object ? $result : $result->isAllowed();
  }

}
