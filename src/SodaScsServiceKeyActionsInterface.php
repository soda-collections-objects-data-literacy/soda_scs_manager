<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsServiceKeyActionsInterface {

  /**
   * Generates a random password.
   *
   * @return string
   */
  function generateRandomPassword(): string ;

  /**
   * Creates a new Service Key entity.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsService $component
   *   The service entity.
   * 
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   */
  function createServiceKey($component): SodaScsServiceKeyInterface ;

  /**
   * Get an existing Service Key entity.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsService $component
   * 
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface|null
   */
  function getServiceKey($component): ?SodaScsServiceKeyInterface ;  
}