<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Composer\InstalledVersions;
use Drupal\canvas\ComponentSource\ComponentSourceManager;
use Drupal\canvas\Controller\ApiAutoSaveController;
use Drupal\canvas\Entity\Page;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityConstraintViolationListInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ThemeInstallerInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\Entity\BaseFieldOverride;
use Drupal\Core\Form\FormState;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\Tests\workspaces\Kernel\WorkspaceTestTrait;
use Drupal\vale\Canvas\ComponentTreeProse;
use Drupal\vale\Command\LintCommand;
use Drupal\vale\FieldSettings;
use Drupal\vale\Finding;
use Drupal\vale\Form\CanvasSettingsForm;
use Drupal\vale\Plugin\Validation\Constraint\ValeTextConstraint;
use Drupal\vale\Plugin\Validation\Constraint\ValeTreeConstraint;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests checking the text in the component tree of Canvas pages.
 *
 * Canvas needs Drupal 11.3 or later, so the test is skipped where it is not
 * installed or cannot run. Canvas's own kernel test base class cannot be used
 * for the same reason: the class must load where Canvas is missing.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
final class CanvasPageTest extends KernelTestBase {

  use UserCreationTrait;
  use ValeTestTrait;
  use WorkspaceTestTrait;

  /**
   * The entity type manager, which WorkspaceTestTrait sets.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The component instance used in each page.
   */
  private const string UUID = 'a6b5c8f0-4a3e-4c2b-9d4e-1f2a3b4c5d6e';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'canvas',
    'ckeditor5',
    'content_translation',
    'datetime',
    'editor',
    'field',
    'file',
    'filter',
    'image',
    'language',
    'link',
    'media',
    'media_library',
    'options',
    'path',
    'path_alias',
    'system',
    'text',
    'user',
    'vale',
    'vale_canvas_test',
    'views',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    if (version_compare(\Drupal::VERSION, '11.3', '<')) {
      $this->markTestSkipped('Canvas needs Drupal 11.3 or later.');
    }
    if (!InstalledVersions::isInstalled('drupal/canvas')) {
      $this->markTestSkipped('Canvas is not installed.');
    }
    parent::setUp();
    $this->container->get(ThemeInstallerInterface::class)->install(['stark']);
    $this->installConfig(['system', 'filter', 'canvas', 'vale']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema(Page::ENTITY_TYPE_ID);
    $this->container->get(ComponentSourceManager::class)->generateComponents();
    $this->setUpVale();
  }

  /**
   * Turns Vale on for the component tree of Canvas pages.
   *
   * @param string[] $skipped
   *   Props not to check.
   */
  private function enableCanvas(array $skipped = ['label']): void {
    $override = $this->override();
    foreach ((new FieldSettings(TRUE, skippedProps: $skipped))->toArray() as $key => $value) {
      $override->setThirdPartySetting('vale', $key, $value);
    }
    $override->save();
  }

  /**
   * Gives the base field override of the component tree.
   *
   * @return \Drupal\Core\Field\Entity\BaseFieldOverride
   *   The stored override, or a new one.
   */
  private function override(): BaseFieldOverride {
    $definition = $this->container->get('entity_field.manager')->getBaseFieldDefinitions('canvas_page')['components'];
    $this->assertInstanceOf(BaseFieldDefinition::class, $definition);
    $override = $definition->getConfig('canvas_page');
    $this->assertInstanceOf(BaseFieldOverride::class, $override);
    return $override;
  }

  /**
   * Makes a page with one notice component.
   *
   * @param array<string, mixed> $inputs
   *   Inputs that replace the defaults.
   * @param bool $published
   *   Whether the page is published.
   *
   * @return \Drupal\canvas\Entity\Page
   *   The unsaved page.
   */
  private function page(array $inputs = [], bool $published = TRUE): Page {
    return Page::create([
      'title' => 'Opening hours',
      'status' => $published,
      'components' => [
        [
          'uuid' => self::UUID,
          'component_id' => 'sdc.vale_canvas_test.notice',
          'inputs' => $inputs + [
            'heading' => 'A very short notice',
            'body' => ['value' => '<p>We are really open.</p>', 'format' => 'canvas_html_block'],
            'tone' => 'very',
            'link' => '/very',
            'label' => 'A very loud notice',
          ],
        ],
      ],
    ]);
  }

  /**
   * Gives Vale's violations of an entity, keyed by property path.
   *
   * @param \Drupal\Core\Entity\EntityConstraintViolationListInterface $violations
   *   All of the entity's violations.
   *
   * @return array<string, string>
   *   The messages of Vale's violations.
   */
  private function valeViolations(EntityConstraintViolationListInterface $violations): array {
    $messages = [];
    foreach ($violations as $violation) {
      $constraint = $violation->getConstraint();
      if ($constraint instanceof ValeTextConstraint || $constraint instanceof ValeTreeConstraint) {
        $messages[$violation->getPropertyPath()] = Html::decodeEntities(strip_tags((string) $violation->getMessage()));
      }
    }
    return $messages;
  }

