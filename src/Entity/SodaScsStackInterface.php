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
   * Get the label.
   *
   * @return string
   *   The label.
   */
  public function getLabel();

  /**
   * Set the label.
   *
   * @param string $label
   *   The label.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface
   *   The called object.
   */
  public function setLabel($label);

  /**
   * Get the included Soda SCS Components.
   */
  public function getIncludedComponents();

  /**
   * Add included Soda SCS Component.
   */
  public function addIncludedComponent($component);

}
