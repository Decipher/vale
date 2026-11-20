<?php

declare(strict_types=1);

namespace Drupal\Tests\vale\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\DependencyInjection\Compiler\ConsoleCompilerPass;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\vale\Traits\ValeContentTrait;
use Drupal\Tests\vale\Traits\ValeTestTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\paragraphs\Entity\ParagraphsType;
use Drupal\vale\Command\CheckCommand;
use Drupal\vale\Command\InstallCommand;
use Drupal\vale\Command\LintCommand;
use Drupal\vale\Command\RulesCommand;
use Drupal\vale\Command\StatusCommand;
use Drupal\vale\ValeInstaller;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the console commands.
 *
 * DrushLintTest runs them through Drush. This runs them with Symfony's
 * command tester, as core's dr would.
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
   * Runs a command.
   *
   * The command is built the way core's dr builds it, from the type of
   * each constructor argument, so a missing service alias fails here.
   *
   * @param class-string<\Symfony\Component\Console\Command\Command> $class
   *   The command class.
   * @param array<string, mixed> $input
   *   The arguments and options.
   *
   * @return \Symfony\Component\Console\Tester\CommandTester
   *   The tester, after the run.
   */
  protected function runCommand(string $class, array $input = []): CommandTester {
    $tester = new CommandTester($this->command($class));
    $tester->execute($input, ['capture_stderr_separately' => TRUE]);
    return $tester;
  }

  /**
   * Builds a command from its constructor's argument types.
   *
   * @param class-string<\Symfony\Component\Console\Command\Command> $class
   *   The command class.
   *
   * @return \Symfony\Component\Console\Command\Command
   *   The command.
   */
  protected function command(string $class): Command {
    $arguments = [];
    foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $parameter) {
      $type = $parameter->getType();
      $this->assertInstanceOf(\ReflectionNamedType::class, $type);
      $arguments[] = $this->container->get($type->getName());
    }
    return (new \ReflectionClass($class))->newInstanceArgs($arguments);
  }

  /**
   * Gives the JSON a command printed.
   *
   * @return array<mixed>
   *   The decoded JSON.
   */
  protected function json(CommandTester $tester): array {
    $data = json_decode($tester->getDisplay(), TRUE, 512, JSON_THROW_ON_ERROR);
    $this->assertIsArray($data);
    return $data;
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

    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'json']);
    $this->assertSame(1, $tester->getStatusCode());
    $rows = $this->json($tester);
    $this->assertSame(['Demo.Delve', 'Demo.Very'], array_column($rows, 'rule'));
    $this->assertSame('node 1: Guide', $rows[1]['host']);
    $this->assertSame('paragraph 1 (docs_text)', $rows[1]['entity']);
    // JSON gives every field, as Drush's did.
    $this->assertSame([
      'host', 'entity', 'entity_type', 'id', 'bundle', 'label', 'language',
      'host_type', 'host_id', 'host_label', 'field', 'delta', 'component',
      'prop', 'line', 'start', 'end', 'level', 'rule', 'message', 'text',
    ], array_keys($rows[0]));

    // The table shows the default fields only.
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page']);
    $display = $tester->getDisplay();
    $this->assertStringContainsString('Host', $display);
    $this->assertStringContainsString('paragraph 1 (docs_text)', $display);
    $this->assertStringContainsString('Demo.Very', $display);
    $this->assertStringNotContainsString('Message', $display);

    // Everything, with only warnings, exits zero.
    $field = $this->container->get('entity_type.manager')->getStorage('field_config')->load('paragraph.docs_text.field_text');
    $field->setThirdPartySetting('vale', 'level_overrides', [['rule' => 'Demo.Very', 'level' => 'warning']])->save();
    $this->assertSame(0, $this->runCommand(LintCommand::class)->getStatusCode());

    // Refusals.
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:nope']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('There is no node bundle "nope".', $tester->getErrorOutput());
    $this->assertSame('', $tester->getDisplay());
    // A bundle with no field opted in is refused, so it never reads as a pass.
    NodeType::create(['type' => 'bare', 'name' => 'Bare'])->save();
    $this->assertSame(1, $this->runCommand(LintCommand::class, ['scope' => 'node:bare'])->getStatusCode());
    // So is a bundle whose components have no field opted in.
    NodeType::create(['type' => 'refs', 'name' => 'Refs'])->save();
    ParagraphsType::create(['id' => 'plain', 'label' => 'Plain'])->save();
    FieldConfig::create([
      'field_name' => 'field_content',
      'entity_type' => 'node',
      'bundle' => 'refs',
      'label' => 'Content',
      'settings' => [
        'handler' => 'default:paragraph',
        'handler_settings' => ['target_bundles' => ['plain' => 'plain']],
      ],
    ])->save();
    $this->assertSame(1, $this->runCommand(LintCommand::class, ['scope' => 'node:refs'])->getStatusCode());
    $this->assertSame(1, $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--workspace' => 'stage'])->getStatusCode());
    $this->assertSame(1, $this->runCommand(LintCommand::class, ['--workspace' => 'stage'])->getStatusCode());
    $this->setSetting('vale_binary', NULL);
    $tester = $this->runCommand(LintCommand::class);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('Vale could not be run.', $tester->getErrorOutput());
  }

  /**
   * Tests that a value Vale could not check fails vale:lint.
   */
  public function testLintUnchecked(): void {
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_text' => 'FAKE_CRASH'])->save();
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'json']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertSame([], $this->json($tester));
    $this->assertStringContainsString('node 1, field_text: not checked.', $tester->getErrorOutput());
  }

  /**
   * Tests --format=csv and --fields.
   */
  public function testCsvAndFields(): void {
    Node::create(['type' => 'page', 'title' => 'Guide', 'field_text' => 'We delve, really.'])->save();

    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'csv', '--fields' => 'entity,Rule,message']);
    $this->assertSame(0, $tester->getStatusCode());
    $this->assertSame(
      "Entity,Rule,Message\n\"node 1 (page)\",Demo.Delve,\"'delve' is an AI tell.\"\n\"node 1 (page)\",Demo.Really,\"Consider removing 'really'.\"\n",
      $tester->getDisplay(),
    );

    // CSV gives every field when --fields is left out.
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'csv']);
    $lines = explode("\n", trim($tester->getDisplay()));
    $this->assertCount(3, $lines);
    $this->assertStringStartsWith('Host,Entity,"Entity type",ID,Bundle,', $lines[0]);

    // JSON keeps only the fields asked for, in that order.
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'json', '--fields' => 'rule,line']);
    $this->assertSame([['rule' => 'Demo.Delve', 'line' => 1], ['rule' => 'Demo.Really', 'line' => 1]], $this->json($tester));

    // A table can show the message too.
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--fields' => 'rule,message']);
    $this->assertStringContainsString("'delve' is an AI tell.", $tester->getDisplay());
    $this->assertStringNotContainsString('node 1 (page)', $tester->getDisplay());

    // An unknown field or format is refused before Vale runs.
    $runs = count($this->valeRuns());
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--fields' => 'rule,nope']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('The field "nope" is not known.', $tester->getErrorOutput());
    $tester = $this->runCommand(LintCommand::class, ['scope' => 'node:page', '--format' => 'yaml']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('The format "yaml" is not known.', $tester->getErrorOutput());
    $this->assertCount($runs, $this->valeRuns());

    // The other commands refuse them the same way.
    foreach ([
      [StatusCommand::class, []],
      [RulesCommand::class, []],
      [CheckCommand::class, ['field' => 'node.page.field_text', 'text' => 'Fine.']],
    ] as [$class, $arguments]) {
      $tester = $this->runCommand($class, $arguments + ['--format' => 'yaml']);
      $this->assertSame(1, $tester->getStatusCode(), $class);
      $this->assertStringContainsString('The format "yaml" is not known.', $tester->getErrorOutput(), $class);
      $tester = $this->runCommand($class, $arguments + ['--fields' => 'nope']);
      $this->assertSame(1, $tester->getStatusCode(), $class);
      $this->assertStringContainsString('The field "nope" is not known.', $tester->getErrorOutput(), $class);
    }
    $this->assertCount($runs, $this->valeRuns());
  }

  /**
   * Tests vale:status and vale:rules.
   */
  public function testStatusAndRules(): void {
    $tester = $this->runCommand(StatusCommand::class, ['--format' => 'json']);
    $this->assertSame(0, $tester->getStatusCode());
    $data = $this->json($tester);
    $this->assertSame(['available', 'binary', 'version', 'config', 'styles', 'rules', 'description'], array_keys($data));
    $this->assertSame('Yes', $data['available']);
    $this->assertSame('0.0.0-fake', $data['version']);
    $this->assertSame(3, $data['rules']);

    $display = $this->runCommand(StatusCommand::class)->getDisplay();
    $this->assertMatchesRegularExpression('/^ Version +: 0\.0\.0-fake$/m', $display);
    $this->assertMatchesRegularExpression('/^ Rules +: 3$/m', $display);

    $tester = $this->runCommand(StatusCommand::class, ['--format' => 'csv', '--fields' => 'version,rules']);
    $this->assertSame("Version,Rules\n0.0.0-fake,3\n", $tester->getDisplay());

    // A field given by name and by label is printed once.
    $tester = $this->runCommand(StatusCommand::class, ['--format' => 'csv', '--fields' => 'version,Version,rules']);
    $this->assertSame("Version,Rules\n0.0.0-fake,3\n", $tester->getDisplay());

    // Rules are keyed by name in JSON, as they were.
    $tester = $this->runCommand(RulesCommand::class, ['--format' => 'json']);
    $this->assertSame(0, $tester->getStatusCode());
    $rules = $this->json($tester);
    $this->assertSame(['Demo.Delve', 'Demo.Really', 'Demo.Very'], array_keys($rules));
    $this->assertSame(['rule', 'level', 'message', 'description', 'link'], array_keys($rules['Demo.Delve']));
    $display = $this->runCommand(RulesCommand::class)->getDisplay();
    $this->assertStringContainsString('Demo.Really', $display);
    $this->assertStringNotContainsString('Description', $display);

    $this->setSetting('vale_binary', NULL);
    $tester = $this->runCommand(StatusCommand::class, ['--format' => 'json']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertSame('No', $this->json($tester)['available']);
  }

  /**
   * Tests vale:check.
   */
  public function testCheck(): void {
    $tester = $this->runCommand(CheckCommand::class, ['field' => 'node.page.field_text', 'text' => 'We delve.', '--format' => 'json']);
    $this->assertSame(0, $tester->getStatusCode());
    $this->assertSame([
      [
        'line' => 1,
        'start' => 4,
        'end' => 8,
        'level' => 'warning',
        'rule' => 'Demo.Delve',
        'message' => "'delve' is an AI tell.",
        'text' => 'delve',
      ],
    ], $this->json($tester));

    $this->assertSame(1, $this->runCommand(CheckCommand::class, ['field' => 'node.page.field_text', 'text' => 'This is very good.'])->getStatusCode());
    $tester = $this->runCommand(CheckCommand::class, ['field' => 'node.page.field_code', 'text' => 'Fine.']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('is not checked by Vale', $tester->getErrorOutput());
    $tester = $this->runCommand(CheckCommand::class, ['field' => 'node.page', 'text' => 'Fine.']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('There is no field "node.page".', $tester->getErrorOutput());
    $this->assertSame(1, $this->runCommand(CheckCommand::class, ['field' => 'nope.page.field_text', 'text' => 'Fine.'])->getStatusCode());
    $tester = $this->runCommand(CheckCommand::class, ['field' => 'node.page.field_text', 'text' => 'FAKE_CRASH']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertStringContainsString('The text was not checked.', $tester->getErrorOutput());
  }

  /**
   * Tests vale:check reading standard input.
   */
  public function testCheckStdin(): void {
    foreach (['-', NULL] as $text) {
      $tester = new CommandTester($this->command(CheckCommand::class));
      $tester->setInputs(['We delve.']);
      $tester->execute(['field' => 'node.page.field_text', 'text' => $text, '--format' => 'json']);
      $this->assertSame(0, $tester->getStatusCode());
      $this->assertSame(['Demo.Delve'], array_column($this->json($tester), 'rule'));
    }
  }

  /**
   * Tests that vale:check does not wait on a terminal for input.
   */
  public function testCheckStdinTerminal(): void {
    // A readable device can still fail to open.
    $terminal = is_readable('/dev/ptmx') ? @fopen('/dev/ptmx', 'r+') : FALSE;
    if ($terminal === FALSE) {
      $this->markTestSkipped('This system has no pseudo terminal.');
    }
    $input = new ArrayInput(['field' => 'node.page.field_text', 'text' => '-']);
    $input->setStream($terminal);
    $output = new BufferedOutput();
    $this->assertSame(1, $this->command(CheckCommand::class)->run($input, $output));
    $this->assertStringContainsString('Give the text as an argument, or pipe it in.', $output->fetch());
  }

  /**
   * Tests that vale:install refuses a version that is not one.
   */
  public function testInstallRefused(): void {
    $tester = $this->runCommand(InstallCommand::class, ['--dir' => $this->valeDirectory . '/bin', '--vale-version' => 'newest']);
    $this->assertSame(1, $tester->getStatusCode());
    $this->assertNotSame('', $tester->getErrorOutput());
    $this->assertFileDoesNotExist($this->valeDirectory . '/bin/vale');
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
    $tester = $this->runCommand(InstallCommand::class, ['--dir' => $this->valeDirectory . '/bin', '--vale-version' => '9.9.9', '--sha256' => hash('sha256', $archive)]);

    $this->assertSame(0, $tester->getStatusCode());
    $this->assertTrue(is_executable($this->valeDirectory . '/bin/vale'));
    $this->assertStringContainsString("\$settings['vale_binary'] = '" . $this->valeDirectory . "/bin/vale';", $tester->getDisplay());

    // A quote in the path is escaped, so the line can be pasted into PHP.
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $archive)]))]);
    $this->container->set('vale.installer', new ValeInstaller($client, $this->container->get('file_system')));
    $tester = $this->runCommand(InstallCommand::class, ['--dir' => $this->valeDirectory . "/vale's-bin", '--vale-version' => '9.9.9', '--sha256' => hash('sha256', $archive)]);
    $this->assertSame(0, $tester->getStatusCode());
    $this->assertStringContainsString("\$settings['vale_binary'] = '" . $this->valeDirectory . "/vale\\'s-bin/vale';", $tester->getDisplay());
    // Beside the web root, the setting is given from $app_root.
    $directory = 'vale-test-' . bin2hex(random_bytes(4));
    $client = new Client(['handler' => HandlerStack::create(new MockHandler([new Response(200, [], $archive)]))]);
    $this->container->set('vale.installer', new ValeInstaller($client, $this->container->get('file_system')));
    try {
      $tester = $this->runCommand(InstallCommand::class, ['--dir' => dirname(DRUPAL_ROOT) . '/' . $directory, '--vale-version' => '9.9.9', '--sha256' => hash('sha256', $archive)]);
      $this->assertStringContainsString("\$settings['vale_binary'] = \$app_root . '/../$directory/vale';", $tester->getDisplay());
    }
    finally {
      $this->container->get('file_system')->deleteRecursive(dirname(DRUPAL_ROOT) . '/' . $directory);
    }
  }

  /**
   * Tests that core and Drush both find every command.
   *
   * Core's dr finds the classes in src/Command by their attribute. Drush 12
   * to 14 find them in drush.services.yml instead.
   */
  public function testCommandDiscovery(): void {
    $classes = [
      CheckCommand::class,
      InstallCommand::class,
      LintCommand::class,
      RulesCommand::class,
      StatusCommand::class,
    ];
    $module = dirname(__DIR__, 3);
    // A new command must be added to this test and to drush.services.yml.
    $files = array_map(static fn (string $file): string => 'Drupal\\vale\\Command\\' . basename($file, '.php'), glob($module . '/src/Command/*Command.php') ?: []);
    $this->assertEqualsCanonicalizing($classes, $files);

    $names = [];
    foreach ($classes as $class) {
      $attributes = (new \ReflectionClass($class))->getAttributes(AsCommand::class);
      $this->assertCount(1, $attributes, $class);
      $names[$class] = $attributes[0]->newInstance()->name;
      // Configuring the command checks its arguments and options.
      $this->assertSame($names[$class], $this->command($class)->getName());
    }
    $this->assertSame(['vale:check', 'vale:install', 'vale:lint', 'vale:rules', 'vale:status'], array_values($names));

    $services = Yaml::decode((string) file_get_contents($module . '/drush.services.yml'))['services'];
    $this->assertEqualsCanonicalizing($classes, array_keys($services));
    foreach ($classes as $class) {
      $service = $services[$class];
      $this->assertSame($class, $service['class']);
      $this->assertSame([['name' => 'console.command']], $service['tags']);
      // Drush builds each command from the listed services.
      $arguments = array_map(fn (string $argument): object => $this->container->get(substr($argument, 1)), $service['arguments'] ?? []);
      $command = (new \ReflectionClass($class))->newInstanceArgs($arguments);
      $this->assertSame($names[$class], $command->getName());
    }

    // A core that ships dr registers the commands by itself.
    if (class_exists(ConsoleCompilerPass::class)) {
      foreach ($classes as $class) {
        $this->assertInstanceOf($class, $this->container->get($class));
      }
    }
  }

}
