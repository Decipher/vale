<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\Translator\TranslatorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the strings that a message with several findings is translated from.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class FindingTranslationTest extends KernelTestBase {

  use ValeContentTrait;
  use ValeTestTrait;

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
   * The source strings looked up for translation, in order.
   *
   * @var \ArrayObject<int, string>
   */
  protected \ArrayObject $sources;

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

    // Locale stores every string it is asked for as a source string. This
    // records them the same way.
    $this->sources = new \ArrayObject();
    $this->container->get('string_translation')->addTranslator(new class($this->sources) implements TranslatorInterface {

      /**
       * Constructs the recorder.
       *
       * @param \ArrayObject<int, string> $sources
       *   The strings looked up.
       */
      public function __construct(protected \ArrayObject $sources) {}

      /**
       * {@inheritdoc}
       */
      public function getStringTranslation($langcode, $string, $context): bool {
        $this->sources[] = $string;
        return FALSE;
      }

      /**
       * {@inheritdoc}
       */
      public function reset(): void {}

    });
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
   * Gives the source strings looked up that hold a finding placeholder.
   *
   * @return string[]
   *   The strings, each once.
   */
  protected function findingSources(): array {
    // A rendered finding must never be looked up as a source string.
    foreach ($this->sources as $source) {
      $this->assertStringNotContainsString('Demo.Very', $source);
    }
    return array_values(array_unique(array_filter($this->sources->getArrayCopy(), static fn (string $source): bool => str_contains($source, '@rule') || str_contains($source, '@reason'))));
  }

  /**
   * Tests a field with one error.
   */
  public function testOneError(): void {
    $page = Node::create(['type' => 'page', 'title' => 'A page', 'field_text' => 'This is very good.']);
    $this->assertSame(['Text: Demo.Very: Avoid &#039;very&#039;. (line 1: "very")'], $this->violations($page));
    $this->assertSame(['@field: @rule: @message (line @line: "@match")'], $this->findingSources());
  }

  /**
   * Tests a field with several errors.
   */
  public function testSeveralErrors(): void {
    $page = Node::create(['type' => 'page', 'title' => 'A page', 'field_text' => "This is very good.\nVery good."]);
    $this->assertSame(
      ["Text: Demo.Very: Avoid &#039;very&#039;. (line 1: \"very\")<br>\nText: Demo.Very: Avoid &#039;Very&#039;. (line 2: \"Very\")"],
      $this->violations($page),
    );
    // The template is translated once, with its own placeholders.
    $this->assertSame(['@field: @rule: @message (line @line: "@match")'], $this->findingSources());
  }

  /**
   * Tests publishing a host with errors in its own field and a paragraph.
   */
  public function testTree(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    $page = Node::create([
      'type' => 'page',
      'title' => 'A page',
      'status' => TRUE,
      'field_text' => 'Very good.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ]);
    $this->assertSame(
      ["Text: Demo.Very: Avoid &#039;Very&#039;. (line 1: \"Very\")<br>\nParagraph \"Docs text\", Text: Demo.Very: Avoid &#039;very&#039;. (line 1: \"very\")"],
      $this->violations($page),
    );
    $this->assertSame([
      '@field: @rule: @message (line @line: "@match")',
      '@type "@bundle", @field: @rule: @message (line @line: "@match")',
    ], $this->findingSources());
  }

}
