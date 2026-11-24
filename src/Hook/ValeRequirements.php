<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\vale\ValeEnvironment;

/**
 * Reports the Vale installation on the site status report.
 */
class ValeRequirements {

  use StringTranslationTrait;

  /**
   * Constructs a ValeRequirements.
   *
   * @param \Drupal\vale\ValeEnvironment $environment
   *   The Vale environment.
   */
  public function __construct(
    protected ValeEnvironment $environment,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   *
   * @return array<string, array<string, mixed>>
   *   The requirement.
   */
  #[Hook('runtime_requirements')]
  public function runtime(): array {
    $requirement = $this->requirement();
    $requirement['severity'] = $requirement['severity'] ? RequirementSeverity::OK : RequirementSeverity::Warning;
    $wasm = $this->wasmRequirement();
    $wasm['severity'] = RequirementSeverity::from($wasm['severity']);
    $languages = $this->languageRequirement();
    $languages['severity'] = RequirementSeverity::from($languages['severity']);
    return ['vale' => $requirement, 'vale_wasm' => $wasm, 'vale_languages' => $languages];
  }

  /**
   * Builds the requirement without its severity.
   *
   * Drupal 11.2 replaced the severity constants with an enum. The OOP hook
   * gives the enum and the legacy hook gives the integer, so this only says
   * whether Vale is available.
   *
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string, description: string|array<string, mixed>, severity: bool}
   *   The requirement, with severity TRUE when Vale is available. When the
   *   styles hold rules, the description is a render array that varies by
   *   the user's permissions, and links to the styles for a user who may
   *   download them.
   */
  public function requirement(): array {
    $status = $this->environment->status();
    $description = $status['description'];
    // Only styles with rules are worth a download.
    if ($status['available'] && $this->environment->rules() !== []) {
      $download = Url::fromRoute('vale.browser_styles');
      $access = $download->access(NULL, TRUE);
      $build = ['#markup' => $description];
      if ($access->isAllowed()) {
        $build['#markup'] = $this->t('@description <a href=":url">Download the styles</a> as a zip, for the Vale browser extension.', [
          '@description' => $description,
          ':url' => $download->toString(),
        ]);
      }
      // The link depends on the user's permissions, so the status report
      // says so to the render cache.
      CacheableMetadata::createFromObject($access)->applyTo($build);
      $description = $build;
    }
    return [
      'title' => $this->t('Vale'),
      'value' => $status['value'],
      'description' => $description,
      'severity' => $status['available'],
    ];
  }

  /**
   * Builds the requirement for the site's languages.
   *
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string, description: string, severity: int}
   *   The requirement. Its severity is 0 for OK and 1 for a warning, which
   *   are the values of both the enum and the older constants.
   */
  public function languageRequirement(): array {
    $status = $this->environment->languageStatus();
    return [
      'title' => $this->t('Vale languages'),
      'value' => $status['value'],
      'description' => $status['description'],
      'severity' => $status['severity'],
    ];
  }

  /**
   * Builds the requirement for Vale's browser build.
   *
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string, description: string, severity: int}
   *   The requirement. Its severity is -1 for information, 0 for OK and 1
   *   for a warning, which are the values of both the enum and the older
   *   constants.
   */
  public function wasmRequirement(): array {
    $status = $this->environment->wasmStatus();
    return [
      'title' => $this->t('Vale browser build'),
      'value' => $status['value'],
      'description' => $status['description'],
      'severity' => $status['severity'],
    ];
  }

}
