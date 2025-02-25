<?php

namespace Drupal\soda_scs_manager\StackActions;

use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsStackActionsInterface {

  /**
   * Creates a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   *
   * @return array
   *   The result of the request.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsStackInterface $stack): array;

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
   * @param Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   *
   * @return array
   *   The result of the request.
   */
  public function getStack($stack): array;

  /**
   * Update a stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   *
   * @return array
   *   The result of the request.
   */
  public function updateStack($stack): array;

  /**
   * Delete a stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   *
   * @return array
   *   The result of the request.
   */
  public function deleteStack(SodaScsStackInterface $stack): array;

}
