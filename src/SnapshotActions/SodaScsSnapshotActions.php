<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\SnapshotActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\file\Entity\File;
use Drupal\soda_scs_manager\Helpers\SodaScsActionsHelper;

/**
 * Interface for SODa SCS Snapshot actions.
 */
class SodaScsSnapshotActions implements SodaScsSnapshotActionsInterface {
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The file system.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsComponentActions;

  /**
   * The snapshot helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * The actions helper.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsActionsHelper
   */
  protected SodaScsActionsHelper $sodaScsActionsHelper;

  /**
   * SodaScsSnapshotActions constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $fileSystem
   *   The file system.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsActionsHelper $sodaScsActionsHelper
   *   The actions helper.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsComponentActions
   *   The component actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers $sodaScsSnapshotHelpers
   *   The actions helper.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    FileSystemInterface $fileSystem,
    #[Autowire(service: 'soda_scs_manager.actions.helpers')]
    SodaScsActionsHelper $sodaScsActionsHelper,
    #[Autowire(service: 'soda_scs_manager.component.actions')]
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->fileSystem = $fileSystem;
    $this->sodaScsActionsHelper = $sodaScsActionsHelper;
    $this->sodaScsComponentActions = $sodaScsComponentActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
  }

  /**
   * Restore components based on the manifest mapping.
   *
   * @param array $manifestData
   *   The parsed manifest data.
   * @param string $tempDir
   *   The temporary directory path.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface|null $snapshot
   *   The snapshot entity.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Array with success status and restoration data.
   */
  public function restoreComponentsFromManifest(array $manifestData, string $tempDir, ?SodaScsSnapshotInterface $snapshot = NULL): SodaScsResult {
    $restorationResults = [];
    $errors = [];

    // Loop through the component mappings from the manifest.
    foreach ($manifestData['mapping'] as $componentMapping) {
      $bundle = $componentMapping['bundle'];
      $eid = $componentMapping['eid'];
      $machineName = $componentMapping['machineName'];
      $dumpFile = $componentMapping['dumpFile'];
      $checksumFile = $componentMapping['checksumFile'];

      // @todo Remove this once we have a generic restore from snapshot for all components.
      // @todo Implement SQL and Triplestore component restoration.
      if ($bundle !== 'soda_scs_wisski_component' && $bundle !== 'soda_scs_sql_component' && $bundle !== 'soda_scs_triplestore_component') {
        $this->messenger()->addError($this->t("Cannot restore components from manifest. Only WissKI components are supported."));
        continue;
      }

      // Validate checksum for component dump file.
      if (isset($checksumFile) && file_exists($checksumFile)) {
        $expectedChecksum = trim(file_get_contents($checksumFile));
        $actualChecksum = hash_file('sha256', $dumpFile);

        if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
          $errors[] = "Checksum validation failed for component {$machineName} ({$bundle}).";
          continue;
        }
      }

      // Load the component entity.
      try {
        $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($eid);
        if (!$component) {
          $errors[] = "Component entity {$eid} not found.";
          continue;
        }

        // Important: do not mutate the method parameter $snapshot here.
        // Create or reuse a snapshot per component to ensure correct routing.
        $componentSnapshot = $snapshot ?? $this->createPseudoSnapshotForComponent($component, $manifestData['snapshotMachineName'], $dumpFile, $tempDir);

        // Restore the component using its own snapshot.
        $restoreResult = $this->sodaScsComponentActions->restoreFromSnapshot($componentSnapshot, $tempDir);

        if ($restoreResult->success) {
          $restorationResults[$machineName] = $restoreResult;
        }
        else {
          $errors[] = "Failed to restore component {$machineName}: " . $restoreResult->message;
        }
      }
      catch (\Exception $e) {
        $errors[] = "Exception while restoring component {$machineName}: " . $e->getMessage();
      }
    }

    if (!empty($errors)) {
      return SodaScsResult::failure(
        error: 'Component restoration failed: ' . implode('; ', $errors),
        message: 'Component restoration failed: ' . implode('; ', $errors),
      );
    }

