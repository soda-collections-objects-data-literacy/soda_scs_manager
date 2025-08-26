<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;

/**
 * Helper class for snapshot operations.
 */
class SodaScsSnapshotHelpers {

  use StringTranslationTrait;

  /**
   * The component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * The docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions
   */
  protected $sodaScsDockerExecServiceActions;

  /**
   * The docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions
   */
  protected $sodaScsDockerRunServiceActions;


  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

/**
 * {@inheritdoc}
 */
public function __construct(
  SodaScsComponentHelpers $sodaScsComponentHelpers,
  SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions,
  SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
  LoggerChannelFactoryInterface $loggerFactory,
) {
  $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
  $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
  $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
  $this->logger = $loggerFactory->get('soda_scs_manager');
}


  /**
   * Constuct the snapshot paths.
   */

  public function constructSnapshotPaths(SodaScsComponentInterface $component, $snapshotMachineName, string $timestamp): array {
      $bundle = $component->bundle();

      switch ($bundle) {
        case 'soda_scs_filesystem_component':
          $type = 'files';
          break;
        case 'soda_scs_wisski_component':
          $type = 'files';
          break;
        case 'soda_scs_sql_component':
          $type = 'sql';
          break;
        case 'soda_scs_triple_store_component':
          $type = 'nq';
          break;
        default:
          throw new \Exception('Invalid component bundle: ' . $bundle);
      }

      // Get the private file system path from Drupal's file system settings. (/var/scs-manager/)
      $privateFileSystemPath = \Drupal::service('file_system')->realpath("private://") ?? throw new \Exception('Private file system path not found');
      // Get the snapshot path from the settings.
      $snapshotPath = \Drupal::config('soda_scs_manager.settings')->get('snapshotPath') ?? throw new \Exception('Snapshot path not found');
      // Full path to the snapshot directory, e.g. /var/scs-manager/snapshots.
      $snapshotFullPath = $privateFileSystemPath . $snapshotPath;
      // Get the owner of the component.
      $owner = $component->getOwner()->getDisplayName();
      // Get the date.
      $date = date('Y-m-d', $timestamp);
      // Get the machine name of the component.
      $machineName = $component->get('machineName')->value;
      // Get the component backup path, e.g. scs_user/new-wisski-component/2025-08-26/1724732400.
      $relativeComponentBackupPath = '/' . $owner . '/' . $machineName . '/' . $date . '/' . $timestamp;
      // Get the backup path with type, e.g. /var/scs-manager/snapshots/scs_user/new-wisski-component/2025-08-26/1724732400.
      $backupPath = $snapshotFullPath . $relativeComponentBackupPath;
      // Get the backup path with type, e.g. /var/scs-manager/snapshots/scs_user/new-wisski-component/2025-08-26/1724732400/files.
      $backupPathWithType = $snapshotFullPath . $relativeComponentBackupPath . '/' . $type;
      // Get the tar file name, e.g. new-wisski-component--1724732400.files.tar.gz.
      $tarFileName = $snapshotMachineName . '--' . $timestamp . '.' . $type . '.tar.gz';
      // Get the sha256 file name, e.g. new-wisski-component--1724732400.files.tar.gz.sha256.
      $sha256FileName = $tarFileName . '.sha256';
      // Get the relative tar file path, e.g. scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz.
      $relativeTarFilePath = $relativeComponentBackupPath . '/' . $type . '/' . $tarFileName;
      // Get the relative sha256 file path, e.g. scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz.sha256.
      $relativeSha256FilePath = $relativeComponentBackupPath . '/' . $sha256FileName;
      // Absolute tar file path, e.g. /var/scs-manager/snapshots/scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz.
      $absoluteTarFilePath = $snapshotFullPath . $relativeTarFilePath;
      // Absolute sha256 file path, e.g. /var/scs-manager/snapshots/scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz.sha256.
      $absoluteSha256FilePath = $snapshotFullPath . $relativeSha256FilePath;


      return [
        'absoluteSha256FilePath' => $absoluteSha256FilePath,
        'absoluteTarFilePath' => $absoluteTarFilePath,
        'backupPath' => $backupPath,
        'backupPathWithType' => $backupPathWithType,
        'relativeComponentBackupPath' => $relativeComponentBackupPath,
        'relativeSha256FilePath' => $relativeSha256FilePath,
        'relativeTarFilePath' => $relativeTarFilePath,
        'sha256FileName' => $sha256FileName,
        'tarFileName' => $tarFileName,
        'type' => $type,
      ];
    }

