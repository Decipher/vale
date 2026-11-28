<?php

declare(strict_types=1);

namespace Drupal\vale\Command;

use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\vale\Alert;
use Drupal\vale\FieldLinter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Lints text as a value of a field, with that field's settings.
 */
#[AsCommand(name: 'vale:check', description: "Lints text as a value of a field, with that field's settings.")]
final class CheckCommand extends Command {

  use OutputTrait;

  /**
   * The label of each field.
   */
  private const array LABELS = [
    'line' => 'Line',
    'start' => 'Start',
    'end' => 'End',
    'level' => 'Level',
    'rule' => 'Rule',
    'message' => 'Message',
    'text' => 'Text',
  ];

  /**
   * Constructs a CheckCommand.
   *
   * @param \Drupal\vale\FieldLinter $fieldLinter
   *   The field linter.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   */
  public function __construct(
    private readonly FieldLinter $fieldLinter,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this
      ->setHelp('Exits with 1 on an error-level finding or when the text could not be checked.')
      ->addArgument('field', InputArgument::REQUIRED, 'The field, as entity_type.bundle.field_name.')
      ->addArgument('text', InputArgument::OPTIONAL, 'The text. Leave it out, or give -, to read standard input.')
      ->addOption('text-format', NULL, InputOption::VALUE_REQUIRED, 'The text format the text is in, which decides how Vale reads it.')
      ->addOutputOptions()
      ->addUsage('node.page.field_text "Some text."')
      ->addUsage('paragraph.docs_text.field_text --text-format=docs_markdown < draft.md');
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    try {
      $fields = $this->outputFields($input, self::LABELS);
    }
    catch (\InvalidArgumentException $e) {
      $this->writeError($input, $output, $e->getMessage());
      return self::FAILURE;
    }

    $field = (string) $input->getArgument('field');
    [$entity_type, $bundle, $name] = explode('.', $field, 3) + [1 => '', 2 => ''];
    try {
      $definition = $name === '' ? NULL : ($this->entityFieldManager->getFieldDefinitions($entity_type, $bundle)[$name] ?? NULL);
    }
    catch (PluginNotFoundException) {
      $definition = NULL;
    }
    if (!$definition) {
      $this->writeError($input, $output, sprintf('There is no field "%s". Name it as entity_type.bundle.field_name.', $field));
      return self::FAILURE;
    }
    if (!$this->fieldLinter->isEnabled($definition)) {
      $this->writeError($input, $output, sprintf('The field "%s" is not checked by Vale.', $field));
      return self::FAILURE;
    }

    $text = $input->getArgument('text');
    if (!is_string($text) || $text === '-') {
      $stream = ($input instanceof StreamableInputInterface ? $input->getStream() : NULL) ?? STDIN;
      // Reading a terminal would wait for input with no prompt.
      if (stream_isatty($stream)) {
        $this->writeError($input, $output, 'Give the text as an argument, or pipe it in.');
        return self::FAILURE;
      }
      $text = (string) stream_get_contents($stream);
    }
    $format = $input->getOption('text-format');
    $result = $this->fieldLinter->lintText($definition, $text, is_string($format) ? $format : NULL);
    if (!$result->isChecked()) {
      $this->writeError($input, $output, 'The text was not checked. ' . $result->reason);
      return self::FAILURE;
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
    $this->writeRows($input, $output, $rows, self::LABELS, $fields);
    return $failed ? self::FAILURE : self::SUCCESS;
  }

}
