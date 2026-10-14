<?php

declare(strict_types=1);

namespace Drupal\vale\Drush\Commands;

use Drupal\vale\Alert;
use Consolidation\AnnotatedCommand\CommandResult;
use Consolidation\AnnotatedCommand\Input\StdinAwareInterface;
use Consolidation\AnnotatedCommand\Input\StdinAwareTrait;
use Consolidation\OutputFormatters\StructuredData\PropertyList;
use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\vale\ContentWalker;
use Drupal\vale\FieldLinter;
use Drupal\vale\Finding;
use Drupal\vale\ValeEnvironment;
use Drupal\vale\ValeInstaller;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Lints stored content from the command line.
 */
final class ValeCommands extends DrushCommands implements StdinAwareInterface {

  use StdinAwareTrait;

  /**
   * Constructs a ValeCommands.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   * @param \Drupal\vale\FieldLinter $fieldLinter
   *   The field linter.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\vale\ValeInstaller $valeInstaller
   *   The Vale installer.
   */
  public function __construct(
    private readonly ContentWalker $contentWalker,
    private readonly ValeEnvironment $valeEnvironment,
    private readonly FieldLinter $fieldLinter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ValeInstaller $valeInstaller,
  ) {
    parent::__construct();
  }

  /**
   * Creates the commands.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   *
   * @return static
   *   The commands.
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('vale.content_walker'),
      $container->get('vale.environment'),
      $container->get('vale.field_linter'),
      $container->get('entity_field.manager'),
      $container->get('vale.installer'),
    );
  }

  /**
   * Lints the prose in opted-in fields of stored content.
   *
   * Exits with 1 when an error-level finding is reported or a value could
   * not be checked, so a CI job can gate a deploy on it.
   *
   * The table leaves out each rule's message, so rule names do not wrap.
   * JSON output and --fields include it.
   *
   * @param string|null $scope
   *   An entity type and bundle, such as node:page.
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   The findings and the exit code.
   */
  #[CLI\Command(name: 'vale:lint')]
  #[CLI\Argument(name: 'scope', description: 'An entity type and bundle, such as node:page. Leave it out to lint all content.')]
  #[CLI\Option(name: 'workspace', description: 'Lint only the revisions this workspace tracks, as they read in it.')]
  #[CLI\Usage(name: 'drush vale:lint node:page', description: 'Lint the latest revision of every page, with its paragraphs.')]
  #[CLI\Usage(name: 'drush vale:lint --workspace=stage', description: 'Lint what the stage workspace would publish.')]
  #[CLI\Usage(name: 'drush vale:lint --format=json', description: 'Lint all content and print one JSON record per finding.')]
  #[CLI\FieldLabels(labels: [
    'host' => 'Host',
    'entity' => 'Entity',
    'entity_type' => 'Entity type',
    'id' => 'ID',
    'bundle' => 'Bundle',
    'label' => 'Label',
    'host_type' => 'Host type',
    'host_id' => 'Host ID',
    'host_label' => 'Host label',
    'field' => 'Field',
    'delta' => 'Delta',
    'line' => 'Line',
    'start' => 'Start',
    'end' => 'End',
    'level' => 'Level',
    'rule' => 'Rule',
    'message' => 'Message',
    'text' => 'Text',
  ])]
  #[CLI\DefaultTableFields(fields: ['host', 'entity', 'field', 'line', 'level', 'rule', 'text'])]
  public function lint(?string $scope = NULL, array $options = ['workspace' => self::REQ, 'format' => 'table']): CommandResult {
    $reason = $this->valeEnvironment->unavailableReason();
    if ($reason !== NULL) {
      $this->logger()?->error('Vale could not be run. ' . $reason);
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $workspace = is_string($options['workspace'] ?? NULL) ? $options['workspace'] : NULL;
    try {
      if ($workspace !== NULL && $scope !== NULL) {
        throw new \InvalidArgumentException('Give either a bundle or --workspace, not both.');
      }
      if ($workspace !== NULL) {
        $findings = $this->contentWalker->workspace($workspace);
      }
      elseif ($scope !== NULL) {
        [$entity_type, $bundle] = explode(':', $scope, 2) + [1 => ''];
        $findings = $this->contentWalker->bundle($entity_type, $bundle);
      }
      else {
        $findings = $this->contentWalker->all();
      }
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()?->error($e->getMessage());
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $rows = [];
    $failed = FALSE;
    foreach ($findings as $finding) {
      if ($finding->alert === NULL) {
        $this->logger()?->error(sprintf('%s %s, %s: not checked. %s', $finding->entityType, $finding->entityId, $finding->field, $finding->reason));
        $failed = TRUE;
        continue;
      }
      $failed = $failed || $finding->isError();
      $rows[] = $this->row($finding);
    }
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * Downloads Vale, checks its checksum and installs it.
   *
   * The module pins a Vale version and the SHA-256 of each platform's
   * archive, so every server gets the same binary and a changed download is
   * refused. Run it where the site is built, such as a container build step.
   *
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   Exit code 0 when Vale was installed.
   */
  #[CLI\Command(name: 'vale:install')]
  #[CLI\Option(name: 'dir', description: 'The directory to install into. Defaults to bin/ beside the web root.')]
  #[CLI\Option(name: 'vale-version', description: 'A Vale version other than the pinned one. Needs --sha256.')]
  #[CLI\Option(name: 'sha256', description: "The SHA-256 of that version's archive, from its release checksums.")]
  #[CLI\Usage(name: 'drush vale:install', description: 'Install the pinned Vale into bin/ beside the web root.')]
  #[CLI\Usage(name: 'drush vale:install --dir=/usr/local/bin', description: 'Install it somewhere else.')]
  public function install(array $options = ['dir' => self::REQ, 'vale-version' => self::REQ, 'sha256' => self::REQ]): CommandResult {
    $directory = is_string($options['dir'] ?? NULL) ? $options['dir'] : dirname(DRUPAL_ROOT) . '/bin';
    try {
      $binary = $this->valeInstaller->install(
        $directory,
        is_string($options['vale-version'] ?? NULL) ? $options['vale-version'] : NULL,
        is_string($options['sha256'] ?? NULL) ? $options['sha256'] : NULL,
      );
    }
    catch (\RuntimeException | \InvalidArgumentException $e) {
      $this->logger()?->error($e->getMessage());
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    $this->logger()?->success(sprintf('Installed Vale at %s.', $binary));
    $this->io()->writeln('Add this to settings.php, with the path to your .vale.ini:');
    // Beside the web root, the path is given from $app_root, so it holds
    // wherever the site is deployed.
    $project = dirname(DRUPAL_ROOT) . '/';
    $setting = str_starts_with($binary, $project)
      ? sprintf("\$app_root . '/../%s'", substr($binary, strlen($project)))
      : sprintf("'%s'", $binary);
    $this->io()->writeln(sprintf("  \$settings['vale_binary'] = %s;", $setting));
    $this->io()->writeln("  \$settings['vale_config'] = \$app_root . '/../.vale.ini';");
    return CommandResult::exitCode(self::EXIT_SUCCESS);
  }

  /**
   * Shows the Vale installation the site points at.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   The installation, and exit code 1 when Vale cannot be run.
   */
  #[CLI\Command(name: 'vale:status')]
  #[CLI\Usage(name: 'drush vale:status', description: 'Show the binary, version, configuration and rule count.')]
  #[CLI\FieldLabels(labels: [
    'available' => 'Available',
    'binary' => 'Binary',
    'version' => 'Version',
    'config' => 'Configuration',
    'styles' => 'Styles directories',
    'rules' => 'Rules',
    'description' => 'Description',
  ])]
  public function status(array $options = ['format' => 'table']): CommandResult {
    $status = $this->valeEnvironment->status();
    $available = $status['available'];
    return CommandResult::dataWithExitCode(new PropertyList([
      'available' => $available ? 'Yes' : 'No',
      'binary' => (string) $this->valeEnvironment->binary(),
      'version' => (string) $this->valeEnvironment->version(),
      'config' => (string) $this->valeEnvironment->configPath(),
      'styles' => implode(', ', $this->valeEnvironment->stylesPaths()),
      'rules' => $available ? count($this->valeEnvironment->rules()) : 0,
      'description' => $status['description'],
    ]), $available ? self::EXIT_SUCCESS : self::EXIT_FAILURE);
  }

  /**
   * Lists the rules Vale loads, for use in field settings.
   *
   * @return \Consolidation\OutputFormatters\StructuredData\RowsOfFields
   *   The rules.
   */
  #[CLI\Command(name: 'vale:rules')]
  #[CLI\Usage(name: 'drush vale:rules', description: 'List every rule with its level and message.')]
  #[CLI\FieldLabels(labels: [
    'rule' => 'Rule',
    'level' => 'Level',
    'message' => 'Message',
    'description' => 'Description',
    'link' => 'Link',
  ])]
  #[CLI\DefaultTableFields(fields: ['rule', 'level', 'message'])]
  public function rules(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->valeEnvironment->rules() as $rule => $definition) {
      $rows[$rule] = ['rule' => $rule] + $definition;
    }
    return new RowsOfFields($rows);
  }

  /**
   * Lints text as a value of a field, with that field's settings.
   *
   * @param string $field
   *   The field, as entity_type.bundle.field_name.
   * @param string|null $text
   *   The text. Leave it out to read standard input.
   * @param array<string, mixed> $options
   *   The options.
   *
   * @return \Consolidation\AnnotatedCommand\CommandResult
   *   The findings, and exit code 1 on an error-level finding or when the
   *   text could not be checked.
   */
  #[CLI\Command(name: 'vale:check')]
  #[CLI\Argument(name: 'field', description: 'The field, as entity_type.bundle.field_name.')]
  #[CLI\Argument(name: 'text', description: 'The text. Leave it out, or give -, to read standard input.')]
  #[CLI\Option(name: 'text-format', description: 'The text format the text is in, which decides how Vale reads it.')]
  #[CLI\Usage(name: 'drush vale:check node.page.field_text "Some text."', description: "Lint a sentence as a page's text would be linted.")]
  #[CLI\Usage(name: 'drush vale:check paragraph.docs_text.field_text --text-format=docs_markdown < draft.md', description: 'Lint a Markdown file as a docs_text paragraph.')]
  #[CLI\FieldLabels(labels: [
    'line' => 'Line',
    'start' => 'Start',
    'end' => 'End',
    'level' => 'Level',
    'rule' => 'Rule',
    'message' => 'Message',
    'text' => 'Text',
  ])]
  public function check(
    string $field,
    ?string $text = NULL,
    array $options = ['text-format' => self::REQ, 'format' => 'table'],
  ): CommandResult {
    [$entity_type, $bundle, $name] = explode('.', $field, 3) + [1 => '', 2 => ''];
    try {
      $definition = $name === '' ? NULL : ($this->entityFieldManager->getFieldDefinitions($entity_type, $bundle)[$name] ?? NULL);
    }
    catch (PluginNotFoundException) {
      $definition = NULL;
    }
    if (!$definition) {
      $this->logger()?->error(sprintf('There is no field "%s". Name it as entity_type.bundle.field_name.', $field));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }
    if (!$this->fieldLinter->isEnabled($definition)) {
      $this->logger()?->error(sprintf('The field "%s" is not checked by Vale.', $field));
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    if ($text === NULL || $text === '-') {
      $stdin = $this->stdin();
      // Reading a terminal would wait for input with no prompt.
      if (!$stdin->hasPath() && stream_isatty($stdin->getStream())) {
        $this->logger()?->error('Give the text as an argument, or pipe it in.');
        return CommandResult::exitCode(self::EXIT_FAILURE);
      }
      $text = (string) $stdin->contents();
    }
    $format = is_string($options['text-format'] ?? NULL) ? $options['text-format'] : NULL;
    $result = $this->fieldLinter->lintText($definition, $text, $format);
    if (!$result->isChecked()) {
      $this->logger()?->error('The text was not checked. ' . $result->reason);
      return CommandResult::exitCode(self::EXIT_FAILURE);
    }

    $rows = [];
    $failed = FALSE;
    foreach ($result->alerts as $alert) {
      $failed = $failed || $alert->level === Alert::ERROR;
      $rows[] = [
        'line' => $alert->line,
        'start' => $alert->start,
        'end' => $alert->end,
        'level' => $alert->level,
        'rule' => $alert->rule,
        'message' => $alert->message,
        'text' => $alert->match,
      ];
    }
    return CommandResult::dataWithExitCode(new RowsOfFields($rows), $failed ? self::EXIT_FAILURE : self::EXIT_SUCCESS);
  }

  /**
   * Gives the output row for a finding.
   *
   * @param \Drupal\vale\Finding $finding
   *   The finding, which has an alert.
   *
   * @return array<string, string|int>
   *   The row.
   */
  private function row(Finding $finding): array {
    $alert = $finding->alert;
    assert($alert instanceof Alert);
    $host = $finding->host;
    return [
      'host' => $host ? sprintf('%s %s: %s', $host['entity_type'], $host['id'], $host['label']) : '',
      'entity' => sprintf('%s %s (%s)', $finding->entityType, $finding->entityId, $finding->bundle),
      'entity_type' => $finding->entityType,
      'id' => $finding->entityId,
      'bundle' => $finding->bundle,
      'label' => $finding->label,
      'host_type' => $host['entity_type'] ?? '',
      'host_id' => $host['id'] ?? '',
      'host_label' => $host['label'] ?? '',
      'field' => $finding->field,
      'delta' => $finding->delta,
      'line' => $alert->line,
      'start' => $alert->start,
      'end' => $alert->end,
      'level' => $alert->level,
      'rule' => $alert->rule,
      'message' => $alert->message,
      'text' => $alert->match,
    ];
  }

}
