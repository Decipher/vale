<?php

declare(strict_types=1);

namespace Drupal\vale\Plugin\views\field;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsField;
use Drupal\views\Plugin\views\field\FieldPluginBase;
use Drupal\views\ResultRow;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Shows the top-level entity's label, linked to its edit form.
 *
 * The link is built from the stored type and id, without loading the
 * entity. Access is checked when the link is followed.
 */
#[ViewsField('vale_root_link')]
final class RootLink extends FieldPluginBase {

  /**
   * Constructs a RootLink.
   *
   * @param array<string, mixed> $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin id.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new self($configuration, $plugin_id, $plugin_definition, $container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function query(): void {
    $this->ensureMyTable();
    $this->addAdditionalFields(['root_type', 'root_id', 'root_label']);
  }

  /**
   * {@inheritdoc}
   *
   * @return array<string, mixed>|string
   *   The link, or the label when the entity type has no edit form.
   */
  public function render(ResultRow $values): array|string {
    $type = (string) $this->getValue($values, 'root_type');
    $id = (string) $this->getValue($values, 'root_id');
    $label = (string) $this->getValue($values, 'root_label');
    // The edit form is the entity type's link template, so nothing is loaded.
    if ($type === '' || !$this->entityTypeManager->getDefinition($type, FALSE)?->hasLinkTemplate('edit-form')) {
      return $label;
    }
    return Link::fromTextAndUrl($label !== '' ? $label : $type . ' ' . $id, Url::fromRoute('entity.' . $type . '.edit_form', [$type => $id]))->toRenderable();
  }

}
