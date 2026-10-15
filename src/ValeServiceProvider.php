<?php

declare(strict_types=1);

namespace Drupal\vale;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Gives the content walker the service that lists a workspace's entities.
 *
 * Drupal 11.3 renamed workspaces.association to workspaces.tracker and
 * deprecated the old name. Each version gets the one it has, so neither
 * version sees a deprecation.
 */
class ValeServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
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
