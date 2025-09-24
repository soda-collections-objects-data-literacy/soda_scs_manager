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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface|null $snapshot
   *   The snapshot.
   * @param string $tempDir
   *   The temporary directory.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreComponentsFromManifest(array $manifestData, ?SodaScsSnapshotInterface $snapshot = NULL, string $tempDir): SodaScsResult;

}