    return SodaScsResult::success(
      message: 'Component restoration successful.',
      data: $restorationResults,
    );
  }

  /**
   * Restore a snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    $snapshotFile = $snapshot->getFile();
    if (!$snapshotFile) {
      return SodaScsResult::failure(
        error: 'Snapshot file not found.',
        message: 'Snapshot file not found.',
      );
    }

    // Get the file URI and convert to system path.
    $fileUri = $snapshotFile->getFileUri();
    $filePath = $this->fileSystem->realpath($fileUri);

    if (!$filePath || !file_exists($filePath)) {
      return SodaScsResult::failure(
        error: 'Snapshot file does not exist on the filesystem.',
        message: 'Snapshot file does not exist on the filesystem.',
      );
    }

    // Step 1: Verify checksum of the snapshot tar file.
    $checksumValidation = $this->sodaScsSnapshotHelpers->validateSnapshotChecksum($snapshot, $filePath);
    if (!$checksumValidation['success']) {
      return SodaScsResult::failure(
        error: $checksumValidation['error'],
        message: 'Checksum validation failed.',
      );
    }

    // Step 2: Create safe temporary directory and unpack the snapshot.
    $tempDir = $this->sodaScsSnapshotHelpers->createTemporaryDirectory();
    if (!$tempDir) {
      return SodaScsResult::failure(
        error: 'Failed to create temporary directory.',
        message: 'Failed to create temporary directory.',
      );
    }

    $unpackResult = $this->sodaScsSnapshotHelpers->unpackSnapshotToTempDirectory($filePath, $tempDir);
    if (!$unpackResult['success']) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $unpackResult['error'],
        message: 'Failed to unpack snapshot.',
      );
    }

    // Step 3: Parse and validate manifest.json.
    $manifestPath = $tempDir . '/manifest.json';
    $manifestData = $this->sodaScsSnapshotHelpers->parseAndValidateManifest($manifestPath);
    if (!$manifestData['success']) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $manifestData['error'],
        message: 'Failed to parse or validate manifest.',
      );
    }

    // Step 4: Delegate restoration to components.
    $restoreResult = $this->restoreComponentsFromManifest($manifestData['data'], $tempDir, NULL);
    if (!$restoreResult->success) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $restoreResult->error,
        message: 'Failed to restore components from manifest.',
      );
    }

    // Step 5: Cleanup temporary directory.
    $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);

    return SodaScsResult::success(
      message: 'Snapshot restored successfully.',
      data: $restoreResult->data,
    );
  }

  /**
   * Create a pseudo snapshot entity for component restoration.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component entity.
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param string $dumpFile
   *   The dump file path.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   A pseudo snapshot entity.
   */
  public function createPseudoSnapshotForComponent($component, string $snapshotMachineName, string $dumpFile, string $tempDir): SodaScsSnapshotInterface {
    // Create a temporary file entity for the dump file.
    $fileUri = 'temporary://' . basename($dumpFile);
    copy($dumpFile, $this->fileSystem->realpath($fileUri));

    $file = File::create([
      'uri' => $fileUri,
      'filename' => basename($dumpFile),
      'status' => 1,
    ]);
    $file->save();

    switch ($component->bundle()) {
      case 'soda_scs_wisski_component':
        $type = 'wisski';
        break;

      case 'soda_scs_sql_component':
        $type = 'sql';
        break;

      case 'soda_scs_triplestore_component':
        $type = 'triplestore';
        break;

      case 'soda_scs_webprotege_component':
      default:
        $type = 'unknown';
    }

    // Create a pseudo snapshot entity.
    $snapshot = $this->entityTypeManager->getStorage('soda_scs_snapshot')->create([
      'label' => 'Pseudo snapshot for restoration',
      'file' => $file->id(),
      'snapshotOfComponent' => $component->id(),
      'machineName' => 'pseudosnapshot-' . $type . '-' . $component->get('machineName')->value,
    ]);

    return $snapshot;
  }

}
