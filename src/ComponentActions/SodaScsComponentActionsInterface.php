<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Interface for SODa SCS Component actions.
 */
interface SodaScsComponentActionsInterface {

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with the created component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array;

  /**
   * Create SODa SCS Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component to create the snapshot from.
   *
   * @param string $label
   *   The label of the snapshot.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $label): array;

  /**
   * Get all SODa SCS Component.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with all component.
   */
  public function getComponents(): array;

  /**
   * Get SODa SCS Component.
   *
   * @param array $props
   *   The properties of the component you are looking for.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with component.
   */
  public function getComponent(SodaScsComponentInterface $props): array;

  /**
   * Update SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with updated component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array;

  /**
   * Delete SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array{
   *   message: string,
   *   data: array[\Psr\Http\Message\ResponseInterface|\Exception],
   *   success: bool,
   *   error: string|null,
   *   statusCode: int,
   *   }
   *   Result information with deleted component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array;

}
