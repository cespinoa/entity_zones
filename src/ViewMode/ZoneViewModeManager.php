<?php

namespace Drupal\entity_zones\ViewMode;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityStorageInterface;

class ZoneViewModeManager {

  protected EntityStorageInterface $viewModeStorage;

  public function __construct(EntityTypeManagerInterface $entityTypeManager) {
    $this->viewModeStorage = $entityTypeManager->getStorage('entity_view_mode');
  }

  /**
   * Ensures a view mode exists for a given entity type.
   */
  public function ensure(string $entity_type, string $view_mode): void {
    
    $id = "$entity_type.$view_mode";

    //~ $display = $this->viewModeStorage->load($id);
    //~ kint($display);

    if (!$this->viewModeStorage->load($id)) {
      $this->viewModeStorage->create([
        'id' => $id,
        'targetEntityType' => $entity_type,
        'label' => ucfirst($view_mode),
        'status' => TRUE,
      ])->save();
    }
  }
}
