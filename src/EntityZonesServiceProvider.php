<?php

namespace Drupal\entity_zones;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

class EntityZonesServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('twig.loader.filesystem')) {
      $loader = $container->getDefinition('twig.loader.filesystem');

      $loader->addMethodCall('addPath', [
        'sites/default/files/entity_zones_templates/templates',
        'entity_zones_ext',
      ]);
    }
  }

}
