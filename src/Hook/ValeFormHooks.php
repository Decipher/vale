<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vale\Alert;
use Drupal\vale\AlertCollector;

/**
 * Shows authors the findings that did not stop their save.
 */
class ValeFormHooks {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * Constructs a ValeFormHooks.
   *
   * @param \Drupal\vale\AlertCollector $collector
   *   The alert collector.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   */
  public function __construct(
    protected AlertCollector $collector,
    protected MessengerInterface $messenger,
  ) {}

  /**
   * Implements hook_form_alter().
   *
   * Entity forms and the Layout Paragraphs component form validate their
   * entity in their own validateForm(). This handler runs after it.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   * @param string $form_id
   *   The form id.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if ($form_state->getFormObject() instanceof ContentEntityFormInterface || $form_id === 'layout_paragraphs_component_form') {
      $form['#validate'][] = [$this, 'showAlerts'];
    }
  }

  /**
   * Shows the collected findings as messages.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function showAlerts(array &$form, FormStateInterface $form_state): void {
    foreach ($this->collector->alerts() as $finding) {
      $alert = $finding['alert'];
      $args = [
        '@label' => $finding['label'],
        '@field' => $finding['field_label'],
        '@rule' => $alert->rule,
        '@message' => $alert->message,
        '@line' => $alert->line,
        '@match' => $alert->match,
      ];
      if ($alert->level === Alert::ERROR) {
        $this->messenger->addWarning($this->t('@label, @field: @rule: @message (line @line: "@match"). Blocks publishing.', $args));
      }
      else {
        $this->messenger->addWarning($this->t('@label, @field: @rule: @message (line @line: "@match")', $args));
      }
    }
    foreach ($this->collector->unchecked() as $reason) {
      $this->messenger->addWarning($this->t('Prose not checked: @reason', ['@reason' => $reason]));
    }
    $this->collector->reset();
  }

}
