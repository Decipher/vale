<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Entity\EntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\field\FieldConfigInterface;
use Drupal\vale\Alert;
use Drupal\vale\FieldSettings;
use Drupal\vale\ValeEnvironment;

/**
 * Adds the Vale settings to the field edit form.
 */
class ValeFieldUiHooks {

  use StringTranslationTrait;

  /**
   * Constructs a ValeFieldUiHooks.
   *
   * @param \Drupal\vale\ValeEnvironment $environment
   *   The Vale environment.
   */
  public function __construct(
    protected ValeEnvironment $environment,
  ) {}

  /**
   * Implements hook_form_FORM_ID_alter() for field_config_edit_form.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  #[Hook('form_field_config_edit_form_alter')]
  public function fieldConfigEditFormAlter(array &$form, FormStateInterface $form_state): void {
    $form_object = $form_state->getFormObject();
    $field = $form_object instanceof EntityFormInterface ? $form_object->getEntity() : NULL;
    if (!$field instanceof FieldConfigInterface || !in_array($field->getType(), FieldSettings::FIELD_TYPES, TRUE)) {
      return;
    }

    $settings = FieldSettings::forField($field);
    $rules = $this->environment->rules();
    $levels = array_combine(Alert::LEVELS, [$this->t('Suggestion'), $this->t('Warning'), $this->t('Error')]);
    $states = ['visible' => [':input[name="third_party_settings[vale][enabled]"]' => ['checked' => TRUE]]];

    $element = [
      '#type' => 'details',
      '#title' => $this->t('Prose checks'),
      '#open' => $settings->enabled,
      '#element_validate' => [[static::class, 'validateSettings']],
      '#vale_rules' => array_keys($rules),
      '#vale_stored' => $settings->toArray(),
    ];
    $element['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check with Vale'),
      '#default_value' => $settings->enabled,
    ];
    if ($reason = $this->environment->unavailableReason()) {
      $element['unavailable'] = [
        '#type' => 'item',
        '#markup' => $this->t('Vale is not available. @reason', ['@reason' => $reason]),
      ];
    }
    $element['min_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum level'),
      '#options' => $levels,
      '#default_value' => $settings->minLevel,
      '#states' => $states,
    ];
    $element['profile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Profile'),
      '#default_value' => $settings->profile,
      '#description' => $this->t('Lint as this name instead of <code>@name</code>. Sections in .vale.ini match on it.', [
        '@name' => (new FieldSettings())->name($field),
      ]),
      '#states' => $states,
    ];
    $element['rules'] = $this->rulesElement($rules, $settings, $levels) + ['#states' => $states];

    $form['third_party_settings']['vale'] = $element;
  }

  /**
   * Builds a table per style, with one level select per rule.
   *
   * This follows Field UI's Manage display, where each row has its own
   * select. A style's details open when any of its rules is changed.
   *
   * @param array<string, array{level: string, message: string, description: string, link: string}> $rules
   *   The rules Vale loads, keyed by name.
   * @param \Drupal\vale\FieldSettings $settings
   *   The field's settings.
   * @param array<string, \Drupal\Core\StringTranslation\TranslatableMarkup> $levels
   *   The level labels, keyed by level.
   *
   * @return array<string, mixed>
   *   The element.
   */
  protected function rulesElement(array $rules, FieldSettings $settings, array $levels): array {
    $element = ['#type' => 'container'];
    if ($rules === []) {
      $element['none'] = [
        '#type' => 'item',
        '#title' => $this->t('Rules'),
        '#markup' => $this->t('No rules are loaded, so the rule settings are kept as they are.'),
      ];
      return $element;
    }

    $options = ['' => $this->t('Default')] + array_reverse($levels, TRUE) + [FieldSettings::OFF => $this->t('Off')];
    $styles = [];
    foreach ($rules as $name => $definition) {
      [$style, $short] = explode('.', $name, 2);
      $value = in_array($name, $settings->disabledRules, TRUE) ? FieldSettings::OFF : ($settings->levelOverrides[$name] ?? '');
      $styles[$style][$short] = [
        'rule' => [
          '#type' => 'inline_template',
          '#template' => '{{ name }}{% if description %}<div class="description">{{ description }}</div>{% endif %}',
          // A rule with no description shows its message, with the
          // placeholders for the matched text shown as an ellipsis.
          '#context' => [
            'name' => $short,
            'description' => $definition['description'] ?: str_replace('%s', '…', $definition['message']),
          ],
        ],
        'default' => ['#plain_text' => (string) ($levels[$definition['level']] ?? $definition['level'])],
        'level' => [
          '#type' => 'select',
          '#title' => $this->t('Level of @rule in this field', ['@rule' => $name]),
          '#title_display' => 'invisible',
          '#options' => $options,
          '#default_value' => $value,
        ],
        '#vale_changed' => $value !== '',
      ];
    }

    foreach ($styles as $style => $rows) {
      $changed = count(array_filter($rows, static fn (array $row): bool => $row['#vale_changed']));
      $title = $this->formatPlural(count($rows), '@style: 1 rule', '@style: @count rules', ['@style' => $style]);
      if ($changed) {
        $title = $this->t('@rules, @changed changed', ['@rules' => $title, '@changed' => $changed]);
      }
      $element[$style] = [
        '#type' => 'details',
        '#title' => $title,
        '#open' => $changed > 0,
        'table' => [
          '#type' => 'table',
          '#header' => [$this->t('Rule'), $this->t('Rule level'), $this->t('This field')],
        ] + $rows,
      ];
    }

    $stale = $settings->unknownRules($rules);
    if ($stale) {
      $element['stale'] = [
        '#type' => 'item',
        '#title' => $this->t('No longer loaded'),
        '#markup' => $this->t('Vale no longer loads these rules, so saving removes them: @rules.', [
          '@rules' => implode(', ', $stale),
        ]),
      ];
    }
    return $element;
  }

  /**
   * Validates the settings and turns them into stored values.
   *
   * A field that is not checked keeps no settings at all.
   *
   * @param array<mixed> $element
   *   The settings element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateSettings(array &$element, FormStateInterface $form_state): void {
    $values = $form_state->getValue($element['#parents']);
    if (!is_array($values) || empty($values['enabled'])) {
      $form_state->unsetValue($element['#parents']);
      return;
    }

    $profile = trim((string) ($values['profile'] ?? ''));
    if (!preg_match('/^[A-Za-z0-9_.-]*$/', $profile)) {
      $form_state->setError($element['profile'], t('Use only letters, digits, dots, hyphens and underscores.'));
    }

    // Each loaded rule has one select. With no rules loaded there are no
    // selects, and the stored choices are kept.
    $disabled = [];
    $overrides = [];
    if ($element['#vale_rules'] === []) {
      $stored = FieldSettings::fromArray($element['#vale_stored']);
      $disabled = $stored->disabledRules;
      $overrides = $stored->levelOverrides;
    }
    foreach ($element['#vale_rules'] as $name) {
      [$style, $short] = explode('.', $name, 2);
      $level = $values['rules'][$style]['table'][$short]['level'] ?? '';
      if ($level === FieldSettings::OFF) {
        $disabled[] = $name;
      }
      elseif (is_string($level) && Alert::weight($level) >= 0) {
        $overrides[$name] = $level;
      }
    }

    $settings = new FieldSettings(
      TRUE,
      FieldSettings::normalizeLevel($values['min_level'] ?? NULL),
      $disabled,
      $overrides,
      $profile,
    );

    $form_state->setValueForElement($element, $settings->toArray());
  }

}
