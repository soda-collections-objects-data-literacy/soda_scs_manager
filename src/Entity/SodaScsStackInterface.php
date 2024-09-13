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

  /**
   * Get the owner of the SODa SCS Component.
   *
   * @return \Drupal\user\Entity\User
   *   The owner of the SODa SCS Component.
   */
  public function getOwner();

  /**
   * Set the owner of the SODa SCS Component.
   *
   * @param \Drupal\user\Entity\User $account
   *   The owner of the SODa SCS Component.
   *
   * @return $this
   */
  public function setOwner(UserInterface $account);

  /**
   * Get the owner ID of the SODa SCS Component.
   *
   * @return int
   *   The owner ID of the SODa SCS Component.
   */
  public function getOwnerId();

  /**
   * Set the owner ID of the SODa SCS Component.
   *
   * @param int $uid
   *   The owner ID of the SODa SCS Component.
   *
   * @return $this
   */
  public function setOwnerId($uid): self;

  /**
   * Get the type of the Soda SCS Stack.
   *
   * @return string
   *   The type of the Soda SCS Stack.
   */
  public function getBundle();

  /**
   * Set the bundle of the Soda SCS Stack.
   *
   * @param string $bundle
   *   The bundle of the Soda SCS Stack.
   *
   * @return $this
   */
  public function setBundle($bundle);

}