  /**
   * Tests that a page with an error is refused, at its component and prop.
   */
  public function testErrorsAreRefusedAtTheComponent(): void {
    $this->enableCanvas();
    $page = $this->page();
    $violations = $page->validate();

    // Only prose props are checked. The choice, the link and the skipped
    // label hold "very" too, and are left alone.
    $path = 'components.0.inputs.' . self::UUID . '.heading';
    $this->assertSame([
      $path => "Components > Notice > heading: Demo.Very: Avoid 'very'. (line 1: \"very\")",
    ], $this->valeViolations($violations));

    // Plain text is linted as text, and rich text by its format.
    $runs = $this->valeRuns();
    sort($runs);
    $this->assertSame(['canvas_page.canvas_page.components.html', 'canvas_page.canvas_page.components.txt'], $runs);

    // Canvas's publish response points the error at the component, which
    // its review panel links to.
    $mapped = ApiAutoSaveController::getViolationSetsFromPropertyPathsAndRoot($page, $violations);
    $paths = [];
    foreach ($mapped as $violation) {
      $paths[] = $violation->getPropertyPath();
    }
    $this->assertContains('model.' . self::UUID . '.heading', $paths);
  }

  /**
   * Tests a list prop, a block component and a broken instance.
   */
  public function testListsBlocksAndBrokenInstances(): void {
    $this->enableCanvas();
    $page = $this->page([
      'heading' => 'A short notice',
      'body' => ['value' => '<p>We are open.</p>', 'format' => 'canvas_html_block'],
      'points' => ['A first point', '  ', 'A very good point'],
    ]);
    $page->get('components')->appendItem([
      'uuid' => 'b7c6d9e1-5b4f-4d3c-8e5f-2a3b4c5d6e7f',
      'component_id' => 'block.system_branding_block',
      'inputs' => [
        'label' => 'Very site branding',
        'label_display' => '0',
        'use_site_logo' => TRUE,
        'use_site_name' => TRUE,
        'use_site_slogan' => TRUE,
      ],
    ]);
    // An input for a prop the component does not have makes Canvas throw.
    $page->get('components')->appendItem([
      'uuid' => 'c8d7e0f2-6c5a-4e4d-9f6a-3b4c5d6e7f8a',
      'component_id' => 'sdc.vale_canvas_test.notice',
      'inputs' => ['heading' => 'A very broken notice', 'gone' => 'Very gone'],
    ]);

    // Each point is its own text, named by its index. The empty point, the
    // block and the broken instance give none.
    $prose = $this->container->get('vale.component_tree_prose');
    $this->assertInstanceOf(ComponentTreeProse::class, $prose);
    $values = $prose->values($page->get('components'), ['label']);
    $this->assertSame([
      ['heading', ''],
      ['body', 'canvas_html_block'],
      ['points.0', ''],
      ['points.2', ''],
    ], array_map(static fn (array $value): array => [$value['prop'], (string) $value['format']], $values));
    $this->assertSame('A very good point', $values[3]['text']);
    $this->assertSame([0], array_values(array_unique(array_column($values, 'delta'))));

    // The error is refused at the point that holds it.
    $path = 'components.0.inputs.' . self::UUID . '.points.2';
    $this->assertSame([
      $path => "Components > Notice > points.2: Demo.Very: Avoid 'very'. (line 1: \"very\")",
    ], $this->valeViolations($page->validate()));
  }

