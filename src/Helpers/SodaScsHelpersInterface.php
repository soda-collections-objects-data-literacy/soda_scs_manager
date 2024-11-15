<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Interface for Helper functions for SCS components.
 */
interface SodaScsHelpersInterface {

  /**
   * Retrieves a referenced component of a given SODa SCS Stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   * @param string $bundle
   *   The bundle of the referenced component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   *   The referenced component.
   *
   * @throws \Drupal\soda_scs_manager\Exceptions\SodaScsComponentException
   *   When the referenced component is not found.
   */
  public function retrieveIncludedComponent(SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface;

  /**
   * Remove value from included component field of a given SODa SCS stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component value to be deleted from field list.
   */
  public function removeIncludedComponentValue(SodaScsStackInterface $stack, SodaScsComponentInterface $component);

  /**
   * Remove non existing components from includedComponents field.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   */
  public function cleanIncludedComponents(SodaScsStackInterface $stack);

}
