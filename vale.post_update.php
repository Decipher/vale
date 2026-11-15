<?php

/**
 * @file
 * Post update functions for the Vale module.
 */

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Updates the content report view, unless the site has changed it.
 *
 * The view is replaced only when it still matches the one that 1.0.0 and
 * 1.0.1 shipped. Values that the site sets or Drupal works out are left
 * out of the match: the UUID, the language, the dependencies, the cache
 * metadata and the pager's default heading level. Value types and the order
 * of keys are ignored too, except for the order of the view's handlers.
 */
function vale_post_update_report_table_fits(): ?TranslatableMarkup {
  $name = 'views.view.vale_findings';
  $active = \Drupal::config($name);
  // The view is optional config. A site without Views has none.
  if ($active->isNew()) {
    return NULL;
  }

  // Views runs its handlers in the order they are listed, so a site that
  // only reordered them has changed the view. The order of other keys does
  // not matter.
  $ordered = ['fields', 'sorts', 'filters', 'arguments', 'relationships', 'header', 'footer', 'empty'];
  $normalise = static function (mixed $value, int|string $key = '') use (&$normalise, $ordered): mixed {
    if (!is_array($value)) {
      return is_bool($value) ? (string) (int) $value : (string) $value;
    }
    if (!in_array($key, $ordered, TRUE)) {
      ksort($value);
    }
    foreach ($value as $child => $item) {
      $value[$child] = $normalise($item, $child);
    }
    return $value;
  };
  $data = $active->getRawData();
  unset($data['uuid'], $data['_core'], $data['langcode'], $data['dependencies']);
  foreach (array_keys($data['display'] ?? []) as $display) {
    unset($data['display'][$display]['cache_metadata']);
    // Drupal 10 saves the pager's default heading level into the view.
    if (($data['display'][$display]['display_options']['pager']['options']['pagination_heading_level'] ?? NULL) === 'h4') {
      unset($data['display'][$display]['display_options']['pager']['options']['pagination_heading_level']);
    }
  }
  // The hash of the view that 1.0.0 and 1.0.1 shipped, after the same steps.
  if (hash('sha256', serialize($normalise($data))) !== '47a1c786fda3c4890284461f1107e3a2a7291f02b396da2ff10d049e2c8f5cc6') {
    return new TranslatableMarkup('The content report view has been changed on this site, so it was kept. To use the new one, run <code>@delete</code> and then <code>@install</code>. That removes your changes to it.', [
      '@delete' => 'drush config:delete ' . $name,
      '@install' => 'drush php:eval "Drupal::entityTypeManager()->getStorage(\'view\')->create((new Drupal\\Core\\Config\\FileStorage(Drupal::service(\'extension.list.module\')->getPath(\'vale\') . \'/config/optional\'))->read(\'' . $name . '\'))->save();"',
    ]);
  }

  // The module ships this file, so it can be read.
  /** @var array<string, mixed> $shipped */
  $shipped = (new FileStorage(\Drupal::service('extension.list.module')->getPath('vale') . '/config/optional'))->read($name);
  $storage = \Drupal::entityTypeManager()->getStorage('view');
  /** @var \Drupal\views\ViewEntityInterface $view */
  $view = $storage->load('vale_findings');
  // This keeps the view's UUID and its language, and works out its
  // dependencies again.
  $shipped['langcode'] = $active->get('langcode');
  $storage->updateFromStorageRecord($view, $shipped)->save();
  return new TranslatableMarkup('Updated the content report view to fit admin themes.');
}
