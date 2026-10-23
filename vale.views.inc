<?php

/**
 * @file
 * Views integration for the Vale content report.
 *
 * Drupal 11.1 and later call ValeViewsHooks. Drupal 10 calls the function
 * marked #[LegacyHook]. Drupal 12 no longer loads this file.
 */

declare(strict_types=1);

use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\vale\Hook\ValeViewsHooks;

/**
 * Implements hook_views_data().
 */
#[LegacyHook]
function vale_views_data(): array {
  return \Drupal::service(ValeViewsHooks::class)->viewsData();
}
