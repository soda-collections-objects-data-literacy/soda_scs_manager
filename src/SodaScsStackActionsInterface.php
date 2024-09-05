<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsStackActionsInterface {

  /**
   * Creates a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array;

  /**
   * Get all stacks of a bundle.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result of the request.
   */
  public function getStacks($bundle, $options): array;

  /**
   * Read a stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function getStack($component): array;

  /**
   * Update a stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function updateStack($component): array;

  /**
   * Delete a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function deleteStack(SodaScsComponentInterface $component): array;

}
