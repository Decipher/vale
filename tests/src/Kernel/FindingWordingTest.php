<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\vale\FindingMessage;
use Drupal\vale\Hook\ValeFormHooks;
use Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a paragraph's finding reads the same wherever it is reported.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FindingWordingTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeContentTrait;
  use ValeTestTrait;
  use WorkspaceTestTrait;

  /**
   * How the paragraph's finding reads everywhere.
   */
  private const string FINDING = 'Paragraph "Docs text", Text: Demo.Very: Avoid &#039;very&#039;. (line 1: "very")';

  /**
   * The entity type manager, which WorkspaceTestTrait sets.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'entity_reference_revisions',
    'field',
    'file',
    'filter',
    'node',
    'paragraphs',
    'system',
    'text',
    'user',
    'vale',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node', 'vale']);
    $this->setUpVale();
    $this->createValeContentModel();
  }

  /**
   * Gives the validation messages of an entity.
   *
   * @return string[]
   *   The messages.
   */
  protected function violations(ContentEntityInterface $entity): array {
    $messages = [];
    foreach ($entity->validate() as $violation) {
      $messages[] = (string) $violation->getMessage();
    }
    return $messages;
  }

  /**
   * Makes a paragraph with an error in it.
   */
  protected function paragraph(): Paragraph {
    return Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.']);
  }

  /**
   * Tests that a component's templates match ValeTree's, word for word.
   *
   * The constraint keeps its own literal strings, which translation tools
   * read. Matching them means each template is translated once.
   */
  public function testComponentTemplatesMatch(): void {
    $constraint = new ValeTreeConstraint();
    $this->assertSame($constraint->message, FindingMessage::COMPONENT_ALERT);
    $this->assertSame($constraint->uncheckedMessage, FindingMessage::COMPONENT_UNCHECKED);
  }

  /**
   * Tests the save errors for a paragraph.
   */
  public function testSaveErrors(): void {
    // A paragraph validated by itself, as the Paragraphs widget does.
    $this->assertSame([self::FINDING], $this->violations($this->paragraph()));

    // In publish-only mode, publishing its host.
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $page = Node::create(['type' => 'page', 'title' => 'Guide', 'status' => TRUE, 'field_content' => [$this->paragraph()]]);
    $this->assertSame([self::FINDING], $this->violations($page));

    // In publish-only mode, saving the paragraph of a published host by
    // itself.
    $page = Node::create(['type' => 'page', 'title' => 'Guide', 'status' => TRUE, 'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'Fine.'])]]);
    $page->save();
    $paragraph = Paragraph::load($page->get('field_content')->target_id);
    $this->assertInstanceOf(Paragraph::class, $paragraph);
    $paragraph->set('field_text', 'This is very good.');
    $this->assertSame([self::FINDING], $this->violations($paragraph));
  }

  /**
   * Tests the form message for a paragraph's error that did not stop a save.
   */
  public function testFormMessage(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $this->assertSame([], $this->violations($this->paragraph()));
    $form = [];
    $this->container->get('class_resolver')->getInstanceFromDefinition(ValeFormHooks::class)->showAlerts($form, new FormState());
    $warnings = array_map(strval(...), $this->container->get('messenger')->messagesByType('warning'));
    $this->assertSame([self::FINDING . '. Blocks publishing.'], $warnings);
  }

  /**
   * Tests the workspace publish message for a paragraph.
   */
  public function testWorkspaceMessage(): void {
    $this->initializeWorkspacesModule();
    $this->config('vale.settings')->set('workspace_mode', 'advise')->save();
    $this->switchToWorkspace('stage');
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_content' => [$this->paragraph()]])->save();
    $this->switchToLive();
    $this->workspaces['stage']->publish();
    $warnings = array_map(strval(...), $this->container->get('messenger')->messagesByType('warning'));
    $this->assertSame('Content "Guide", ' . self::FINDING, $warnings[0]);
  }

}
