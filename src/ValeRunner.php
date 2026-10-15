<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Lints text by piping it to the Vale binary.
 *
 * The text goes to Vale on standard input, with --path naming it. The name
 * decides both how Vale reads the text and which .vale.ini sections apply.
 * No file is written.
 */
class ValeRunner implements ValeRunnerInterface {

  use StringTranslationTrait;

  /**
   * How long a cached result is kept, in seconds.
   *
   * A changed configuration already misses the cache, so this only stops
   * results for text that no longer exists from staying for ever.
   */
  protected const RESULT_LIFETIME = 604800;

  /**
   * Constructs a ValeRunner.
   *
   * @param \Drupal\vale\ValeEnvironment $environment
   *   The Vale environment.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The vale cache bin.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Psr\Log\LoggerInterface $logger
   *   The vale logger channel.
   */
  public function __construct(
    protected ValeEnvironment $environment,
    protected CacheBackendInterface $cache,
    protected ConfigFactoryInterface $configFactory,
    protected TimeInterface $time,
    protected LoggerInterface $logger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function lint(string $text, string $extension = 'html', string $name = 'text', bool $store = TRUE): LintResult {
    $reason = $this->environment->unavailableReason();
    if ($reason !== NULL) {
      return LintResult::unavailable($reason);
    }
    if (trim($text) === '') {
      return LintResult::checked([]);
    }

    $extension = $this->cleanExtension($extension);
    $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $name) ?: 'text';
    $path = $name . '.' . $extension;

    $cid = 'lint:' . hash('sha256', $this->environment->fingerprint() . "\0" . $path . "\0" . $text);
    if ($cached = $this->cache->get($cid)) {
      return LintResult::checked(array_map(Alert::fromArray(...), $cached->data));
    }

    // Vale lints only the block content of an HTML document, so a fragment
    // such as "Some <em>text</em>" or untagged text would pass unread. The
    // wrapper starts on a line of its own, so columns are unchanged and every
    // line moves down by one.
    $html = in_array($extension, ['html', 'htm'], TRUE);
    $input = $html ? "<html><body>\n" . $text . "\n</body></html>\n" : $text;

    try {
      $process = $this->environment->process([
        '--output=JSON',
        '--minAlertLevel=suggestion',
        '--path=' . $path,
      ], $input);
      $process->run();
    }
    catch (ProcessTimedOutException) {
      $seconds = $this->environment->timeout();
      return $this->failed(
        'Vale ran out of time after @seconds seconds.',
        ['@seconds' => $seconds],
        (string) $this->formatPlural($seconds, 'Vale ran out of time after 1 second.', 'Vale ran out of time after @count seconds.'),
      );
    }
    catch (ProcessException $e) {
      return $this->failed('Vale could not be run: @message', ['@message' => $e->getMessage()], $this->t('Vale could not be run:') . ' ' . $e->getMessage());
    }

    $output = json_decode($process->getOutput(), TRUE);

    // Vale exits with 0 when there are no errors and 1 when there are. Any
    // other code is a failure, which Vale describes in a JSON object of its
    // own.
    if (!in_array($process->getExitCode(), [0, 1], TRUE)) {
      $detail = is_array($output) && is_string($output['Text'] ?? NULL) ? $output['Text'] : trim($process->getErrorOutput());
      $code = (string) $process->getExitCode();
      return $this->failed(
        'Vale failed with exit code @code: @detail',
        ['@code' => $code, '@detail' => $detail],
        $this->t('Vale failed with exit code @code:', ['@code' => $code]) . ' ' . $detail,
      );
    }

    $alerts = $this->readAlerts($output);
    if ($alerts === NULL) {
      return $this->failed('Vale gave output that could not be read.', [], (string) $this->t('Vale gave output that could not be read.'));
    }
    if ($html) {
      $alerts = $this->unwrap($alerts, count(preg_split('/\R/', $text) ?: []));
    }

    if ($store) {
      $this->cache->set($cid, array_map(static fn (Alert $alert) => $alert->toArray(), $alerts), $this->time->getRequestTime() + static::RESULT_LIFETIME);
    }
    return LintResult::checked($alerts);
  }

  /**
   * {@inheritdoc}
   */
  public function extensionForFormat(?string $format): string {
    $settings = $this->configFactory->get('vale.settings');
    $formats = $settings->get('formats');
    if ($format !== NULL && is_array($formats) && is_string($formats[$format] ?? NULL)) {
      return $this->cleanExtension($formats[$format]);
    }
    return $this->cleanExtension((string) $settings->get('default_extension'));
  }

  /**
   * Reads the alerts from Vale's decoded JSON output.
   *
   * @param mixed $output
   *   The decoded output: an object keyed by path, each holding a list.
   *
   * @return \Drupal\vale\Alert[]|null
   *   The alerts, or NULL when the output does not have Vale's shape.
   */
  protected function readAlerts(mixed $output): ?array {
    if (!is_array($output)) {
      return NULL;
    }
    $alerts = [];
    foreach ($output as $items) {
      if (!is_array($items)) {
        return NULL;
      }
      foreach ($items as $item) {
        $alert = is_array($item) ? Alert::fromVale($item) : NULL;
        if (!$alert instanceof Alert) {
          return NULL;
        }
        $alerts[] = $alert;
      }
    }
    return $alerts;
  }

  /**
   * Moves alerts from the wrapped HTML back onto the text's own lines.
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts, with lines counted in the wrapped document.
   * @param int $lines
   *   How many lines the text has.
   *
   * @return \Drupal\vale\Alert[]
   *   The alerts, with lines counted in the text. An alert on the wrapper's
   *   opening or closing line is dropped.
   */
  protected function unwrap(array $alerts, int $lines): array {
    $unwrapped = [];
    foreach ($alerts as $alert) {
      if ($alert->line > 1 && $alert->line <= $lines + 1) {
        $unwrapped[] = new Alert($alert->rule, $alert->level, $alert->message, $alert->line - 1, $alert->start, $alert->end, $alert->match, $alert->description, $alert->link);
      }
    }
    return $unwrapped;
  }

  /**
   * Gives a safe file extension.
   *
   * @param string $extension
   *   An extension, with or without its dot.
   *
   * @return string
   *   The extension in lower case, or "html" when it is not plain letters
   *   and digits.
   */
  protected function cleanExtension(string $extension): string {
    $extension = strtolower(ltrim($extension, '.'));
    return preg_match('/^[a-z0-9]{1,10}$/', $extension) ? $extension : 'html';
  }

  /**
   * Logs a failed run and gives its result.
   *
   * @param string $message
   *   The message, with placeholders.
   * @param array<string, string|int> $context
   *   The placeholder values.
   * @param string $reason
   *   The reason to show, translated, with Vale's own output as plain text.
   *
   * @return \Drupal\vale\LintResult
   *   The failed result.
   */
  protected function failed(string $message, array $context, string $reason): LintResult {
    $this->logger->warning($message, $context);
    return LintResult::failed($reason);
  }

}
