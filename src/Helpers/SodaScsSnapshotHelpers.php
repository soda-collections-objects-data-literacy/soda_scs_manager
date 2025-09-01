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
use Drupal\Core\Messenger\MessengerTrait;

/**
 * Helper class for snapshot operations.
 */
class SodaScsSnapshotHelpers {

  use StringTranslationTrait;
  use MessengerTrait;

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
        case 'soda_scs_triplestore_component':
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
      switch ($componentBundle) {
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
          throw new \Exception('Invalid component bundle: ' . $componentBundle);
      }
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
        'tar czf ' . $contentsTarFileName . ' ' . $manifestFileName . ' ' . $contentFilesString . ' && ' .
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

    $relativeBagPath = str_replace(\Drupal::service('file_system')->realpath("private://"), '', $bagPath);

    return SodaScsResult::success(
      data: [
        'startContainerResponse' => $startContainerResponse,
        'createContainerResponse' => $createContainerResponse,
        'metadata' => [
          'contentsTarFileName' => $contentsTarFileName,
          'contentsSha256FileName' => $contentsSha256FileName,
          'contentsTarFilePath' => $bagPath . '/' . $contentsTarFileName,
          'contentsSha256FilePath' => $bagPath . '/' . $contentsSha256FileName,
          'relativeTarFilePath' => $relativeBagPath . '/' . $contentsTarFileName,
          'relativeSha256FilePath' => $relativeBagPath . '/' .$contentsSha256FileName,
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
   * Create directory using native PHP operations.
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
      // Check if directory already exists.
      if (is_dir($path)) {
        return [
          'message' => $this->t("Directory already exists."),
          'success' => TRUE,
          'error' => '',
          'data' => ['path' => $path],
          'statusCode' => 200,
        ];
      }

      // Create the directory with proper permissions.
      if (!mkdir($path, 0755, TRUE)) {
        $error = 'Failed to create directory: ' . $path;
        return [
          'message' => $this->t("Failed to create directory: @path", ['@path' => $path]),
          'success' => FALSE,
          'error' => $error,
          'data' => [],
          'statusCode' => 500,
        ];
      }

      return [
        'message' => $this->t("Directory created successfully."),
        'success' => TRUE,
        'error' => '',
        'data' => ['path' => $path],
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


  /**
   * Adjusts the maxAttempts based on the PHP request timeout and sleep interval.
   *
   * @param int|string $phpRequestTimeout
   *   The PHP max_execution_time value.
   * @param int $sleepInterval
   *   The sleep interval in seconds.
   *
   * @return int|false
   *   Returns the calculated maxAttempts, or FALSE if the timeout is too low.
   */
  public function adjustMaxAttempts($sleepInterval = 5) {
    // Read the global PHP request timeout setting from the server.
    $phpRequestTimeout = ini_get('max_execution_time');
    // If max_execution_time is 0, it means unlimited, so return a default value.
    if ((int)$phpRequestTimeout === 0) {
      return 18;
    }
    if ((int)$phpRequestTimeout < $sleepInterval) {
      // Show error and return FALSE if timeout is too low.
      return FALSE;
    }
    // Calculate maxAttempts as the number of sleep intervals that fit in the timeout.
    return (int) floor((int)$phpRequestTimeout / $sleepInterval);
  }

  /**
   * Transform SPARQL JSON results to N-Quads format and save to filesystem.
   *
   * @param string $sparqlJsonData
   *   The SPARQL JSON results as string.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component entity.
   * @param string $backupPath
   *   The backup directory path.
   * @param int $timestamp
   *   The timestamp.
   *
   * @return array
   *   Result array with success status and file information.
   */
  public function transformSparqlJsonToNquads($sparqlJsonData, SodaScsComponentInterface $component, $backupPath, int $timestamp) {
    try {
      // Parse the SPARQL JSON data.
      $sparqlResults = json_decode($sparqlJsonData, TRUE);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        return [
          'success' => FALSE,
          'error' => 'Invalid JSON data: ' . json_last_error_msg(),
        ];
      }

      if (!isset($sparqlResults['results']['bindings'])) {
        return [
          'success' => FALSE,
          'error' => 'Invalid SPARQL JSON format: missing results.bindings',
        ];
      }

      $nquads = [];
      $triplesCount = 0;

      // Process each binding (triple).
      foreach ($sparqlResults['results']['bindings'] as $binding) {
        if (!isset($binding['s'], $binding['p'], $binding['o'])) {
          continue; // Skip incomplete triples.
        }

        $subject = $this->formatRdfTerm($binding['s']);
        $predicate = $this->formatRdfTerm($binding['p']);
        $object = $this->formatRdfTerm($binding['o']);

        if ($subject && $predicate && $object) {
          $nquads[] = "$subject $predicate $object .";
          $triplesCount++;
        }
      }

      // Create the N-Quads file.
      $fileName = $component->get('machineName')->value . '--' .(string) $timestamp   . '.nq';
      $filePath = $backupPath . '/' . $fileName;

      // Write N-Quads to file using the createFile helper.
      $nquadsContent = implode("\n", $nquads);
      // Use native file writing.
      $writeResult = $this->writeFileContent($filePath, $nquadsContent);
      
      if (!$writeResult['success']) {
        return [
          'success' => FALSE,
          'error' => 'Failed to write N-Quads file: ' . $writeResult['error'],
        ];
      }

      return [
        'success' => TRUE,
        'file_path' => $filePath,
        'file_name' => $fileName,
        'triples_count' => $triplesCount,
      ];

    } catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'N-Quads transformation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'success' => FALSE,
        'error' => 'Exception during N-Quads conversion: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Format an RDF term for N-Quads serialization.
   *
   * @param array $term
   *   The RDF term from SPARQL JSON binding.
   *
   * @return string|null
   *   The formatted term or NULL if invalid.
   */
  private function formatRdfTerm($term) {
    if (!isset($term['type'], $term['value'])) {
      return NULL;
    }

    switch ($term['type']) {
      case 'uri':
        return '<' . $term['value'] . '>';

      case 'literal':
        $value = '"' . addslashes($term['value']) . '"';
        
        // Add language tag if present.
        if (isset($term['xml:lang'])) {
          $value .= '@' . $term['xml:lang'];
        }
        // Add datatype if present.
        elseif (isset($term['datatype'])) {
          $value .= '^^<' . $term['datatype'] . '>';
        }
        
        return $value;

      case 'bnode':
        return '_:' . $term['value'];

      default:
        return NULL;
    }
  }

  /**
   * Write file content using native PHP file operations.
   *
   * @param string $filePath
   *   The full file path to write to.
   * @param string $content
   *   The content to write.
   *
   * @return array
   *   Result array with success status.
   */
  public function writeFileContent($filePath, $content) {
    try {
      $this->logger->info('Attempting to write file: @path', ['@path' => $filePath]);
      
      // First, ensure the directory exists using native PHP.
      $directory = dirname($filePath);
      if (!is_dir($directory)) {
        $this->logger->info('Creating directory: @dir', ['@dir' => $directory]);
        if (!mkdir($directory, 0755, TRUE)) {
          $error = 'Failed to create directory: ' . $directory;
          $this->logger->error($error);
          return [
            'message' => $this->t("Failed to create directory: @dir", ['@dir' => $directory]),
            'success' => FALSE,
            'error' => $error,
            'data' => [],
            'statusCode' => 500,
          ];
        }
      }

      // Write the file using native PHP file_put_contents.
      $bytesWritten = file_put_contents($filePath, $content, LOCK_EX);
      
      if ($bytesWritten === FALSE) {
        $error = 'Failed to write file content to: ' . $filePath;
        $this->logger->error($error);
        return [
          'message' => $this->t("Failed to write file: @path", ['@path' => $filePath]),
          'success' => FALSE,
          'error' => $error,
          'data' => [],
          'statusCode' => 500,
        ];
      }

      // Verify the file was actually created and has content.
      if (!file_exists($filePath)) {
        $error = 'File was not created: ' . $filePath;
        $this->logger->error($error);
        return [
          'message' => $this->t("File was not created successfully at: @path", ['@path' => $filePath]),
          'success' => FALSE,
          'error' => $error,
          'data' => [],
          'statusCode' => 500,
        ];
      }

      $fileSize = filesize($filePath);
      if ($fileSize === FALSE) {
        $fileSize = 0;
      }

      $this->logger->info('File written and verified successfully: @path (@bytes bytes)', [
        '@path' => $filePath,
        '@bytes' => $bytesWritten,
      ]);

      return [
        'message' => $this->t("File written successfully."),
        'success' => TRUE,
        'error' => '',
        'data' => [
          'file_path' => $filePath,
          'file_size' => $fileSize,
          'bytes_written' => $bytesWritten,
        ],
        'statusCode' => 200,
      ];
    }
    catch (\Exception $e) {
      $this->logger->error('Exception during file write: @message', ['@message' => $e->getMessage()]);
      return [
        'message' => $this->t("Failed to write file: @error", ['@error' => $e->getMessage()]),
        'success' => FALSE,
        'error' => $e->getMessage(),
        'data' => $e,
        'statusCode' => $e->getCode(),
      ];
    }
  }

  /**
   * Verify that a file exists using native PHP file operations.
   *
   * @param string $filePath
   *   The full file path to verify.
   *
   * @return array
   *   Result array with success status and file information.
   */
  public function verifyFileExists($filePath) {
    try {
      if (!file_exists($filePath)) {
        return [
          'message' => $this->t("File not found: @path", ['@path' => $filePath]),
          'success' => FALSE,
          'error' => 'File does not exist',
          'data' => [],
          'statusCode' => 404,
        ];
      }

      if (!is_file($filePath)) {
        return [
          'message' => $this->t("Path is not a file: @path", ['@path' => $filePath]),
          'success' => FALSE,
          'error' => 'Path exists but is not a file',
          'data' => [],
          'statusCode' => 400,
        ];
      }

      $fileSize = filesize($filePath);
      if ($fileSize === FALSE) {
        return [
          'message' => $this->t("Cannot determine file size: @path", ['@path' => $filePath]),
          'success' => FALSE,
          'error' => 'Failed to get file size',
          'data' => [],
          'statusCode' => 500,
        ];
      }

      return [
        'message' => $this->t("File exists and verified."),
        'success' => TRUE,
        'error' => '',
        'data' => [
          'file_path' => $filePath,
          'file_size' => $fileSize,
          'is_readable' => is_readable($filePath),
          'is_writable' => is_writable($filePath),
        ],
        'statusCode' => 200,
      ];
    }
    catch (\Exception $e) {
      return [
        'message' => $this->t("Failed to verify file: @error", ['@error' => $e->getMessage()]),
        'success' => FALSE,
        'error' => $e->getMessage(),
        'data' => $e,
        'statusCode' => $e->getCode(),
      ];
    }
  }

}
