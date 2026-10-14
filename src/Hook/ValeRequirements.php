<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
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
    return ['vale' => $requirement];
  }

  /**
   * Builds the requirement without its severity.
   *
   * Drupal 11.2 replaced the severity constants with an enum. The OOP hook
   * gives the enum and the legacy hook gives the integer, so this only says
   * whether Vale is available.
   *
   * @return array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, value: string, description: string, severity: bool}
   *   The requirement, with severity TRUE when Vale is available.
   */
  public function requirement(): array {
    $status = $this->environment->status();
    return [
      'title' => $this->t('Vale'),
      'value' => $status['value'],
      'description' => $status['description'],
      'severity' => $status['available'],
    ];
  }

}
