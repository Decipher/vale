<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\File\FileSystem;
use Drupal\Core\Site\Settings;
use Drupal\KernelTests\KernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Alert;
use Drupal\vale\Command\SyncCommand;
use Drupal\vale\FieldLinter;
use Drupal\vale\Hook\ValeRequirements;
use Drupal\vale\ValeEnvironment;
use Drupal\vale\ValeRunner;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests that each value is checked in the language of its translation.
 *
 * The fake Vale flags "très" only when its configuration sets Lang = fr.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class LanguageTest extends KernelTestBase {

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
    $this->setUpVale();
    // The configurations are written to a temporary directory of the test's
    // own, so a test that breaks it leaves the others alone.
    mkdir($this->valeDirectory . '/tmp');
    $this->setSetting('file_temp_path', $this->valeDirectory . '/tmp');
    $this->container->set('file_system', new FileSystem($this->container->get('stream_wrapper_manager'), Settings::getInstance()));
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'language', 'node', 'vale']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->createValeContentModel();
    $this->setVersion('3.27.0');
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    unset($_ENV['FAKE_VERSION']);
    parent::tearDown();
  }

  /**
   * Makes the fake Vale report a version.
   *
   * @param string|null $version
   *   The version, or NULL for the fake's own, which is older than 3.27.0.
   */
  protected function setVersion(?string $version): void {
    // Symfony's Process passes $_ENV on to Vale.
    if ($version === NULL) {
      unset($_ENV['FAKE_VERSION']);
    }
    else {
      $_ENV['FAKE_VERSION'] = $version;
    }
    $this->container->get('cache.vale')->deleteAll();
  }

  /**
   * Gives an environment with nothing remembered in memory.
   */
  protected function environment(): ValeEnvironment {
    return new ValeEnvironment(
      $this->container->get('cache.vale'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('file_system'),
      $this->container->get('language_manager'),
    );
  }

  /**
   * Gives a runner with its own environment, as a new request would.
   */
  protected function runner(): ValeRunner {
    return new ValeRunner(
      $this->environment(),
      $this->container->get('cache.vale'),
      $this->container->get('config.factory'),
      $this->container->get('datetime.time'),
      $this->container->get('logger.channel.vale'),
    );
  }

  /**
   * Gives the language of each lint run, oldest first.
   *
   * @return string[]
   *   The Lang the fake Vale read each run in.
   */
  protected function languages(): array {
    $log = $this->valeDirectory . '/langs.log';
    $lines = is_file($log) ? array_filter(explode("\n", (string) file_get_contents($log))) : [];
    return array_values(array_map(static fn (string $line): string => substr($line, strrpos($line, ' ') + 1), $lines));
  }

  /**
   * Gives the rules of some alerts.
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts.
   *
   * @return string[]
   *   The rules.
   */
  protected function rules(array $alerts): array {
    return array_map(static fn (Alert $alert): string => $alert->rule, $alerts);
  }

  /**
   * Creates a page with a French translation.
   */
  protected function createTranslatedPage(): NodeInterface {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Page',
      'langcode' => 'en',
      'field_text' => ['value' => 'Très very.', 'format' => 'plain_text'],
    ]);
    $node->addTranslation('fr', ['title' => 'Page', 'field_text' => ['value' => 'Très very.', 'format' => 'plain_text']]);
    $node->save();
    return $node;
  }

  /**
   * Tests that a French translation is read in French.
   */
  public function testTranslation(): void {
    $node = $this->createTranslatedPage();
    $linter = $this->container->get(FieldLinter::class);
    $this->assertSame(['Demo.Very'], $this->rules($linter->lint($node->get('field_text'))[0]->alerts));
    $this->assertSame(['Demo.Tres', 'Demo.Very'], $this->rules($linter->lint($node->getTranslation('fr')->get('field_text'))[0]->alerts));
    $this->assertSame(['en', 'fr'], $this->languages());

    // Saving the French translation is refused for the French error.
    $violations = [];
    foreach ($node->getTranslation('fr')->validate() as $violation) {
      $violations[] = (string) $violation->getMessage();
    }
    $this->assertStringContainsString('Demo.Tres', implode("\n", $violations));

    // The configuration is written to the temporary directory, with the
    // styles where the site's configuration has them.
    $files = glob($this->container->get('file_system')->getTempDirectory() . '/vale/*.fr.ini') ?: [];
    $this->assertCount(1, $files);
    $this->assertStringStartsWith('StylesPath = ' . $this->valeDirectory . "/styles\nLang = fr\nMinAlertLevel = suggestion\n", (string) file_get_contents($files[0]));
    $this->assertStringContainsString("[*.field_callout.md]\n", (string) file_get_contents($files[0]));

    // Each language has its own cached result. English keeps the key it had.
    $runner = $this->runner();
    $runner->lint('Très very.', 'txt', 'node.page.field_text', TRUE, 'fr');
    $runner->lint('Très very.', 'txt', 'node.page.field_text', TRUE, 'fr');
    $runner->lint('Très very.', 'txt', 'node.page.field_text');
    $runner->lint('Très very.', 'txt', 'node.page.field_text', TRUE, 'en');
    $this->assertSame(['en', 'fr', 'fr', 'en'], $this->languages());
    $environment = $this->environment();
    $cid = 'lint:' . hash('sha256', $environment->fingerprint() . "\0node.page.field_text.txt\0Très very.");
    $this->assertNotFalse($this->container->get('cache.vale')->get($cid));
  }

  /**
   * Tests the languages that are read as English.
   */
  public function testEnglish(): void {
    $environment = $this->environment();
    foreach (['en', 'en-gb', 'und', 'zxx', 'x-default', 'not a langcode'] as $langcode) {
      $this->assertNull($environment->valeLanguage($langcode), $langcode);
      $this->assertNull($environment->languageConfig($langcode), $langcode);
      $this->assertNull($environment->languageConfigPath($langcode), $langcode);
    }
    $this->assertSame('pt-br', $environment->valeLanguage('pt-br'));

    $result = $this->runner()->lint('Très very.', 'txt', 'node.page.field_text', TRUE, 'und');
    $this->assertSame(['Demo.Very'], $this->rules($result->alerts));
    $this->assertSame(['en'], $this->languages());
  }

  /**
   * Tests the configuration a language is read with.
   */
  public function testLanguageConfig(): void {
    // The site's top-level Lang is replaced, and a section's own Lang wins.
    file_put_contents($this->valeDirectory . '/.vale.ini', "Lang = de\nStylesPath = \"styles\"\nStylesPath = other\n\n[*]\nBasedOnStyles = Demo\n\n[*.field_quote.txt]\nLang = es\n");
    $environment = $this->environment();
    $this->assertSame("StylesPath = " . $this->valeDirectory . "/styles\nLang = fr\n\n[*]\nBasedOnStyles = Demo\n\n[*.field_quote.txt]\nLang = es\n", $environment->languageConfig('fr'));
    // The browser config route gives the StylesPath its build reads.
    $this->assertStringStartsWith("StylesPath = /styles\nLang = fr\n", (string) $environment->languageConfig('fr', '/styles'));

    $runner = $this->runner();
    $this->assertSame(['Demo.Tres'], $this->rules($runner->lint('Très.', 'txt', 'node.page.field_text', TRUE, 'fr')->alerts));
    $this->assertSame([], $runner->lint('Très.', 'txt', 'node.page.field_quote', TRUE, 'fr')->alerts);
    $this->assertSame(['fr', 'es'], $this->languages());

    // An absolute StylesPath is kept, and a configuration with none sets
    // none.
    file_put_contents($this->valeDirectory . '/.vale.ini', 'StylesPath = ' . $this->valeDirectory . "/styles\n");
    $this->assertSame('StylesPath = ' . $this->valeDirectory . "/styles\nLang = fr\n", $this->environment()->languageConfig('fr'));
    file_put_contents($this->valeDirectory . '/.vale.ini', "[*]\nBasedOnStyles = Demo\n");
    $this->assertSame("Lang = fr\n[*]\nBasedOnStyles = Demo\n", $this->environment()->languageConfig('fr'));

    // Without a configuration, there is none to change.
    $this->setSetting('vale_config', NULL);
    $this->assertNull($this->environment()->languageConfig('fr'));
    $this->assertNull($this->environment()->modelsConfigPath());
  }

  /**
   * Tests a configuration that cannot be written.
   */
  public function testWriteFailure(): void {
    // A file where the directory should be stops the directory being made.
    $directory = $this->container->get('file_system')->getTempDirectory() . '/vale';
    file_put_contents($directory, '');
    $result = $this->runner()->lint('Très.', 'txt', 'node.page.field_text', TRUE, 'fr');
    $this->assertFalse($result->isChecked());
    $this->assertStringContainsString('could not be written to ' . $directory, $result->reason);

    $tester = $this->sync();
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('Vale could not sync the language models.', $tester->getErrorOutput());
  }

  /**
   * Tests a Vale binary older than 3.27.0.
   */
  public function testOldBinary(): void {
    $this->setVersion(NULL);
    $environment = $this->environment();
    $this->assertFalse($environment->supportsLanguages());
    $this->assertNull($environment->valeLanguage('fr'));
    $this->assertNull($environment->languageConfig('fr'));
    $this->assertNull($environment->modelsConfigPath());

    $result = $this->runner()->lint('Très very.', 'txt', 'node.page.field_text', TRUE, 'fr');
    $this->assertSame(['Demo.Very'], $this->rules($result->alerts));
    $this->assertSame(['en'], $this->languages());

    $status = $environment->languageStatus();
    $this->assertSame(1, $status['severity']);
    $this->assertSame('English, French', $status['value']);
    $this->assertSame('Vale 0.0.0-fake checks every value in English. Checking French in their own language needs Vale 3.27.0 or later.', $status['description']);

    $tester = $this->sync();
    $this->assertSame(0, $tester->getStatusCode());
    $this->assertStringContainsString('Vale 0.0.0-fake reads every value as English. Vale 3.27.0 or later downloads a model for fr.', preg_replace('/\s+/', ' ', $tester->getDisplay()));
    $this->assertStringNotContainsString('language model(s)', $tester->getDisplay());

    // A Vale that does not report its version is read as too old.
    $_ENV['FAKE_PROBE_FAIL'] = '1';
    try {
      $this->container->get('cache.vale')->deleteAll();
      $this->assertStringStartsWith('Vale did not report its version', $this->environment()->languageStatus()['description']);
    }
    finally {
      unset($_ENV['FAKE_PROBE_FAIL']);
    }
  }

  /**
   * Runs vale:sync.
   */
  protected function sync(): CommandTester {
    $tester = new CommandTester(new SyncCommand($this->environment()));
    $tester->execute([], ['capture_stderr_separately' => TRUE]);
    return $tester;
  }

  /**
   * Tests that vale:sync downloads a model for each of the site's languages.
   */
  public function testSyncModels(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    ConfigurableLanguage::createFromLangcode('ja')->save();
    $tester = $this->sync();
    $this->assertSame(0, $tester->getStatusCode());
    $display = $tester->getDisplay();
    $this->assertStringContainsString('Synced 1 package(s)', $display);
    $this->assertStringContainsString('Synced 0 package(s), 2 language model(s)', $display);
    $this->assertStringContainsString("No model is published for language 'ja'", $display);
    $this->assertStringContainsString('Vale now loads 4 rules.', $display);
    $this->assertFileExists($this->valeDirectory . '/styles/config/models/fr/model.json');
    $this->assertFileExists($this->valeDirectory . '/styles/config/models/de/model.json');

    // A failed model download fails the command.
    $_ENV['FAKE_SYNC_FAIL'] = '1';
    try {
      $tester = $this->sync();
    }
    finally {
      unset($_ENV['FAKE_SYNC_FAIL']);
    }
    $this->assertSame(1, $tester->getStatusCode());

    // The styles can sync while the models fail.
    $_ENV['FAKE_SYNC_MODELS_FAIL'] = '1';
    try {
      $tester = $this->sync();
    }
    finally {
      unset($_ENV['FAKE_SYNC_MODELS_FAIL']);
    }
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('Vale could not sync the language models. E100 could not fetch the language models.', preg_replace('/\s+/', ' ', $tester->getErrorOutput()));
    $this->assertStringNotContainsString('Vale now loads', $tester->getDisplay());
  }

  /**
   * Tests the site status report for the site's languages.
   */
  public function testStatusReport(): void {
    ConfigurableLanguage::createFromLangcode('de')->save();
    $status = $this->environment()->languageStatus();
    $this->assertSame(1, $status['severity']);
    $this->assertSame('English, French, German', $status['value']);
    $this->assertSame('Each value is checked in the language of its translation. Vale has no model for French, German, so it splits their sentences by punctuation. Run vale:sync to download the models Vale publishes. There is no spelling dictionary for French, German in config/dictionaries, so Vale skips its spelling check in them.', $status['description']);

    // A model and a dictionary each, named the plain or the Hunspell way.
    $config = $this->valeDirectory . '/styles/config';
    foreach (['models/fr', 'models/de', 'dictionaries'] as $directory) {
      mkdir($config . '/' . $directory, 0777, TRUE);
    }
    touch($config . '/models/fr/model.json');
    touch($config . '/models/de/model.json');
    touch($config . '/dictionaries/fr.dic');
    touch($config . '/dictionaries/de_DE.dic');
    $this->container->get('cache.vale')->deleteAll();
    $hooks = $this->container->get('class_resolver')->getInstanceFromDefinition(ValeRequirements::class);
    $requirement = $hooks->languageRequirement();
    $this->assertSame(0, $requirement['severity']);
    $this->assertSame('Vale languages', (string) $requirement['title']);
    $this->assertSame('Each value is checked in the language of its translation.', $requirement['description']);

    // Both hooks give the enum where it exists, and the integer before it.
    $this->container->get('module_handler')->loadInclude('vale', 'install');
    $this->assertSame(enum_exists(RequirementSeverity::class) ? RequirementSeverity::OK : 0, vale_requirements('runtime')['vale_languages']['severity']);
    if (class_exists(RequirementSeverity::class)) {
      $this->assertSame(RequirementSeverity::OK, $hooks->runtime()['vale_languages']['severity']);
    }

    // A site with only English needs nothing more.
    ConfigurableLanguage::load('fr')?->delete();
    ConfigurableLanguage::load('de')?->delete();
    $status = $this->environment()->languageStatus();
    $this->assertSame(0, $status['severity']);
    $this->assertSame('Every value is checked in English.', $status['description']);
  }

}
