<?php

declare(strict_types=1);

namespace Drupal\vale\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\vale\ContentWalker;
use Drupal\vale\ScanBatch;
use Drupal\vale\ValeEnvironment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scans chosen bundles into the content report.
 */
final class ScanForm extends FormBase {

  /**
   * Constructs a ScanForm.
   *
   * @param \Drupal\vale\ContentWalker $contentWalker
   *   The content walker.
   * @param \Drupal\vale\ValeEnvironment $valeEnvironment
   *   The Vale environment.
   */
  public function __construct(
    protected ContentWalker $contentWalker,
    protected ValeEnvironment $valeEnvironment,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new self(
      $container->get('vale.content_walker'),
      $container->get('vale.environment'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'vale_scan_form';
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   *
   * @return array<string, mixed>
   *   The form.
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $bundles = $this->contentWalker->scannableBundles();
    if ($reason = $this->valeEnvironment->unavailableReason()) {
      $this->messenger()->addWarning($this->t('Vale is not available, so content cannot be checked. @reason', ['@reason' => $reason]));
    }
    $form['bundles'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Content to check'),
      '#options' => $bundles,
      '#default_value' => array_keys($bundles),
      '#description' => $this->t('Each bundle is checked again from the start, replacing its rows in the report.'),
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check content'),
      '#disabled' => $bundles === [] || $reason !== NULL,
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $bundles = array_values(array_filter((array) $form_state->getValue('bundles')));
    if ($bundles === []) {
      return;
    }
    batch_set(ScanBatch::build($bundles));
    $form_state->setRedirectUrl(ScanBatch::reportUrl());
  }

}
