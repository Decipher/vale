<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\WidgetInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vale\FieldSettings;
use Drupal\vale\ValeBrowser;

/**
 * Shows findings while an author edits a field Vale checks.
 *
 * Each widget of a checked field gets a "Show findings while editing"
 * setting on the form display. When it is on, the widget's value element
 * tells the browser what it holds, and the vale/findings library lists the
 * findings under it. A text editor's own plugin marks them instead.
 */
class ValeWidgetHooks {

  use StringTranslationTrait;

  /**
   * The third-party setting that shows findings while editing.
   */
  public const string SETTING = 'show_findings';

  /**
   * Constructs a ValeWidgetHooks.
   *
   * @param \Drupal\vale\ValeBrowser $browser
   *   What the vale/browser library needs.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   */
  public function __construct(
    protected ValeBrowser $browser,
    protected AccountInterface $currentUser,
  ) {}

  /**
   * Tells whether Vale checks a field's values while they are edited.
   *
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field
   *   The field.
   *
   * @return bool
   *   TRUE for a text or string field that Vale checks.
   */
  protected function applies(FieldDefinitionInterface $field): bool {
    return in_array($field->getType(), FieldSettings::FIELD_TYPES, TRUE) && FieldSettings::forField($field)->enabled;
  }

  /**
   * Tells whether a widget shows findings while editing.
   *
   * @param \Drupal\Core\Field\WidgetInterface $widget
   *   The widget.
   *
   * @return bool
   *   TRUE unless its form display turns it off.
   */
  protected function shows(WidgetInterface $widget): bool {
    return (bool) $widget->getThirdPartySetting('vale', self::SETTING, TRUE);
  }

  /**
   * Implements hook_field_widget_third_party_settings_form().
   *
   * @param \Drupal\Core\Field\WidgetInterface $plugin
   *   The widget.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field.
   * @param string $form_mode
   *   The form mode.
   * @param array<string, mixed> $form
   *   The form display form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The setting's element, or nothing for a field Vale does not check.
   */
  #[Hook('field_widget_third_party_settings_form')]
  public function settingsForm(WidgetInterface $plugin, FieldDefinitionInterface $field_definition, string $form_mode, array $form, FormStateInterface $form_state): array {
    if (!$this->applies($field_definition)) {
      return [];
    }
    return [
      self::SETTING => [
        '#type' => 'checkbox',
        '#title' => $this->t('Show findings while editing'),
        '#description' => $this->t('Vale checks the text while the author types. The save still checks it.'),
        '#default_value' => $this->shows($plugin),
      ],
    ];
  }

  /**
   * Implements hook_field_widget_settings_summary_alter().
   *
   * @param array<int, mixed> $summary
   *   The summary lines.
   * @param array<string, mixed> $context
   *   The widget, field_definition and form_mode.
   */
  #[Hook('field_widget_settings_summary_alter')]
  public function settingsSummaryAlter(array &$summary, array $context): void {
    $widget = $context['widget'] ?? NULL;
    $field = $context['field_definition'] ?? NULL;
    if (!$widget instanceof WidgetInterface || !$field instanceof FieldDefinitionInterface || !$this->applies($field)) {
      return;
    }
    $summary[] = $this->shows($widget) ? $this->t('Findings shown while editing') : $this->t('Findings hidden while editing');
  }

  /**
   * Implements hook_field_widget_single_element_form_alter().
   *
   * The value element gets a data-vale-findings attribute that says which
   * field, entity and language it holds, so the browser lints it as the save
   * would. The summary of a text_with_summary field is left out.
   *
   * @param array<string, mixed> $element
   *   The widget's element for one value.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param array<string, mixed> $context
   *   The widget, items and delta, among others.
   */
  #[Hook('field_widget_single_element_form_alter')]
  public function singleElementFormAlter(array &$element, FormStateInterface $form_state, array $context): void {
    $widget = $context['widget'] ?? NULL;
    $items = $context['items'] ?? NULL;
    if (!$widget instanceof WidgetInterface || !$items instanceof FieldItemListInterface) {
      return;
    }
    $field = $items->getFieldDefinition();
    if (!$this->applies($field) || !$this->shows($widget)) {
      return;
    }
    // The browser lints with the check endpoint's permission.
    $cache = CacheableMetadata::createFromRenderArray($element)->addCacheContexts(['user.permissions']);
    $element['#cache'] = [
      'contexts' => $cache->getCacheContexts(),
      'tags' => $cache->getCacheTags(),
      'max-age' => $cache->getCacheMaxAge(),
    ];
    if (!$this->currentUser->hasPermission('use vale check')) {
      return;
    }

    $entity = $items->getEntity();
    $data = [
      'entityType' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'field' => $field->getName(),
      'langcode' => $entity->language()->getId(),
      'id' => $entity->uuid(),
      'entityId' => $entity->isNew() ? NULL : $entity->id(),
      'delta' => (int) ($context['delta'] ?? 0),
    ];
    $attribute = json_encode($data, JSON_THROW_ON_ERROR);
    // A text_format element moves its attributes onto its value element.
    if (($element['#type'] ?? NULL) === 'text_format') {
      $element['#attributes'] = $this->withAttribute($element['#attributes'] ?? [], $attribute);
    }
    elseif (isset($element['value']) && is_array($element['value'])) {
      $value = $element['value'];
      $value['#attributes'] = $this->withAttribute($value['#attributes'] ?? [], $attribute);
      $element['value'] = $value;
    }
    else {
      return;
    }
    $attachments = $this->browser->attachments($field);
    $attachments['library'][] = 'vale/findings';
    $attached = is_array($element['#attached'] ?? NULL) ? $element['#attached'] : [];
    $element['#attached'] = BubbleableMetadata::mergeAttachments($attached, $attachments);
  }

  /**
   * Adds the data-vale-findings attribute to an element's attributes.
   *
   * @param mixed $attributes
   *   The element's #attributes.
   * @param string $value
   *   The attribute's value.
   *
   * @return array<mixed>
   *   The attributes.
   */
  protected function withAttribute(mixed $attributes, string $value): array {
    $attributes = is_array($attributes) ? $attributes : [];
    $attributes['data-vale-findings'] = $value;
    return $attributes;
  }

}
