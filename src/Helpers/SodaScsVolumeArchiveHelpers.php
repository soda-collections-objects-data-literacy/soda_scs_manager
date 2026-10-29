<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Archives a single Docker volume into a tar.gz and restores it.
 *
 * Owners are stored numerically, so volumes written by other images (e.g.
 * jovyan in Jupyter servers) keep working after a restore.
 */
class SodaScsVolumeArchiveHelpers {

  /**
   * Constructs the helper.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    protected SodaScsSnapshotHelpers $snapshotHelpers,
  ) {}

  /**
   * Writes the content of a volume to {targetDirectory}/{tarFileName}.
   *
   * @param string $volumeName
   *   Docker volume name.
   * @param string $targetDirectory
   *   Absolute directory below the snapshot bind mount.
   * @param string $tarFileName
   *   File name of the archive.
   * @param string[] $exclude
   *   Paths relative to the volume root that must not be archived.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function exportVolume(string $volumeName, string $targetDirectory, string $tarFileName, array $exclude = []): SodaScsResult {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID . ':' . SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_GID;
    $tar = escapeshellarg('/backup/' . $tarFileName);
    $excludeArgs = '';
    foreach ($exclude as $path) {
      $excludeArgs .= ' --exclude=' . escapeshellarg($path);
    }
    return $this->containerHelpers->runShortLivedContainer(
      'export--' . $volumeName . '__' . $this->snapshotHelpers->generateRandomSuffix(),
      ['sh', '-c', 'tar czf ' . $tar . $excludeArgs . ' --numeric-owner -C /volume . && chown ' . $owner . ' ' . $tar],
      [
        $volumeName . ':/volume:ro',
        $this->snapshotHelpers->convertContainerPathToHostPath($targetDirectory) . ':/backup',
      ],
    );
  }

  /**
   * Replaces the content of a volume with an archive; creates the volume.
   *
   * Nothing may use the volume while this runs.
   *
   * @param string $volumeName
   *   Docker volume name.
   * @param string $sourceDirectory
   *   Absolute directory below the snapshot bind mount.
   * @param string $tarFileName
   *   File name of the archive.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function importVolume(string $volumeName, string $sourceDirectory, string $tarFileName): SodaScsResult {
    $command = 'rm -rf /volume/* /volume/.[!.]* /volume/..?* && ' .
      'tar xzpf ' . escapeshellarg('/restore/' . $tarFileName) . ' --numeric-owner -C /volume';
    return $this->containerHelpers->runShortLivedContainer(
      'restore--' . $volumeName . '__' . $this->snapshotHelpers->generateRandomSuffix(),
      ['sh', '-c', $command],
      [
        $volumeName . ':/volume',
        $this->snapshotHelpers->convertContainerPathToHostPath($sourceDirectory) . ':/restore:ro',
      ],
    );
  }

}
