<?php

declare(strict_types=1);

namespace Drupal\vale\Hook;

use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Gives the module's help page.
 */
class ValeHelpHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help(string $route_name, RouteMatchInterface $route_match): string {
    if ($route_name !== 'help.page.vale') {
      return '';
    }
    $output = '<h2>' . $this->t('About') . '</h2>';
    $output .= '<p>' . $this->t('Vale checks the prose in text fields with the <a href=":vale">Vale</a> style linter. An error-level finding stops the save. Warnings and suggestions are shown to the author.', [':vale' => 'https://vale.sh']) . '</p>';
    $output .= '<h2>' . $this->t('Uses') . '</h2>';
    $output .= '<dl>';
    $output .= '<dt>' . $this->t('Turning on checks for a field') . '</dt>';
    $output .= '<dd>' . $this->t('Edit a text field and open <em>Prose checks</em>. You can turn rules off or change their level for that field.') . '</dd>';
    $output .= '<dt>' . $this->t('Finding problems in existing content') . '</dt>';
    $output .= '<dd>' . $this->t('Check content from the prose findings report, with the <em>Check prose with Vale</em> action, or with <code>drush vale:lint</code>.') . '</dd>';
    $output .= '<dt>' . $this->t('Setting up Vale') . '</dt>';
    $output .= '<dd>' . $this->t('Set the Vale binary and its configuration file in <code>settings.php</code>. The status report says whether Vale can run.') . '</dd>';
    return $output . '</dl>';
  }

}
