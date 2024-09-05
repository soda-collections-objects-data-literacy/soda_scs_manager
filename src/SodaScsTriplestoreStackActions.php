<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsStackActionsInterface;

/**
 * Handles the communication with the SCS user manager daemon for triplestore stacks.
 */
class SodaScsTriplestoreStackActions implements SodaScsStackActionsInterface
{

  use DependencySerializationTrait;

  /**
   * Class constructor.
   */
  public function __construct() {}

  /**
   * Create a triplestore stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component
   *  
   * @return array
   * 
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array
  {
    return [];
  }

  /**
   * Read all triplestore stacks.
   * 
   * @param $bundle
   * @param $options
   * 
   * @return array
   */
  public function getStacks($bundle, $options): array
  {
    return [];
  }

  /**
   * Read a triplestore stack.
   * 
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function getStack($component): array
  {
    return [];
  }

  /**
   * Update a triplestore stack.
   * 
   * @param $component
   * 
   * @return array
   */
  public function updateStack($component): array
  {
    return [];
  }

  /**
   * Delete a triplestore stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * 
   * @return array
   */
  public function deleteStack(SodaScsComponentInterface $component): array
  {
    return [];
  }
}
