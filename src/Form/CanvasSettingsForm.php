<?php

declare(strict_types=1);

namespace Drupal\vale\Form;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Field\FieldConfigInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vale\Hook\ValeFieldUiHooks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Sets how Vale checks the text in Canvas pages.
 *
 * A Canvas page keeps its components in a base field, and Canvas pages have
 * no Field UI. So the settings are kept on a base field override of that
 * field, the way a configurable field keeps them, and edited here.
 */
final class CanvasSettingsForm extends FormBase {

  /**
   * The entity type of Canvas pages.
   */
  public const string ENTITY_TYPE = 'canvas_page';

  /**
   * The field that holds a page's component tree.
   */
  public const string FIELD = 'components';

  /**
   * Constructs a CanvasSettingsForm.
   *
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\vale\Hook\ValeFieldUiHooks $valeFieldUiHooks
   *   The field settings form builder.
   */
  public function __construct(
    protected EntityFieldManagerInterface $entityFieldManager,
    protected ValeFieldUiHooks $valeFieldUiHooks,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('entity_field.manager'),
      $container->get(ValeFieldUiHooks::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vale_canvas_settings';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $field = $this->override();
    $form['#tree'] = TRUE;
    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Check the text in the components of Canvas pages. A page with an error-level alert is not published, and the review panel links each error to its component.') . '</p>',
    ];
    $form['third_party_settings']['vale'] = ['#type' => 'container'] + $this->valeFieldUiHooks->settingsElement($field);
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save configuration'),
      '#button_type' => 'primary',
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $field = $this->override();
    // The settings element leaves no values when Vale is turned off.
    $values = $form_state->getValue(['third_party_settings', 'vale']);
    $values = is_array($values) ? $values : [];
    foreach (array_keys($field->getThirdPartySettings('vale')) as $key) {
      if (!array_key_exists($key, $values)) {
        $field->unsetThirdPartySetting('vale', $key);
      }
    }
    foreach ($values as $key => $value) {
      $field->setThirdPartySetting('vale', (string) $key, $value);
    }
    // A new override is only worth saving when it turns Vale on.
    if (!$field->isNew() || $values !== []) {
      $field->save();
    }
    $this->messenger()->addStatus($this->t('The configuration options have been saved.'));
  }

  /**
   * Gives the base field override that holds the settings.
   *
   * @return \Drupal\Core\Field\FieldConfigInterface
   *   The stored override, or a new one.
   */
  private function override(): FieldConfigInterface {
    // Canvas always defines this base field on its pages. A page has one
    // bundle, named after its entity type.
    return $this->entityFieldManager->getBaseFieldDefinitions(self::ENTITY_TYPE)[self::FIELD]->getConfig(self::ENTITY_TYPE);
  }

}
