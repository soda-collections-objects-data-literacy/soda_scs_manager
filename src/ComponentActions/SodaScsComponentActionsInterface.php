<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

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
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param int $timestamp
   *   The timestamp of the snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult;

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
   * Restore Component from Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   * @param string|null $tempDir
   *   The path to the temporary directory,
   *   if the files are unpacked in case of stack restoration.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDir): SodaScsResult;

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

}
