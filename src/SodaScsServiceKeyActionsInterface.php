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
   *   Randomly generated password.
   */
  public function generateRandomPassword(): string;

  /**
   * Creates a new Service Key entity.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsService $component
   *   The service entity.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   *   The created service key.
   */
  public function createServiceKey($component): SodaScsServiceKeyInterface;

  /**
   * Get an existing Service Key entity.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsService $component
   *   The service entity.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface|null
   *   The existing service key.
   */
  public function getServiceKey($component): ?SodaScsServiceKeyInterface;

}
