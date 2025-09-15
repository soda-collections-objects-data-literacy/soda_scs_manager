<?php

namespace Drupal\soda_scs_manager\SnapshotActions;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * Interface for Soda SCS Snapshot actions.
 */
interface SodaScsSnapshotActionsInterface {

  /**
   * Restore a snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreSnapshot(SodaScsSnapshot $snapshot): SodaScsResult;

  /**
   * Restore a component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  public function restoreComponent(SodaScsComponentInterface $component, string $snapshotFilePath);

  /**
   * Restore a stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  public function restoreStack(SodaScsStackInterface $stack, string $snapshotFilePath);

  /**
   * Restore an SQL component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SQL component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  public function restoreSqlComponent(SodaScsComponentInterface $component, string $snapshotFilePath);

  /**
   * Restore a triplestore component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The triplestore component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  public function restoreTriplestoreComponent(SodaScsComponentInterface $component, string $snapshotFilePath);

  /**
   * Restore a WissKI component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  public function restoreWisskiComponent(SodaScsComponentInterface $component, string $snapshotFilePath);

}
