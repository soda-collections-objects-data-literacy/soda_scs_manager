<?php

namespace Drupal\soda_scs_manager\SnapshotActions;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;

/**
 * Interface for Soda SCS Snapshot actions.
 */
class SodaScsSnapshotActions implements SodaScsSnapshotActionsInterface {

  public function __construct(
    protected SodaScsComponentActionsInterface $sodaScsComponentActions,
    protected SodaScsSnapshotHelpers $snapshotHelpers,
    protected SodaScsRunRequestInterface $dockerRunService,
    protected FileSystemInterface $fileSystem,
    protected LoggerChannelFactoryInterface $loggerFactory,
  ) {
  }

  /**
   * Restore a snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot
   *   The snapshot to restore.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the restore operation.
   *
   * @todo May not need this method.
   */
  public function restoreSnapshot(SodaScsSnapshot $snapshot): SodaScsResult {
    return SodaScsResult::success(
      message: 'Snapshot restored successfully',
      data: [
        'snapshot' => $snapshot,
      ],
    );

  }

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
  public function restoreComponent($component, $snapshotFilePath) {
    // This is a placeholder implementation.
    // The actual restore logic would need to:
    // 1. Extract the snapshot file (tar.gz).
    // 2. Based on component bundle, restore the appropriate data.
    // 3. For SQL components: restore database.
    // 4. For WissKI components: restore files.
    // 5. For triplestore components: restore RDF data.
    switch ($component->bundle()) {
      case 'soda_scs_sql_component':
        return $this->restoreSqlComponent($component, $snapshotFilePath);

      case 'soda_scs_triplestore_component':
        return $this->restoreTriplestoreComponent($component, $snapshotFilePath);

      case 'soda_scs_wisski_component':
        return $this->restoreWisskiComponent($component, $snapshotFilePath);

      default:
        return [
          'success' => FALSE,
          'error' => 'Unsupported component type: ' . $component->bundle(),
        ];
    }
  }

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
  public function restoreStack($stack, $snapshotFilePath) {
    // Placeholder implementation for stack restore.
    // This would need to handle multi-component stack restoration.
    return [
      'success' => FALSE,
      'error' => 'Stack restore functionality not yet implemented.',
    ];
  }

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
  public function restoreSqlComponent($component, $snapshotFilePath) {
    // Placeholder implementation.
    // Actual implementation would need to:
    // 1. Extract the tar.gz file to get the SQL dump.
    // 2. Use Docker exec to restore the database in the SQL container.
    return [
      'success' => FALSE,
      'error' => 'SQL component restore functionality not yet implemented.',
    ];
  }

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
  public function restoreTriplestoreComponent($component, $snapshotFilePath) {
    // Placeholder implementation.
    // Actual implementation would need to:
    // 1. Extract the tar.gz file to get the N-Quads file.
    // 2. Use OpenGDB API to clear and reload the triplestore.
    return [
      'success' => FALSE,
      'error' => 'Triplestore component restore functionality not yet implemented.',
    ];
  }

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
  public function restoreWisskiComponent(SodaScsComponentInterface $component, string $snapshotFilePath) {
    try {
      // Validate snapshot file path existence.
      if (empty($snapshotFilePath) || !file_exists($snapshotFilePath)) {
        return [
          'success' => FALSE,
          'error' => 'Snapshot file does not exist at path: ' . $snapshotFilePath,
        ];
      }

      // Services are now injected via constructor.
      $snapshotHelpers = $this->snapshotHelpers;
      $dockerRunService = $this->dockerRunService;
      $fileSystem = $this->fileSystem;

      // Compose temporary working directories under private storage.
      $privateRoot = $fileSystem->realpath('private://');
      if (!$privateRoot) {
        return [
          'success' => FALSE,
          'error' => 'Private file system root could not be resolved.',
        ];
      }

      $componentMachineName = $component->get('machineName')->value;
      $randomSuffix = $snapshotHelpers->generateRandomSuffix();
      $tmpBasePath = $privateRoot . '/restore-tmp/' . $componentMachineName . '/' . $randomSuffix;
      $extractPath = $tmpBasePath . '/extract';
      $rollbackPath = $tmpBasePath . '/rollback';

      foreach ([$tmpBasePath, $extractPath, $rollbackPath] as $dirPath) {
        $dirCreate = $snapshotHelpers->createDir($dirPath);
        if (!$dirCreate['success']) {
          return [
            'success' => FALSE,
            'error' => 'Failed to create temporary directory: ' . $dirPath . ' (' . ($dirCreate['error'] ?? 'unknown error') . ')',
          ];
        }
      }

      // Determine bind mounts and container parameters.
      $volumeName = $componentMachineName . '_drupal-root';
      $snapshotDir = dirname($snapshotFilePath);
      $snapshotFileName = basename($snapshotFilePath);

      $containerName = 'restore--' . $randomSuffix . '--' . $componentMachineName . '--drupal';

      // Build a robust shell script that backs up current volume, extracts
      // snapshot into a working dir, swaps contents, and rolls back on errors.
      $script =
        'SNAP="/backup/' . addslashes($snapshotFileName) . '"; ' .
        'WORK="/work"; ROLL="/rollback"; TARGET="/target"; ' .
        'mkdir -p "$WORK" "$ROLL"; ' .
        'BACKUP_TAR="$ROLL/pre-restore-$(date +%s).tar.gz"; ' .
        'tar czf "$BACKUP_TAR" -C "$TARGET" . || { echo "Backup failed" >&2; exit 1; }; ' .
        'if tar xzf "$SNAP" -C "$WORK"; then ' .
          'find "$TARGET" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; ' .
          '( cd "$WORK" && tar cf - . ) | ( cd "$TARGET" && tar xf - ); ' .
          'chown -R 33:33 "$TARGET" || true; ' .
          'echo OK > "$ROLL/RESTORE_OK"; ' .
        'else ' .
          'echo "Extraction failed, rolling back" >&2; ' .
          'find "$TARGET" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; ' .
          'tar xzf "$BACKUP_TAR" -C "$TARGET"; ' .
          'exit 1; ' .
        'fi';

      $requestParams = [
        'name' => $containerName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => 'root',
        'cmd' => [
          'sh',
          '-c',
          $script,
        ],
        'hostConfig' => [
          'Binds' => [
            $volumeName . ':/target',
            $snapshotDir . ':/backup',
            $extractPath . ':/work',
            $rollbackPath . ':/rollback',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Create container.
      $createContainerRequest = $dockerRunService->buildCreateRequest($requestParams);
      $createContainerResponse = $dockerRunService->makeRequest($createContainerRequest);
      if (!$createContainerResponse['success']) {
        return [
          'success' => FALSE,
          'error' => 'Could not create restore container: ' . ($createContainerResponse['error'] ?? 'unknown error'),
          'data' => [
            'createContainerResponse' => $createContainerResponse,
            'metadata' => [
              'tmpBasePath' => $tmpBasePath,
              'extractPath' => $extractPath,
              'rollbackPath' => $rollbackPath,
              'containerName' => $containerName,
              'volumeName' => $volumeName,
              'snapshotFilePath' => $snapshotFilePath,
            ],
          ],
        ];
      }

      // Get container ID and start the container.
      $containerId = json_decode($createContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $startContainerRequest = $dockerRunService->buildStartRequest([
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ]);
      $startContainerResponse = $dockerRunService->makeRequest($startContainerRequest);

      if (!$startContainerResponse['success']) {
        return [
          'success' => FALSE,
          'error' => 'Could not start restore container: ' . ($startContainerResponse['error'] ?? 'unknown error'),
          'data' => [
            'createContainerResponse' => $createContainerResponse,
            'startContainerResponse' => $startContainerResponse,
            'metadata' => [
              'tmpBasePath' => $tmpBasePath,
              'extractPath' => $extractPath,
              'rollbackPath' => $rollbackPath,
              'containerId' => $containerId,
              'containerName' => $containerName,
              'volumeName' => $volumeName,
              'snapshotFilePath' => $snapshotFilePath,
            ],
          ],
        ];
      }

      return [
        'success' => TRUE,
        'message' => 'Restore container created and started successfully.',
        'data' => [
          'createContainerResponse' => $createContainerResponse,
          'startContainerResponse' => $startContainerResponse,
          'metadata' => [
            'tmpBasePath' => $tmpBasePath,
            'extractPath' => $extractPath,
            'rollbackPath' => $rollbackPath,
            'containerId' => $containerId,
            'containerName' => $containerName,
            'volumeName' => $volumeName,
            'snapshotFilePath' => $snapshotFilePath,
          ],
        ],
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'WissKI restore failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

}
