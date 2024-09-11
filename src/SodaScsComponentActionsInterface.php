<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Interface for SODa SCS Component actions.
 */
interface SodaScsComponentActionsInterface {

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $component
   *   The SODa SCS Component. Components are always part of a stack.
   *
   * @return array
   *   Result information with the created component.
   */
  public function createComponent(SodaScsStackInterface $stack): array;

  /**
   * Get all SODa SCS Component.
   *
   * @return array
   *   Result information with all component.
   */
  public function getComponents(): array;

  /**
   * Get SODa SCS Component.
   *
   * @param array $props
   *   The properties of the component you are looking for.
   *
   * @return array
   *   Result information with component.
   */
  public function getComponent(SodaScsComponentInterface $props): array;

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
