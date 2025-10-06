<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\user\EntityOwnerInterface;

/**
 * Interface for Soda SCS Snapshot entities.
 */
interface SodaScsSnapshotInterface extends ContentEntityInterface, EntityOwnerInterface {

  /**
   * Gets the snapshot creation timestamp.
   *
   * @return int
   *   Creation timestamp of the snapshot.
   */
  public function getCreatedTime();

  /**
   * Sets the snapshot creation timestamp.
   *
   * @param int $timestamp
   *   The snapshot creation timestamp.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The called snapshot entity.
   */
  public function setCreatedTime($timestamp);

  /**
   * Gets the snapshot label.
   *
   * @return string
   *   Label of the snapshot.
   */
  public function getLabel();

  /**
   * Sets the snapshot label.
   *
   * @param string $label
   *   The snapshot label.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The called snapshot entity.
   */
  public function setLabel($label);

  /**
   * Gets the snapshot machine name.
   *
   * @return string
   *   Machine name of the snapshot.
   */
  public function getMachineName();

  /**
   * Sets the snapshot machine name.
   *
   * @param string $machine_name
   *   The snapshot machine name.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The called snapshot entity.
   */
  public function setMachineName($machine_name);

  /**
   * Gets the snapshot checksum.
   *
   * @return string
   *   Checksum of the snapshot.
   */
  public function getChecksum();

  /**
   * Sets the snapshot checksum.
   *
   * @param string $checksum
   *   The snapshot checksum.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The called snapshot entity.
   */
  public function setChecksum($checksum);

  /**
   * Gets the snapshot file entity.
   *
   * @return \Drupal\file\FileInterface|null
   *   The file entity or null if not set.
   */
  public function getFile();

  /**
   * Sets the snapshot file.
   *
   * @param int $fid
   *   The file entity ID.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   The called snapshot entity.
   */
  public function setFile($fid);

}