  /**
   * Converts a human-readable label to a clean machine name.
   *
   * This function handles:
   * - Transliteration of accented characters (ä → a, é → e, etc.)
   * - Conversion of special characters (* → _, ! → _, etc.)
   * - Lowercase conversion
   * - Removal of duplicate underscores
   *
   * @param string $label
   *   The human-readable label to convert.
   * @param string $replacement
   *   The character to replace invalid characters with. Defaults to '_'.
   * @param int $maxLength
   *   Maximum length of the machine name. Defaults to 32.
   *
   * @return string
   *   The cleaned machine name.
   *
   * @example
   *   cleanMachineName('Café & Bücher *Test*') returns 'cafe_bucher_test'
   */
  public static function cleanMachineName(string $label, string $replacement = '_', int $maxLength = 32): string {
    // Get the transliteration service.
    $transliteration = \Drupal::service('transliteration');

    // Step 1: Transliterate accented characters (ä → a, é → e, etc.).
    $machineName = $transliteration->transliterate($label, LanguageInterface::LANGCODE_DEFAULT, $replacement);

    // Step 2: Convert to lowercase.
    $machineName = strtolower($machineName);

    // Step 3: Replace any remaining special characters with replacement character.
    // This pattern matches anything that's not a lowercase letter, number, or underscore.
    $machineName = preg_replace('/[^a-z0-9_]+/', $replacement, $machineName);

    // Step 4: Remove duplicate replacement characters.
    $machineName = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $machineName);

    // Step 5: Trim replacement characters from beginning and end.
    $machineName = trim($machineName, $replacement);

    // Step 6: Ensure it doesn't start with a number (machine names should start with a letter).
    $machineName = preg_replace('/^[0-9]+/', '', $machineName);

    // Step 7: Limit length and trim any trailing replacement characters.
    if (strlen($machineName) > $maxLength) {
      $machineName = substr($machineName, 0, $maxLength);
      $machineName = rtrim($machineName, $replacement);
    }

    // Step 8: Ensure we have at least something (fallback).
    if (empty($machineName)) {
      $machineName = 'untitled';
    }

