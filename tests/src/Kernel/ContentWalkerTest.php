<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\vale\Finding;
use Drupal\vale\ValeServiceProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests walking stored content for findings.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ContentWalkerTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeContentTrait;
  use ValeTestTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, which WorkspaceTestTrait sets.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'content_translation',
    'entity_reference_revisions',
    'entity_test',
    'field',
    'file',
    'filter',
    'language',
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
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->tearDownVale();
    parent::tearDown();
  }

  /**
   * Saves a page.
   *
   * @param array<string, mixed> $values
   *   Values for the page.
   */
  protected function savePage(array $values): NodeInterface {
    $page = Node::create($values + ['type' => 'page']);
    $page->save();
    return $page;
  }

  /**
   * Summarises findings as "<host> > <entity>.<field>:<rule>".
   *
   * @param \Drupal\vale\Finding[] $findings
   *   The findings.
   *
   * @return string[]
   *   The summaries.
   */
  protected function summarise(array $findings): array {
    return array_map(static function (Finding $finding): string {
      $host = $finding->host ? $finding->host['entity_type'] . ' ' . $finding->host['label'] . ' > ' : '';
      return $host . $finding->entityType . '.' . $finding->field . ':' . ($finding->alert->rule ?? 'unchecked');
    }, $findings);
  }

  /**
   * Tests linting a bundle, with paragraphs and forward revisions.
   */
  public function testBundle(): void {
    $this->savePage([
      'title' => 'First',
      'field_text' => 'This is very good.',
      'field_code' => 'Very, very code.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'We delve.'])],
    ]);
    $second = $this->savePage(['title' => 'Second', 'field_text' => 'Fine.']);

    // A forward revision is what an editor is working on, so it is linted.
    $second->setNewRevision();
    $second->isDefaultRevision(FALSE);
    $second->set('field_text', 'Really.');
    $second->save();

    $walker = $this->container->get('vale.content_walker');
    $this->assertSame([
      'node.field_text:Demo.Very',
      'node First > paragraph.field_text:Demo.Delve',
      'node.field_text:Demo.Really',
    ], $this->summarise($walker->bundle('node', 'page')));

    $findings = $walker->bundle('node', 'page');
    $this->assertSame('First', $findings[0]->label);
    $this->assertNull($findings[0]->host);
    $this->assertSame('docs_text', $findings[1]->bundle);
    // Saved content uses the entity's own label, which Paragraphs takes from
    // its host and field.
    $this->assertSame('First > Content', $findings[1]->label);
    $this->assertSame('field_text', $findings[1]->field);
    $this->assertSame('delve', $findings[1]->alert?->match);

    // Paragraphs can be linted by themselves, and name their host.
    $this->assertSame(['node First > paragraph.field_text:Demo.Delve'], $this->summarise($walker->bundle('paragraph', 'docs_text')));

    // All content reaches paragraphs only through their hosts.
    $this->assertSame($this->summarise($walker->bundle('node', 'page')), $this->summarise($walker->all()));

    $this->expectException(\InvalidArgumentException::class);
    $walker->bundle('node', 'nope');
  }

  /**
   * Tests that a value Vale could not check is a finding of its own.
   */
  public function testUnchecked(): void {
    $this->savePage(['title' => 'First', 'field_text' => 'FAKE_CRASH']);
    $findings = $this->container->get('vale.content_walker')->bundle('node', 'page');
    $this->assertCount(1, $findings);
    $this->assertNull($findings[0]->alert);
    $this->assertStringContainsString('Fake failure.', $findings[0]->reason);
  }

  /**
   * Tests linting what a workspace tracks.
   */
  public function testWorkspace(): void {
    $this->initializeWorkspacesModule();

    // Live content with a finding, which the workspace leaves alone.
    $this->savePage(['title' => 'Live', 'field_text' => 'This is very good.']);
    $changed = $this->savePage(['title' => 'Changed', 'field_text' => 'Fine.']);

    $this->switchToWorkspace('stage');
    $changed = Node::load($changed->id());
    $this->assertInstanceOf(NodeInterface::class, $changed);
    $changed->set('field_text', 'We delve.');
    $changed->save();
    $this->savePage([
      'title' => 'New',
      'field_text' => 'Fine.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'Really very.'])],
    ]);
    $this->switchToLive();

    $walker = $this->container->get('vale.content_walker');
    $this->assertSame([
      'node.field_text:Demo.Delve',
      'node New > paragraph.field_text:Demo.Really',
      'node New > paragraph.field_text:Demo.Very',
    ], $this->summarise($walker->workspace('stage')));

    // Live sees only Live's revisions: the page the workspace made is not
    // there, and the changed page is linted as Live has it.
    $this->assertSame(['node.field_text:Demo.Very'], $this->summarise($walker->bundle('node', 'page')));
    $this->assertSame('Fine.', $walker->latest('node', $changed->id())?->get('field_text')->value);

    $this->expectException(\InvalidArgumentException::class);
    $walker->workspace('nope');
  }

  /**
   * Tests linting an entity type without revisions.
   */
  public function testWithoutRevisions(): void {
    $this->installEntitySchema('entity_test');
    $this->createValeTextField('entity_test', 'entity_test', 'field_text', TRUE);
    EntityTest::create(['name' => 'Test', 'field_text' => 'We delve.'])->save();
    $this->assertSame(['entity_test.field_text:Demo.Delve'], $this->summarise($this->container->get('vale.content_walker')->bundle('entity_test', 'entity_test')));
  }

  /**
   * Tests that the service provider leaves a container without Vale alone.
   */
  public function testServiceProviderWithoutVale(): void {
    $container = new ContainerBuilder();
    (new ValeServiceProvider())->alter($container);
    $this->assertFalse($container->hasDefinition('vale.content_walker'));
  }

  /**
   * Tests that every translation is linted, with its own paragraphs.
   */
  public function testTranslations(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $manager = $this->container->get('content_translation.manager');
    $manager->setEnabled('node', 'page', TRUE);
    $manager->setEnabled('paragraph', 'docs_text', TRUE);
    // Paragraphs translates the paragraphs, not the reference to them.
    FieldConfig::loadByName('node', 'page', 'field_content')?->set('translatable', FALSE)->save();

    $paragraph = Paragraph::create(['type' => 'docs_text', 'field_text' => 'Fine.']);
    $page = $this->savePage([
      'title' => 'Guide',
      'field_text' => 'Fine.',
      'field_content' => [$paragraph],
    ]);
    // Saving a paragraph makes a new revision, which the page then points at.
    $paragraph->addTranslation('fr', ['field_text' => 'This is very good.'])->save();
    $page->addTranslation('fr', [
      'title' => 'Guide FR',
      'field_text' => 'We delve.',
      'field_content' => [$paragraph],
    ])->save();

    $findings = $this->container->get('vale.content_walker')->bundle('node', 'page');
    // Re-saving field_content above moved it ahead of field_text.
    $this->assertSame([
      'node Guide FR > paragraph.field_text:Demo.Very',
      'node.field_text:Demo.Delve',
    ], $this->summarise($findings));
    $this->assertSame(['fr', 'fr'], array_map(static fn (Finding $finding): string => $finding->langcode, $findings));
    $this->assertSame('Guide FR', $findings[1]->label);
  }

  /**
   * Tests that shared values are linted once, not once per translation.
   */
  public function testTranslationsShareValues(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $manager = $this->container->get('content_translation.manager');
    $manager->setEnabled('node', 'page', TRUE);
    $manager->setEnabled('paragraph', 'docs_text', TRUE);
    FieldConfig::loadByName('node', 'page', 'field_content')?->set('translatable', FALSE)->save();
    // The page's text is the same in every language.
    FieldConfig::loadByName('node', 'page', 'field_text')?->set('translatable', FALSE)->save();

    $page = $this->savePage([
      'title' => 'Guide',
      'field_text' => 'We delve.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ]);
    // A French page whose paragraph has no French translation.
    $page->addTranslation('fr', ['title' => 'Guide FR'])->save();

    $findings = $this->container->get('vale.content_walker')->bundle('node', 'page');
    $this->assertCount(2, $findings);
    $this->assertSame(['en', 'en'], array_map(static fn (Finding $finding): string => $finding->langcode, $findings));
  }

  /**
   * Tests that only components are followed, not any revision reference.
   */
  public function testOnlyComponents(): void {
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference_revisions',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create(['field_name' => 'field_related', 'entity_type' => 'node', 'bundle' => 'page'])->save();

    $other = $this->savePage(['title' => 'Other', 'field_text' => 'We delve.']);
    $this->savePage(['title' => 'Guide', 'field_text' => 'Fine.', 'field_related' => [$other]]);

    // The other page is a page of its own, linted once, with no host.
    $this->assertSame(['node.field_text:Demo.Delve'], $this->summarise($this->container->get('vale.content_walker')->bundle('node', 'page')));
  }

}
