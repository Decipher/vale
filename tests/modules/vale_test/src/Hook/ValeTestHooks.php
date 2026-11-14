<?php

declare(strict_types=1);

namespace Drupal\vale_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\State\StateInterface;
use Drupal\workspaces\Entity\Handler\IgnoredWorkspaceHandler;

/**
 * Hooks for testing Vale.
 */
class ValeTestHooks {

  public function __construct(
    protected StateInterface $state,
  ) {}

  /**
   * Implements hook_entity_type_alter().
   *
   * A test that keeps nodes out of workspaces, as an ignored handler does for
   * a type a site leaves live while a workspace is active, sets the state and
   * rebuilds the container. Nothing else sets it, so other tests are spared.
   *
   * @param array<string, \Drupal\Core\Entity\EntityTypeInterface> $entity_types
   *   The entity types, keyed by ID.
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    if ($this->state->get('vale_test_nodes_not_tracked')) {
      $entity_types['node']->setHandlerClass('workspace', IgnoredWorkspaceHandler::class);
    }
  }

  /**
   * Implements hook_entity_field_access().
   *
   * Nobody may edit the text of a page titled "Locked". The rule needs the
   * entity's values, so it only applies when access is checked with them.
   *
   * @phpstan-param \Drupal\Core\Field\FieldItemListInterface<\Drupal\Core\Field\FieldItemInterface>|null $items
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    $locked = $operation === 'edit'
      && $field_definition->getName() === 'field_text'
      && $items?->getEntity()->label() === 'Locked';
    return AccessResult::forbiddenIf($locked);
  }

}