    return $machineName;
  }

  /**
   * Create bag of files.
   *
   * Get the files in a snapshot directory, tar it, sign the tar file and create a manifest.
   *
   * @param array $snapshotData
   *   The snapshot data.
   *
   */
  public function createBagOfFiles($snapshotData) {
  try {
    $contentFiles = [];
    foreach ($snapshotData as $componentBundle => $componentData) {
      foreach ($componentData['metadata']['contentFileNames'] as $fileType => $contentFileName) {
        $contentFiles[$componentBundle][$fileType] = $contentFileName;
      }
    }

    $snapshotMachineName = reset($snapshotData)['metadata']['snapshotMachineName'];
    $timestamp = reset($snapshotData)['metadata']['timestamp'];

  // Create the bag directory.
      $bagPath = reset($snapshotData)['metadata']['backupPath'] . '/bag';
      $dirCreateResult = $this->createDir($bagPath);
      if (!$dirCreateResult['success']) {
        return SodaScsResult::failure(
          error: $dirCreateResult['error'],
          message: 'Snapshot creation failed: Could not create bag directory.',
        );
      }


  // Create the tar file names.
  $contentsTarFileName = $snapshotMachineName . '--' . $timestamp . '.contents.tar.gz';
  $contentsSha256FileName = $snapshotMachineName . '--' . $timestamp . '.contents.tar.gz.sha256';
  $manifestFileName = $snapshotMachineName . '--' . $timestamp . '.manifest.json';

  // Create the bag files array.
  $bagFiles = [
      $contentsTarFileName,
      $contentsSha256FileName,
      $manifestFileName,
  ];

  $mappings = [];
  foreach ($snapshotData as $componentData) {
    $mappings[] = [
      "eid" => $componentData['metadata']['componentId'],
      "bundle" => $componentData['metadata']['componentBundle'],
      "machineName" => $componentData['metadata']['componentMachineName'],
      "dumpFile" => $componentData['metadata']['contentFilePaths']['tarFilePath'],
    ];
  }

  // Create the manifest.
  $manifest = json_encode([
    "version" => "1.0",
    "algorithm" => "sha256",
    "created" => $timestamp,
    "snapshotMachineName" => $snapshotMachineName,
    "files" => [
      "contentFiles" =>  $contentFiles,
      "bagFiles" => $bagFiles,
      ],
    "mapping" => $mappings,
    ],
    JSON_PRETTY_PRINT
  );

  // Create the content files string, to get i.e. ../scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz
    $contentFilesString = '';
    foreach ($contentFiles as $bundle => $fileTypes) {

      foreach ($fileTypes as $fileType => $contentFileName) {
        switch ($bundle) {
        case 'soda_scs_filesystem_component':
          $type = 'files';
          break;
        case 'soda_scs_wisski_component':
          $type = 'files';
          break;
        case 'soda_scs_sql_component':
          $type = 'sql';
          break;
        case 'soda_scs_triple_store_component':
          $type = 'nq';
          break;
        default:
          throw new \Exception('Invalid component bundle: ' . $bundle);
      }
        $contentFilesString .= '../' . $type. '/' . $contentFileName . ' ';
      }
    }

  $manifest = '"'. $manifest . '"';
  // Create the request params.
  $requestParams = [
    'name' => $snapshotMachineName . '--bag',
    'volumes' => NULL,
    'image' => 'alpine:latest',
    'user' => '33:33',
    'cmd' => [
      'sh',
      '-c',
        'cd /backup/bag && ' .
        'echo ' . $manifest . ' > ' . $manifestFileName . ' && ' .
        'tar czf ' . $contentsTarFileName . ' -C ' . $manifestFileName . ' ' . $contentFilesString . ' && ' .
        'sha256sum ' . $contentsTarFileName . ' > ' . $contentsSha256FileName,
    ],
    'hostConfig' => [
      'Binds' => [
        reset($snapshotData)['metadata']['backupPath'] . ':/backup',
      ],
      'AutoRemove' => FALSE,
    ],
  ];

  // Make the create container request.
    $createContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($requestParams);
    $createContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createContainerRequest);

    if (!$createContainerResponse['success']) {
      return SodaScsResult::failure(
        error: $createContainerResponse['error'],
        message: 'Snapshot creation failed: Could not create bag container.',
      );
    }

    // Get container ID from response.
    $containerId = json_decode($createContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

    // Make the start container request.
    $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
      'routeParams' => [
        'containerId' => $containerId,
      ],
    ]);
    $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);

    if (!$startContainerResponse['success']) {
      return SodaScsResult::failure(
        error: $startContainerResponse['error'],
        message: 'Snapshot creation failed: Could not start bag container.',
      );
    }

    return SodaScsResult::success(
      data: [
        'startContainerResponse' => $startContainerResponse,
        'createContainerResponse' => $createContainerResponse,
        'metadata' => [
          'contentsTarFileName' => $contentsTarFileName,
          'contentsSha256FileName' => $contentsSha256FileName,
          'contentsTarFilePath' => $bagPath . '/' . $contentsTarFileName,
          'contentsSha256FilePath' => $bagPath . '/' . $contentsSha256FileName,
          'manifestFileName' => $manifestFileName,
          'containerId' => $containerId,
        ],
      ],
      message: 'Created and started bag container successfully.',
    );
  }
  catch (\Exception $e) {
    Error::logException(
      $this->logger,
      $e,
      'Snapshot creation failed: @message',
      ['@message' => $e->getMessage()],
      LogLevel::ERROR
    );
    return SodaScsResult::failure(
      error: $e->getMessage(),
      message: 'Snapshot creation failed.',
    );
  }
}

  /**
   * Create FS dir via access-proxy container.
   *
   * @param string $path
   *   The path to create.
   *
   * @return array{
   *   message: string,
   *   success: bool,
   *   error: string,
   *   data: array,
   *   statusCode: int,
   *   }
   *   The result of the operation.
   */
  public function createDir(string $path) {
    try {
      $dirCreateExecRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest([
        'containerName' => 'access-proxy',
        'user' => '33',
        'cmd' => [
          'mkdir',
          '-p',
          $path,
        ],
      ]);

      $dirCreateExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($dirCreateExecRequest);

      if (!$dirCreateExecResponse['success']) {
        return $dirCreateExecResponse;
      }

      $dirCreateExecId = json_decode($dirCreateExecResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      $dirCreateStartExecRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest([
        'execId' => $dirCreateExecId,
      ]);

      $dirCreateStartExecResponse = $this->sodaScsDockerExecServiceActions->makeRequest($dirCreateStartExecRequest);

      if (!$dirCreateStartExecResponse['success']) {
        return $dirCreateStartExecResponse;
      }

      return [
        'message' => $this->t("Directory created successfully."),
        'success' => TRUE,
        'error' => '',
        'data' => [],
        'statusCode' => 200,
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Failed to create directory: @error", ['@error' => $e->getMessage()]),
        'success' => FALSE,
        'error' => $e->getMessage(),
        'data' => $e,
        'statusCode' => $e->getCode(),
      ];
    }
  }

}