  /**
   * Tests that the skip list and the switch both work.
   */
  public function testSkippedPropsAndSwitch(): void {
    // Without the switch, nothing is checked.
    $this->assertSame([], $this->valeViolations($this->page()->validate()));
    $this->assertSame([], $this->valeRuns());

    // A skipped prop is not checked.
    $this->enableCanvas(['heading']);
    $violations = $this->valeViolations($this->page()->validate());
    $this->assertSame(['components.0.inputs.' . self::UUID . '.label'], array_keys($violations));

    // A list of prop names comes from the settings form.
    $form_state = (new FormState())->setValues([
      'third_party_settings' => [
        'vale' => [
          'enabled' => 1,
          'min_level' => 'suggestion',
          'profile' => '',
          'skipped_props' => "label\nheading\n",
        ],
      ],
    ]);
    $this->container->get('form_builder')->submitForm(CanvasSettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $settings = FieldSettings::forField($this->override());
    $this->assertTrue($settings->enabled);
    $this->assertSame(['label', 'heading'], $settings->skippedProps);
    $this->assertSame([], $this->valeViolations($this->page()->validate()));
  }

  /**
   * Tests that turning the check off in the form removes its settings.
   */
  public function testFormTurnsOff(): void {
    $this->enableCanvas();
    // A programmed checkbox is unchecked by NULL. Any other value checks it.
    $form_state = (new FormState())->setValues(['third_party_settings' => ['vale' => ['enabled' => NULL]]]);
    $this->container->get('form_builder')->submitForm(CanvasSettingsForm::class, $form_state);
    $this->assertSame([], $form_state->getErrors());
    $this->assertSame([], $this->override()->getThirdPartySettings('vale'));
    $this->assertFalse(FieldSettings::forField($this->override())->enabled);
  }

  /**
   * Tests that publish-only mode refuses only the save that publishes.
   */
  public function testPublishOnly(): void {
    $this->config('vale.settings')->set('publish_only', TRUE)->save();
    // With the check off, the publishing save is not checked either.
    $this->assertSame([], $this->valeViolations($this->page()->validate()));
    $this->assertSame([], $this->valeRuns());

    $this->enableCanvas();

    $this->assertSame([], $this->valeViolations($this->page(published: FALSE)->validate()));

    $path = 'components.0.inputs.' . self::UUID . '.heading';
    $this->assertSame([
      $path => "Components > Notice > heading: Demo.Very: Avoid 'very'. (line 1: \"very\")",
    ], $this->valeViolations($this->page()->validate()));
  }

  /**
   * Tests that a workspace publish names the page that holds the text.
   */
  public function testWorkspacePublishNamesThePage(): void {
    $this->initializeWorkspacesModule();
    $this->config('vale.settings')->set('workspace_mode', 'advise')->save();
    $this->enableCanvas();
    $this->switchToWorkspace('stage');
    $this->page()->save();
    $this->switchToLive();

    $this->workspaces['stage']->publish();
    $warnings = array_map(strval(...), $this->container->get('messenger')->all()['warning'] ?? []);
    $this->assertStringStartsWith('canvas_page "Opening hours", Components &gt; Notice &gt; heading: Demo.Very:', $warnings[0]);
  }

  /**
   * Tests the content report and vale:lint, in each translation.
   */
  public function testReportAndLint(): void {
    $this->installConfig(['language']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->container->get('content_translation.manager')->setEnabled('canvas_page', 'canvas_page', TRUE);
    $this->enableCanvas();

    $page = $this->page();
    $page->save();
    // Canvas translates the inputs of each component, not the tree.
    $components = $page->get('components')->getValue();
    $components[0]['inputs'] = ['heading' => 'Un avis very court'];
    $page->addTranslation('fr', ['title' => 'Opening hours FR', 'components' => $components])->save();

    $walker = $this->container->get('vale.content_walker');
    $this->assertArrayHasKey('canvas_page:canvas_page', $walker->scannableBundles());
    $findings = $walker->bundle('canvas_page', 'canvas_page');

    $by_language = [];
    foreach ($findings as $finding) {
      $by_language[$finding->langcode][] = $finding;
    }
    $this->assertSame(['en', 'fr'], array_keys($by_language));

    // English has an error in the heading and a suggestion in the body.
    $english = array_map(static fn (Finding $finding): array => [$finding->prop, $finding->alert?->rule], $by_language['en']);
    $this->assertEqualsCanonicalizing([['heading', 'Demo.Very'], ['body', 'Demo.Really']], $english);
    $heading = $by_language['en'][array_search(['heading', 'Demo.Very'], $english, TRUE)];
    $this->assertSame(self::UUID, $heading->component);
    $this->assertSame('Opening hours > Components > Notice > heading', $heading->label);
    $this->assertSame('Opening hours', $heading->root()['label']);

    // The French translation is checked in its own words.
    $french = array_map(static fn (Finding $finding): ?string => $finding->alert?->match, $by_language['fr']);
    $this->assertContains('very', $french);
    $this->assertSame('Opening hours FR', $by_language['fr'][0]->root()['label']);

    // The report keeps the component and prop.
    $storage = $this->container->get('vale.finding_storage');
    $storage->replace('canvas_page', [(string) $page->id()], $findings);
    $rows = iterator_to_array($storage->rows(['rule' => 'Demo.Very']), FALSE);
    $this->assertSame([self::UUID], array_values(array_unique(array_column($rows, 'component'))));
    $this->assertSame(['heading'], array_values(array_unique(array_column($rows, 'prop'))));

    // So does vale:lint, which names them after the field.
    $tester = new CommandTester(new LintCommand(
      $this->container->get('vale.content_walker'),
      $this->container->get('vale.environment'),
      $storage,
      $this->container->get('datetime.time'),
      $this->container->get('vale.report_refresher'),
    ));
    $this->assertSame(1, $tester->execute(['scope' => 'canvas_page:canvas_page', '--format' => 'json']));
    $output = json_decode($tester->getDisplay(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($output);
    $lint_rows = array_values(array_filter($output, static fn (array $row): bool => $row['rule'] === 'Demo.Very' && $row['language'] === 'en'));
    $this->assertSame('components > Notice > heading', $lint_rows[0]['field']);
    $this->assertSame(self::UUID, $lint_rows[0]['component']);
    $this->assertSame('heading', $lint_rows[0]['prop']);
  }

}
