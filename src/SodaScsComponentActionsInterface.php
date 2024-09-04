<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

interface SodaScsComponentActionsInterface {

   /**
   * Create SODa SCS Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function createComponent(SodaScsComponentInterface $component): array;

  /**
   * Get SODa SCS Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function getComponent(SodaScsComponentInterface $component): array;
  /** 
   * Update SODa SCS Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function updateComponent(SodaScsComponentInterface $component): array;

  /**
   * Delete SODa SCS Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function deleteComponent(SodaScsComponentInterface $component): array;
}
