<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\field\FieldConfigInterface;

/**
 * The Vale settings of one field.
 *
 * They are kept as third-party settings on the field's FieldConfig. They can
 * only narrow what Vale reports: drop rules, change a rule's level, or raise
 * the minimum level. A rule the codebase's configuration does not load can
 * never be added, so the settings are safe for anyone who can edit fields.
 */
final readonly class FieldSettings {

  /**
   * The field types that hold prose.
   */
  public const array FIELD_TYPES = ['text', 'text_long', 'text_with_summary', 'string', 'string_long'];

  /**
   * The form value for a rule that is turned off.
   */
  public const string OFF = 'off';

  /**
   * Constructs a FieldSettings.
   *
   * @param bool $enabled
   *   Whether the field is linted.
   * @param string $minLevel
   *   The lowest level that is reported.
   * @param string[] $disabledRules
   *   The rules that are not reported.
   * @param array<string, string> $levelOverrides
   *   New levels, keyed by rule.
   * @param string $profile
   *   The name the field's values are linted under, or an empty string for
   *   "<entity_type>.<bundle>.<field>".
   */
  public function __construct(
    public bool $enabled = FALSE,
    public string $minLevel = Alert::SUGGESTION,
    public array $disabledRules = [],
    public array $levelOverrides = [],
    public string $profile = '',
  ) {}

  /**
   * Gives the settings of a field.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return self
   *   Its settings. A field that cannot hold prose, or is not a configurable
   *   field, is not enabled.
   */
  public static function forField(FieldDefinitionInterface $field): self {
    if (!in_array($field->getType(), self::FIELD_TYPES, TRUE) || !$field instanceof FieldConfigInterface) {
      return new self();
    }
    return self::fromArray($field->getThirdPartySettings('vale'));
  }

  /**
   * Makes settings from stored or submitted values.
   *
   * @param array<mixed> $values
   *   The values.
   *
   * @return self
   *   The settings. Values of the wrong type are left at their defaults.
   */
  public static function fromArray(array $values): self {
    $overrides = [];
    // Rule names hold dots, which configuration keys cannot, so the changes
    // are stored as a list of rule and level pairs.
    foreach (is_array($values['level_overrides'] ?? NULL) ? $values['level_overrides'] : [] as $override) {
      $rule = is_array($override) ? ($override['rule'] ?? NULL) : NULL;
      $level = is_array($override) ? ($override['level'] ?? NULL) : NULL;
      if (is_string($rule) && is_string($level) && Alert::weight($level) >= 0) {
        $overrides[$rule] = $level;
      }
    }
    return new self(
      !empty($values['enabled']),
      self::normalizeLevel($values['min_level'] ?? NULL),
      array_values(array_filter(is_array($values['disabled_rules'] ?? NULL) ? $values['disabled_rules'] : [], is_string(...))),
      $overrides,
      is_string($values['profile'] ?? NULL) ? $values['profile'] : '',
    );
  }

  /**
   * Gives a valid minimum level.
   *
   * @param mixed $level
   *   A stored or submitted level.
   *
   * @return string
   *   The level, or "suggestion" when it is not a level.
   */
  public static function normalizeLevel(mixed $level): string {
    return is_string($level) && Alert::weight($level) >= 0 ? $level : Alert::SUGGESTION;
  }

  /**
   * Gives the settings as stored values.
   *
   * @return array{enabled: bool, min_level: string, disabled_rules: string[], level_overrides: list<array{rule: string, level: string}>, profile: string}
   *   The values.
   */
  public function toArray(): array {
    $overrides = [];
    foreach ($this->levelOverrides as $rule => $level) {
      $overrides[] = ['rule' => $rule, 'level' => $level];
    }
    return [
      'enabled' => $this->enabled,
      'min_level' => $this->minLevel,
      'disabled_rules' => $this->disabledRules,
      'level_overrides' => $overrides,
      'profile' => $this->profile,
    ];
  }

  /**
   * Gives the name a field's values are linted under.
   *
   * Vale matches .vale.ini sections against "<name>.<extension>", so a
   * section such as [*.field_callout.md] or [docs_callout.md] applies to one
   * field only.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return string
   *   The profile, or "<entity_type>.<bundle>.<field>".
   */
  public function name(FieldDefinitionInterface $field): string {
    if ($this->profile !== '') {
      return $this->profile;
    }
    $entity_type = $field->getTargetEntityTypeId();
    return implode('.', [$entity_type, $field->getTargetBundle() ?? $entity_type, $field->getName()]);
  }

  /**
   * Applies the settings to the alerts Vale reported.
   *
   * Disabled rules are dropped first, then levels are changed, then alerts
   * below the minimum level are dropped.
   *
   * @param \Drupal\vale\Alert[] $alerts
   *   The alerts.
   *
   * @return \Drupal\vale\Alert[]
   *   The alerts that are reported.
   */
  public function filter(array $alerts): array {
    $minimum = Alert::weight($this->minLevel);
    $kept = [];
    foreach ($alerts as $alert) {
      if (in_array($alert->rule, $this->disabledRules, TRUE)) {
        continue;
      }
      if (isset($this->levelOverrides[$alert->rule])) {
        $alert = $alert->withLevel($this->levelOverrides[$alert->rule]);
      }
      if (Alert::weight($alert->level) >= $minimum) {
        $kept[] = $alert;
      }
    }
    return $kept;
  }

  /**
   * Lists the rules the settings name that Vale does not load.
   *
   * @param array<string, mixed> $catalogue
   *   The rules Vale loads, keyed by name.
   *
   * @return string[]
   *   The unknown rule names.
   */
  public function unknownRules(array $catalogue): array {
    $named = array_unique([...$this->disabledRules, ...array_keys($this->levelOverrides)]);
    return array_values(array_filter($named, static fn (string $rule): bool => !isset($catalogue[$rule])));
  }

}
