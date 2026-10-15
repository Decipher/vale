<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\AnnotatedCommand\Input\StdinHandler;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\vale\Drush\Commands\ValeCommands;
use Drupal\vale\ValeInstaller;
use Drush\Attributes\Command;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests the Drush commands' own logic.
 *
 * DrushLintTest runs them through Drush. This calls them directly.
 *
 * @group vale
 * @runTestsInSeparateProcesses
 */
#[Group('vale')]
#[RunTestsInSeparateProcesses]
class ValeCommandsTest extends KernelTestBase {

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
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('paragraph');
    $this->installEntitySchema('user');
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
   * Gives the commands.
   */
  protected function commands(): ValeCommands {
    return ValeCommands::create($this->container);
  }

  /**
   * Gives the rows of a result.
   *
   * @return array<mixed>
   *   The rows.
   */
  protected function rows(CommandResult $result): array {
    $data = $result->getOutputData();
    $this->assertInstanceOf(RowsOfFields::class, $data);
    return $data->getArrayCopy();
  }

  /**
   * Tests vale:lint.
   */
  public function testLint(): void {
    Node::create([
      'type' => 'page',
      'title' => 'Guide',
      'field_text' => 'We delve.',
      'field_content' => [Paragraph::create(['type' => 'docs_text', 'field_text' => 'This is very good.'])],
    ])->save();

    $result = $this->commands()->lint('node:page', ['workspace' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $rows = $this->rows($result);
    $this->assertSame(['Demo.Delve', 'Demo.Very'], array_column($rows, 'rule'));
    $this->assertSame('node 1: Guide', $rows[1]['host']);
    $this->assertSame('paragraph 1 (docs_text)', $rows[1]['entity']);

    // Everything, with only warnings, exits zero.
    $field = $this->container->get('entity_type.manager')->getStorage('field_config')->load('paragraph.docs_text.field_text');
    $field->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Very', 'level' => 'warning']])->save();
    $this->assertSame(0, $this->commands()->lint(NULL, ['workspace' => NULL])->getExitCode());

    // Refusals.
    $this->assertSame(1, $this->commands()->lint('node:nope', ['workspace' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->lint('node:page', ['workspace' => 'stage'])->getExitCode());
    $this->assertSame(1, $this->commands()->lint(NULL, ['workspace' => 'stage'])->getExitCode());
    $this->setSetting('vale_binary', NULL);
    $this->assertSame(1, $this->commands()->lint(NULL, ['workspace' => NULL])->getExitCode());
  }

  /**
   * Tests that a value Vale could not check fails vale:lint.
   */
  public function testLintUnchecked(): void {
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_text' => 'FAKE_CRASH'])->save();
    $result = $this->commands()->lint('node:page', ['workspace' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $this->assertSame([], $this->rows($result));
  }

  /**
   * Tests vale:status and vale:rules.
   */
  public function testStatusAndRules(): void {
    $result = $this->commands()->status();
    $this->assertSame(0, $result->getExitCode());
    $data = $result->getOutputData();
    $this->assertInstanceOf(PropertyList::class, $data);
    $this->assertSame('0.0.0-fake', $data['version']);
    $this->assertSame(3, $data['rules']);

    $this->assertSame(['Demo.Delve', 'Demo.Really', 'Demo.Very'], array_keys($this->commands()->rules()->getArrayCopy()));

    $this->setSetting('vale_binary', NULL);
    $this->assertSame(1, $this->commands()->status()->getExitCode());
  }

  /**
   * Tests vale:check.
   */
  public function testCheck(): void {
    $result = $this->commands()->check('node.page.field_text', 'We delve.', ['text-format' => NULL]);
    $this->assertSame(0, $result->getExitCode());
    $this->assertSame(['Demo.Delve'], array_column($this->rows($result), 'rule'));

    $this->assertSame(1, $this->commands()->check('node.page.field_text', 'This is very good.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page.field_code', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('nope.page.field_text', 'Fine.', ['text-format' => NULL])->getExitCode());
    $this->assertSame(1, $this->commands()->check('node.page.field_text', 'FAKE_CRASH', ['text-format' => NULL])->getExitCode());
  }

  /**
   * Tests that vale:install refuses an unpinned version without a checksum.
   */
  public function testInstallRefused(): void {
    $result = $this->commands()->install(['dir' => $this->valeDirectory . '/bin', 'vale-version' => '1.0.0', 'sha256' => NULL]);
    $this->assertSame(1, $result->getExitCode());
    $this->assertFileDoesNotExist($this->valeDirectory . '/bin/vale');
  }

  /**
   * Tests vale:check reading standard input.
   */
  public function testCheckStdin(): void {
    $file = $this->valeDirectory . '/draft.txt';
    file_put_contents($file, 'We delve.');
    $commands = $this->commands();
    $commands->setStdinHandler((new StdinHandler())->redirect($file));
    $result = $commands->check('node.page.field_text', '-', ['text-format' => NULL]);
    $this->assertSame(['Demo.Delve'], array_column($this->rows($result), 'rule'));
  }

  /**
   * Tests that vale:install installs Vale and says how to set it up.
   */
  public function testInstall(): void {
    $phar = new \PharData($this->valeDirectory . '/release.tar');
    $phar->addFromString('vale', "#!/bin/sh\necho 'vale version 9.9.9'\n");
    $phar->compress(\Phar::GZ);
    $archive = (string) file_get_contents($this->valeDirectory . '/release.tar.gz');
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $archive)]))]);

    $this->container->set('vale.installer', new ValeInstaller($client, $this->container->get('file_system')));
    $commands = $this->commands();
    $output = new BufferedOutput();
    $commands->setInput(new ArrayInput([]));
    $commands->setOutput($output);
    $result = $commands->install(['dir' => $this->valeDirectory . '/bin', 'vale-version' => '9.9.9', 'sha256' => hash('sha256', $archive)]);

    $this->assertSame(0, $result->getExitCode());
    $this->assertTrue(is_executable($this->valeDirectory . '/bin/vale'));
    $this->assertStringContainsString("\$settings['vale_binary'] = '" . $this->valeDirectory . "/bin/vale';", $output->fetch());
    // Beside the web root, the setting is given from $app_root.
    $directory = 'vale-test-' . bin2hex(random_bytes(4));
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $archive)]))]);
    $this->container->set('vale.installer', new ValeInstaller($client, $this->container->get('file_system')));
    $commands = $this->commands();
    $commands->setInput(new ArrayInput([]));
    $commands->setOutput($output);
    try {
      $commands->install(['dir' => dirname(DRUPAL_ROOT) . '/' . $directory, 'vale-version' => '9.9.9', 'sha256' => hash('sha256', $archive)]);
      $this->assertStringContainsString("\$settings['vale_binary'] = \$app_root . '/../$directory/vale';", $output->fetch());
    }
    finally {
      $this->container->get('file_system')->deleteRecursive(dirname(DRUPAL_ROOT) . '/' . $directory);
    }
  }

  /**
   * Tests that every command's attributes and defaults are well formed.
   *
   * Drush reads them through reflection when it discovers the commands, so
   * a mistake here breaks every command, not one.
   */
  public function testCommandDefinitions(): void {
    $commands = [];
    foreach ((new \ReflectionClass(ValeCommands::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
      foreach ($method->getAttributes() as $attribute) {
        $instance = $attribute->newInstance();
        if ($instance instanceof Command) {
          $commands[] = $instance->name;
        }
      }
      foreach ($method->getParameters() as $parameter) {
        if ($parameter->isDefaultValueAvailable()) {
          $parameter->getDefaultValue();
        }
      }
    }
    sort($commands);
    $this->assertSame(['vale:check', 'vale:install', 'vale:lint', 'vale:rules', 'vale:status'], $commands);
  }

}
