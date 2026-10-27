<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Unit;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\vale\PublishState;
use Drupal\workspaces\WorkspaceInformationInterface;
use Drupal\workspaces\WorkspaceManagerInterface;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests whether a save drafts into the active workspace.
 *
 * The full save flows are covered by the kernel tests; this pins the
 * workspace decision itself, including types a workspace does not track.
 *
 * @group vale
 * @coversDefaultClass \Drupal\vale\PublishState
 */
#[Group('vale')]
class PublishStateTest extends UnitTestCase {

  /**
   * Gives a workspace manager that answers the given active state.
   *
   * @param bool $active
   *   Whether a workspace is active.
   *
   * @return \Drupal\workspaces\WorkspaceManagerInterface
   *   The manager.
   */
  private function manager(bool $active): WorkspaceManagerInterface {
    $manager = $this->createMock(WorkspaceManagerInterface::class);
    $manager->method('hasActiveWorkspace')->willReturn($active);
    return $manager;
  }

  /**
   * Gives workspace information that answers the given tracked state.
   *
   * @param bool $tracked
   *   Whether the entity type is tracked.
   *
   * @return \Drupal\workspaces\WorkspaceInformationInterface
   *   The information service.
   */
  private function information(bool $tracked): WorkspaceInformationInterface {
    $information = $this->createMock(WorkspaceInformationInterface::class);
    $information->method('isEntitySupported')->willReturn($tracked);
    return $information;
  }

  /**
   * Gives a published node whose type answers the given revision state.
   *
   * @param bool $revisionable
   *   Whether the node's type is revisionable.
   *
   * @return \Drupal\node\NodeInterface
   *   The node.
   */
  private function node(bool $revisionable): NodeInterface {
    $entity_type = $this->createMock(EntityTypeInterface::class);
    $entity_type->method('isRevisionable')->willReturn($revisionable);
    $node = $this->createMock(NodeInterface::class);
    $node->method('getEntityType')->willReturn($entity_type);
    $node->method('isPublished')->willReturn(TRUE);
    $node->method('isNew')->willReturn(FALSE);
    return $node;
  }

  /**
   * Tests that content a workspace tracks is never published by its save.
   */
  public function testTrackedTypeIsDraft(): void {
    $state = new PublishState(NULL, $this->manager(TRUE), $this->information(TRUE));
    $this->assertFalse($state->isBeingPublished($this->node(TRUE)));
  }

  /**
   * Tests that untracked content still saves straight to Live.
   *
   * Types a workspace does not track, such as those an ignored workspace
   * handler keeps out, publish into Live however a workspace is active, so
   * their publishing save keeps its refusal.
   */
  public function testUntrackedTypeStillPublishes(): void {
    $state = new PublishState(NULL, $this->manager(TRUE), $this->information(FALSE));
    $this->assertTrue($state->isBeingPublished($this->node(TRUE)));
  }

  /**
   * Tests the rule on cores with no workspace information service.
   *
   * Drupal before 11.3 tracks every publishable revision, so a
   * revisionable type drafts and a type that cannot be tracked does not.
   */
  public function testWithoutWorkspaceInformation(): void {
    $state = new PublishState(NULL, $this->manager(TRUE));
    $this->assertFalse($state->isBeingPublished($this->node(TRUE)));
    $this->assertTrue($state->isBeingPublished($this->node(FALSE)));
  }

  /**
   * Tests that Live keeps the usual publishing decision.
   */
  public function testOutsideAWorkspace(): void {
    $state = new PublishState(NULL, $this->manager(FALSE), $this->information(TRUE));
    $this->assertTrue($state->isBeingPublished($this->node(TRUE)));
  }

}
