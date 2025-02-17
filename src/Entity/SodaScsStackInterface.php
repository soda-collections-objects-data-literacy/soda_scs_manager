<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;
use Drupal\user\UserInterface;

/**
 * Interface for the SodaScsStack entity.
 */
interface SodaScsStackInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Get the included Soda SCS Components.
   */
  public function getIncludedComponents();

  /**
   * Add included Soda SCS Component.
   */
  public function addIncludedComponent($component);

}
