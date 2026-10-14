<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\views\area;

use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsArea;
use Drupal\views\Plugin\views\area\AreaPluginBase;

/**
 * Links to the CSV export, with the report's current filters.
 */
#[ViewsArea('vale_export')]
final class ExportLink extends AreaPluginBase {

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>
   *   The link.
   */
  public function render($empty = FALSE): array {
    if ($empty && empty($this->options['empty'])) {
      return [];
    }
    // The exposed form's accepted values, not the raw query string.
    $query = array_intersect_key($this->view->exposed_raw_input, array_flip(['root_bundle', 'level', 'rule']));
    $query = array_filter($query, static fn (mixed $value): bool => is_string($value) && $value !== '' && $value !== 'All');
    $url = Url::fromRoute('vale.report_export', [], ['query' => $query]);
    $link = Link::fromTextAndUrl($this->t('Export these findings as CSV'), $url)->toRenderable();
    $link['#access'] = $url->access();
    return $link;
  }

}
