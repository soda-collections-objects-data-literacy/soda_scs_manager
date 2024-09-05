<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

/**
 * Interface for SODa SCS Component actions.
 */
interface SodaScsComponentActionsInterface {

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with the created component.
   */
  public function createComponent(SodaScsComponentInterface $component): array;

  /**
   * Get SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with component.
   */
  public function getComponent(SodaScsComponentInterface $component): array;

  /**
   * Update SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with updated component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array;

  /**
   * Delete SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with deleted component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array;

}
