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
 * metadata and the pager's default heading level. Key order and value types
 * are ignored too.
 */
function vale_post_update_report_table_fits(): ?TranslatableMarkup {
  $name = 'views.view.vale_findings';
  $active = \Drupal::config($name);
  // The view is optional config. A site without Views has none.
  if ($active->isNew()) {
    return NULL;
  }

  $normalise = static function (mixed $value) use (&$normalise): mixed {
    if (is_array($value)) {
      ksort($value);
      return array_map($normalise, $value);
    }
    return is_bool($value) ? (string) (int) $value : (string) $value;
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
  if (hash('sha256', serialize($normalise($data))) !== '8a2c8952d872bfb4823472fa4c2a783a4f219d06aecf479e921bb36ce81156b2') {
    return new TranslatableMarkup('The content report view has been changed on this site, so it was kept. To use the new one, run <code>@delete</code> and then <code>@install</code>. That removes your changes to it.', [
      '@delete' => 'drush config:delete ' . $name,
      '@install' => 'drush php:eval "Drupal::service(\'config.installer\')->installOptionalConfig();"',
    ]);
  }

  $shipped = (new FileStorage(\Drupal::service('extension.list.module')->getPath('vale') . '/config/optional'))->read($name);
  if (!is_array($shipped)) {
    return NULL;
  }
  $storage = \Drupal::entityTypeManager()->getStorage('view');
  /** @var \Drupal\views\ViewEntityInterface $view */
  $view = $storage->load('vale_findings');
  // This keeps the view's UUID and works out its dependencies again.
  $storage->updateFromStorageRecord($view, $shipped)->save();
  return new TranslatableMarkup('Updated the content report view to fit admin themes.');
}
