<?php

/**
 * @file
 * Views integration for the Vale content report.
 */

declare(strict_types=1);

use Drupal\vale\FindingStorage;

/**
 * Implements hook_views_data().
 */
function vale_views_data(): array {
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
    'group' => t('Prose linting'),
    'base' => [
      'field' => 'fid',
      'title' => t('Prose linting'),
      'help' => t('Vale alerts in stored content, from the last check.'),
      'weight' => 10,
    ],
  ];
  $data['vale_finding']['fid'] = $number(t('Alert ID'), t('The row id.'));
  $data['vale_finding']['root_type'] = $string(t('Root type'), t('The entity type of the top-level entity.'));
  $data['vale_finding']['root_id'] = $string(t('Root ID'), t('The id of the top-level entity.'));
  $data['vale_finding']['root_bundle'] = $string(t('Root bundle'), t('The bundle of the top-level entity.'));
  $data['vale_finding']['root_bundle']['filter'] = [
    'id' => 'in_operator',
    'options callback' => FindingStorage::class . '::bundleOptions',
  ];
  $data['vale_finding']['root_label'] = $string(t('Root label'), t('The label of the top-level entity.'));
  $data['vale_finding']['root_link'] = [
    'title' => t('Root link'),
    'help' => t('The top-level entity, linked to its edit form.'),
    'real field' => 'root_label',
    'field' => ['id' => 'vale_root_link'],
  ];
  $data['vale_finding']['entity_type'] = $string(t('Entity type'), t('The entity type of the entity that holds the value.'));
  $data['vale_finding']['entity_id'] = $string(t('Entity ID'), t('The id of the entity that holds the value.'));
  $data['vale_finding']['bundle'] = $string(t('Bundle'), t('The bundle of the entity that holds the value.'));
  $data['vale_finding']['label'] = $string(t('Label'), t('The label of the entity that holds the value.'));
  $data['vale_finding']['langcode'] = [
    'title' => t('Language'),
    'help' => t('The language of the translation that holds the value.'),
    'field' => ['id' => 'language'],
    'filter' => ['id' => 'language'],
    'sort' => ['id' => 'standard'],
    'argument' => ['id' => 'language'],
  ];
  $data['vale_finding']['field'] = $string(t('Field'), t('The field name.'));
  $data['vale_finding']['delta'] = $number(t('Delta'), t('The delta of the value.'));
  $data['vale_finding']['rule'] = $string(t('Rule'), t('The Vale rule.'));
  $data['vale_finding']['level'] = $string(t('Level'), t('error, warning, suggestion, or unchecked.'));
  $data['vale_finding']['level']['filter'] = [
    'id' => 'in_operator',
    'options callback' => FindingStorage::class . '::levelOptions',
  ];
  $data['vale_finding']['message'] = $string(t('Message'), t("The rule's message."));
  $data['vale_finding']['matched'] = $string(t('Text'), t('The flagged text.'));
  $data['vale_finding']['line'] = $number(t('Line'), t('The line of the match.'));
  $data['vale_finding']['span_start'] = $number(t('Start'), t('The first column of the match.'));
  $data['vale_finding']['span_end'] = $number(t('End'), t('The last column of the match.'));
  $data['vale_finding']['scanned'] = [
    'title' => t('Scanned'),
    'help' => t('When the alert was recorded.'),
    'field' => ['id' => 'date'],
    'filter' => ['id' => 'date'],
    'sort' => ['id' => 'date'],
  ];
  $data['vale_finding']['vale_export'] = [
    'title' => t('Export link'),
    'help' => t('A link to export the filtered alerts as CSV.'),
    'area' => ['id' => 'vale_export'],
  ];
  return $data;
}
