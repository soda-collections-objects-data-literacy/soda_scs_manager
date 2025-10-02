<?php

namespace Drupal\soda_scs_manager\SnapshotActions;

use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * Interface for SODa SCS Snapshot actions.
 */
interface SodaScsSnapshotActionsInterface {

  /**
   * Restore components from manifest.
   *
   * @param array $manifestData
   *   The manifest data.
   * @param string $tempDir
   *   The temporary directory.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface|null $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreComponentsFromManifest(array $manifestData, string $tempDir, ?SodaScsSnapshotInterface $snapshot = NULL): SodaScsResult;

  /**
   * Restore a snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult;

}
