<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\vale\Canvas\ComponentTreeProse;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Adds the services that depend on optional modules.
 *
 * The content walker gets the service that lists a workspace's entities.
 * Drupal 11.3 renamed workspaces.association to workspaces.tracker and
 * deprecated the old name. Each version gets the one it has, so neither
 * version sees a deprecation.
 *
 * The field linter gets the reader of Canvas component trees, which is
 * defined only when Canvas is installed. Its class uses Canvas classes, so
 * without Canvas nothing loads it.
 */
class ValeServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    $modules = $container->getParameter('container.modules');
    if (is_array($modules) && isset($modules['canvas'])) {
      $container->register('vale.component_tree_prose', ComponentTreeProse::class);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('vale.component_tree_prose') && $container->hasDefinition('vale.field_linter')) {
      $container->getDefinition('vale.field_linter')->setArgument(1, new Reference('vale.component_tree_prose'));
    }
    if (!$container->hasDefinition('vale.content_walker')) {
      return;
    }
    foreach (['workspaces.tracker', 'workspaces.association'] as $id) {
      if ($container->hasDefinition($id)) {
        $container->getDefinition('vale.content_walker')->setArgument(6, new Reference($id));
        return;
      }
    }
  }

}
