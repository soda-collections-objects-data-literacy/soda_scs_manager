<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Archives and restores the persistent Docker volumes of a WissKI instance.
 *
 * Works on volume names only, so the archive of one instance can be restored
 * into another one (demo templates) as well as into itself (snapshots).
 */
class SodaScsWisskiVolumeHelpers {

  use StringTranslationTrait;

  /**
   * Site files that hold the credentials and hash salt of an instance.
   *
   * They stay with the target when an archive of another instance is
   * restored; otherwise the target would connect to the source database.
   */
  protected const INSTANCE_SETTINGS_PATTERNS = [
    'settings*.php',
    'services*.yml',
  ];

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
   * Archives sites/ and private-files/ of an instance into one tar.gz.
   *
   * @param string $machineName
   *   Machine name of the WissKI component (volume prefix).
   * @param string $targetDirectory
   *   Absolute directory (snapshot bind mount) the archive is written to.
   * @param string $tarFileName
   *   File name of the archive.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function exportVolumes(string $machineName, string $targetDirectory, string $tarFileName): SodaScsResult {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID . ':' . SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_GID;
    $tar = escapeshellarg('/backup/' . $tarFileName);
    $command = 'mkdir -p /staging/sites /staging/private-files && ' .
      'cp -a /source-sites/. /staging/sites/ && ' .
      'cp -a /source-private/. /staging/private-files/ && ' .
      'tar czf ' . $tar . ' -C /staging sites private-files && ' .
      'chown ' . $owner . ' ' . $tar;

    return $this->containerHelpers->runShortLivedContainer(
      'export--' . $machineName . '--drupal__' . $this->snapshotHelpers->generateRandomSuffix(),
      ['sh', '-c', $command],
      [
        self::sitesVolumeName($machineName) . ':/source-sites:ro',
        self::privateFilesVolumeName($machineName) . ':/source-private:ro',
        $this->snapshotHelpers->convertContainerPathToHostPath($targetDirectory) . ':/backup',
      ],
    );
  }

  /**
   * Replaces the volume contents of an instance with an archive.
   *
   * The WissKI container must be stopped. Supports the 3.x layout (sites/,
   * private-files/) and the 2.x layout (web/sites/, private-files/).
   *
   * @param string $machineName
   *   Machine name of the target WissKI component.
   * @param string $sourceDirectory
   *   Absolute directory (snapshot bind mount) that holds the archive.
   * @param string $tarFileName
   *   File name of the archive.
   * @param bool $keepInstanceSettings
   *   Keep settings.php and services.yml of the target; needed when the
   *   archive comes from another instance.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function importVolumes(string $machineName, string $sourceDirectory, string $tarFileName, bool $keepInstanceSettings): SodaScsResult {
    return $this->containerHelpers->runShortLivedContainer(
      'restore--' . $machineName . '--drupal__' . $this->snapshotHelpers->generateRandomSuffix(),
      ['sh', '-c', $this->buildRestoreCommand($tarFileName, $keepInstanceSettings)],
      [
        self::sitesVolumeName($machineName) . ':/volume-sites',
        self::privateFilesVolumeName($machineName) . ':/volume-private',
        $this->snapshotHelpers->convertContainerPathToHostPath($sourceDirectory) . ':/restore:ro',
      ],
    );
  }

  /**
   * Docker volume with the private files (/opt/drupal/private-files).
   */
  public static function privateFilesVolumeName(string $machineName): string {
    return $machineName . '--drupal-private-files';
  }

  /**
   * Docker volume with the site state (/opt/drupal/web/sites).
   */
  public static function sitesVolumeName(string $machineName): string {
    return $machineName . '--drupal-sites';
  }

  /**
   * Shell command that purges both volumes and extracts the archive.
   */
  public function buildRestoreCommand(string $tarFileName, bool $keepInstanceSettings): string {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID . ':' . SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_GID;
    $saveSettings = '';
    $restoreSettings = '';
    if ($keepInstanceSettings) {
      $patterns = implode(' ', array_map(
        static fn (string $pattern): string => '/volume-sites/default/' . $pattern,
        self::INSTANCE_SETTINGS_PATTERNS,
      ));
      $saveSettings = 'mkdir -p /keep && for f in ' . $patterns . '; do [ -e "$f" ] && cp -a "$f" /keep/; done; ' .
        'rm -f /keep/default.settings.php /keep/default.services.yml; ' .
        '[ -f /keep/settings.php ] || { echo "Target settings.php not found; refusing to overwrite the instance" >&2; exit 1; }; ';
      $restoreSettings = 'mkdir -p /volume-sites/default && rm -f /volume-sites/default/settings*.php /volume-sites/default/services*.yml && cp -a /keep/. /volume-sites/default/ && ';
    }

    return 'STAGING=/staging && ' .
      'mkdir -p "$STAGING" && ' .
      $saveSettings .
      'rm -rf /volume-sites/* /volume-sites/.[!.]* /volume-sites/..?* && ' .
      'rm -rf /volume-private/* /volume-private/.[!.]* /volume-private/..?* && ' .
      'tar -xzf ' . escapeshellarg('/restore/' . $tarFileName) . ' -C "$STAGING" && ' .
      'if [ -d "$STAGING/sites" ]; then ' .
      'cp -a "$STAGING/sites/." /volume-sites/ && cp -a "$STAGING/private-files/." /volume-private/; ' .
      'elif [ -d "$STAGING/web/sites" ]; then ' .
      'cp -a "$STAGING/web/sites/." /volume-sites/ && ' .
      '[ -d "$STAGING/private-files" ] && cp -a "$STAGING/private-files/." /volume-private/ || true; ' .
      'else echo "Unrecognized WissKI snapshot layout" >&2; exit 1; fi && ' .
      $restoreSettings .
      'rm -rf "$STAGING" && chown -R ' . $owner . ' /volume-sites /volume-private';
  }

}
