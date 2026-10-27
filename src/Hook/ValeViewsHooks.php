<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\vale\FindingStorage;

/**
 * Describes the content report's table to Views.
 */
class ValeViewsHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_views_data().
   *
   * Drupal 12 no longer loads views.inc files, so this is a hook class.
   *
   * @return array<string, array<string, mixed>>
   *   The Views data for the report table.
   */
  #[Hook('views_data')]
  public function viewsData(): array {
    $string = static fn (\Stringable $title, \Stringable $help): array => [
      'title' => $title,
      'help' => $help,
      'field' => ['id' => 'standard'],
      'filter' => ['id' => 'string'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'string'],
    ];
    $number = static fn (\Stringable $title, \Stringable $help): array => [
      'title' => $title,
      'help' => $help,
      'field' => ['id' => 'numeric'],
      'filter' => ['id' => 'numeric'],
      'sort' => ['id' => 'standard'],
    ];

    $data['vale_finding']['table'] = [
      'group' => $this->t('Prose linting'),
      'base' => [
        'field' => 'fid',
        'title' => $this->t('Prose linting'),
        'help' => $this->t('Vale alerts in stored content, from the last check.'),
        'weight' => 10,
      ],
    ];
    $data['vale_finding']['fid'] = $number($this->t('Alert ID'), $this->t('The row id.'));
    $data['vale_finding']['root_type'] = $string($this->t('Root type'), $this->t('The entity type of the top-level entity.'));
    $data['vale_finding']['root_id'] = $string($this->t('Root ID'), $this->t('The id of the top-level entity.'));
    $data['vale_finding']['root_bundle'] = $string($this->t('Root bundle'), $this->t('The bundle of the top-level entity.'));
    $data['vale_finding']['root_bundle']['filter'] = [
      'id' => 'in_operator',
      'options callback' => FindingStorage::class . '::bundleOptions',
    ];
    $data['vale_finding']['root_label'] = $string($this->t('Root label'), $this->t('The label of the top-level entity.'));
    $data['vale_finding']['root_link'] = [
      'title' => $this->t('Root link'),
      'help' => $this->t('The top-level entity, linked to its edit form.'),
      'real field' => 'root_label',
      'field' => ['id' => 'vale_root_link'],
    ];
    $data['vale_finding']['entity_type'] = $string($this->t('Entity type'), $this->t('The entity type of the entity that holds the value.'));
    $data['vale_finding']['entity_id'] = $string($this->t('Entity ID'), $this->t('The id of the entity that holds the value.'));
    $data['vale_finding']['bundle'] = $string($this->t('Bundle'), $this->t('The bundle of the entity that holds the value.'));
    $data['vale_finding']['label'] = $string($this->t('Label'), $this->t('The label of the entity that holds the value.'));
    $data['vale_finding']['langcode'] = [
      'title' => $this->t('Language'),
      'help' => $this->t('The language of the translation that holds the value.'),
      'field' => ['id' => 'language'],
      'filter' => ['id' => 'language'],
      'sort' => ['id' => 'standard'],
      'argument' => ['id' => 'language'],
    ];
    $data['vale_finding']['field'] = $string($this->t('Field'), $this->t('The field name.'));
    $data['vale_finding']['delta'] = $number($this->t('Delta'), $this->t('The delta of the value.'));
    $data['vale_finding']['rule'] = $string($this->t('Rule'), $this->t('The Vale rule.'));
    $data['vale_finding']['level'] = $string($this->t('Level'), $this->t('error, warning, suggestion, or unchecked.'));
    $data['vale_finding']['level']['filter'] = [
      'id' => 'in_operator',
      'options callback' => FindingStorage::class . '::levelOptions',
    ];
    $data['vale_finding']['message'] = $string($this->t('Message'), $this->t("The rule's message."));
    $data['vale_finding']['matched'] = $string($this->t('Text'), $this->t('The flagged text.'));
    $data['vale_finding']['line'] = $number($this->t('Line'), $this->t('The line of the match.'));
    $data['vale_finding']['span_start'] = $number($this->t('Start'), $this->t('The first column of the match.'));
    $data['vale_finding']['span_end'] = $number($this->t('End'), $this->t('The last column of the match.'));
    $data['vale_finding']['scanned'] = [
      'title' => $this->t('Scanned'),
      'help' => $this->t('When the alert was recorded.'),
      'field' => ['id' => 'date'],
      'filter' => ['id' => 'date'],
      'sort' => ['id' => 'date'],
    ];
    $data['vale_finding']['vale_export'] = [
      'title' => $this->t('Export link'),
      'help' => $this->t('A link to export the filtered alerts as CSV.'),
      'area' => ['id' => 'vale_export'],
    ];
    return $data;
  }

}
