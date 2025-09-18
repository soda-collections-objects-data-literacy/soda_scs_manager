<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerExecServiceActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LogLevel;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Component\Transliteration\TransliterationInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Entity\EntityTypeManagerInterface;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The transliteration service.
   *
   * @var \Drupal\Component\Transliteration\TransliterationInterface
   */
  protected $transliteration;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public function __construct(
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsDockerExecServiceActions $sodaScsDockerExecServiceActions,
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
    LoggerChannelFactoryInterface $loggerFactory,
    FileSystemInterface $fileSystem,
    ConfigFactoryInterface $configFactory,
    TransliterationInterface $transliteration,
    RequestStack $requestStack,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->fileSystem = $fileSystem;
    $this->configFactory = $configFactory;
    $this->transliteration = $transliteration;
    $this->requestStack = $requestStack;
    $this->entityTypeManager = $entityTypeManager;
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
        $type = 'drupal-data';
        break;

      case 'soda_scs_sql_component':
        $type = 'sql';
        break;

      case 'soda_scs_triplestore_component':
        $type = 'nq';
        break;

      case 'soda_scs_wisski_stack':
        $type = 'wisski-stack';
        break;

      default:
        $type = $bundle;
    }

    // Get the private file system path from Drupal's
    // file system settings. (/var/scs-manager/)
    $privateFileSystemPath = $this->fileSystem->realpath("private://") ?? throw new \Exception('Private file system path not found');
    // Get the snapshot path from the settings.
    $snapshotPath = $this->configFactory->get('soda_scs_manager.settings')->get('snapshotPath') ?? throw new \Exception('Snapshot path not found');
    // Full path to the snapshot directory, e.g. /var/scs-manager/snapshots.
    $snapshotFullPath = $privateFileSystemPath . $snapshotPath;
    // Get the owner of the component.
    $owner = $component->getOwner()->getDisplayName();
    // Get the date.
    $date = date('Y-m-d', $timestamp);
    // Get the component backup path,
    // e.g. scs_user/new-snapshot/2025-08-26/1724732400.
    $relativeSnapshotBackupPath = '/' . $owner . '/' . $snapshotMachineName . '/' . $date . '/' . $timestamp;
    // Get the backup path with type, e.g.
    // /var/scs-manager/snapshots/scs_user/new-snapshot/2025-08-26/1724732400.
    $backupPath = $snapshotFullPath . $relativeSnapshotBackupPath;
    // Get the backup path with type e.g.
    // /var/scs-manager/snapshots/scs_user/new-snapshot/2025-08-26/1724732400/files.
    $backupPathWithType = $snapshotFullPath . $relativeSnapshotBackupPath . '/' . $type;
    // Get the tar file name,
    // e.g. new-snapshot--1724732400.files.tar.gz.
    $tarFileName = $snapshotMachineName . '--' . $timestamp . '--' . $type . '.tar.gz';
    // Get the sha256 file name,
    // e.g. new-snapshot--1724732400.files.tar.gz.sha256.
    $sha256FileName = $tarFileName . '.sha256';
    // Get the relative tar file path, e.g. scs_user/new-snapshot/2025-08-26/1724732400/files/new-snapshot--1724732400.files.tar.gz.
    $relativeTarFilePath = $relativeSnapshotBackupPath . '/' . $type . '/' . $tarFileName;
    // Get the relative sha256 file path, e.g.
    // scs_user/new-snapshot/2025-08-26/1724732400/files/new-snapshot--1724732400.files.tar.gz.sha256.
    $relativeSha256FilePath = $relativeSnapshotBackupPath . '/' . $type . '/' . $sha256FileName;
    // Absolute tar file path, e.g.
    // /var/scs-manager/snapshots/scs_user/new-snapshot/2025-08-26/1724732400/files/new-snapshot--1724732400.files.tar.gz.
    $absoluteTarFilePath = $snapshotFullPath . $relativeTarFilePath;
    // Absolute sha256 file path, e.g.
    // /var/scs-manager/snapshots/scs_user/new-snapshot/2025-08-26/1724732400/files/new-snapshot--1724732400.files.tar.gz.sha256.
    $absoluteSha256FilePath = $snapshotFullPath . $relativeSha256FilePath;

    // URL relative backup path.
    // e.g. /system/files/snapshots/rnsrk/new-snapshot/2025-08-26/1724732400.
    $relativeUrlBackupPath = '/system/files' . $snapshotPath . '/' . $owner . '/' . $snapshotMachineName . '/' . $date . '/' . $timestamp;

    return [
      'absoluteSha256FilePath' => $absoluteSha256FilePath,
      'absoluteTarFilePath' => $absoluteTarFilePath,
      'backupPath' => $backupPath,
      'backupPathWithType' => $backupPathWithType,
      'relativeSnapshotBackupPath' => $relativeSnapshotBackupPath,
      'relativeSha256FilePath' => $relativeSha256FilePath,
      'relativeTarFilePath' => $relativeTarFilePath,
      'relativeUrlBackupPath' => $relativeUrlBackupPath,
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
   * - Removal of duplicate underscores.
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
  public function cleanMachineName(string $label, string $replacement = '_', int $maxLength = 32): string {
    // Step 1: Transliterate accented characters (ä → a, é → e, etc.).
    $machineName = $this->transliteration->transliterate($label, LanguageInterface::LANGCODE_DEFAULT, $replacement);

    // Step 2: Convert to lowercase.
    $machineName = strtolower($machineName);

    // Step 3: Replace any remaining special characters with replacement
    // character.
    // This pattern matches anything that's not a lowercase letter,
    // number, or underscore.
    $machineName = preg_replace('/[^a-z0-9_]+/', $replacement, $machineName);

    // Step 4: Remove duplicate replacement characters.
    $machineName = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $machineName);

    // Step 5: Trim replacement characters from beginning and end.
    $machineName = trim($machineName, $replacement);

    // Step 6: Ensure it doesn't start with a number
    // (machine names should start with a letter).
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
   * Get the files in a snapshot directory, tar it,
   * sign the tar file and create a manifest.
   *
   * @param array $snapshotData
   *   The snapshot data.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot entity.
   */
  public function createBagOfFiles($snapshotData, $snapshot) {
    try {
      // @todo This is redundant, should be abstracted.
      $contentFiles = [];
      foreach ($snapshotData as $componentBundle => $componentData) {
        switch ($componentBundle) {
          case 'soda_scs_filesystem_component':
            $type = 'files';
            break;

          case 'soda_scs_wisski_component':
            $type = 'drupal-data';
            break;

          case 'soda_scs_sql_component':
            $type = 'sql';
            break;

          case 'soda_scs_triplestore_component':
            $type = 'nq';
            break;

          case 'soda_scs_wisski_stack':
            $type = 'wisski-stack';
            break;

          default:
            $type = $componentBundle;
        }
        foreach ($componentData->metadata['contentFileNames'] as $fileType => $contentFileName) {
          $contentFiles[$type][$fileType] = (string) $contentFileName;
        }
      }

      $snapshotMachineName = reset($snapshotData)->metadata['snapshotMachineName'];
      $timestamp = reset($snapshotData)->metadata['timestamp'];

      // Create the bag directory.
      $bagPath = reset($snapshotData)->metadata['backupPath'] . '/bag';
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
      $manifestFileName = 'manifest.json';

      // Create the bag files array.
      $currentRequest = $this->requestStack->getCurrentRequest();
      $schemeAndHost = $currentRequest ? str_replace('http://', 'https://', $currentRequest->getSchemeAndHttpHost()) : '';

      $relativeUrlBackupPath = reset($snapshotData)->metadata['relativeUrlBackupPath'] ?? '';
      $bagUrl = $schemeAndHost . $relativeUrlBackupPath . '/bag/' . $contentsTarFileName;
      $checksumUrl = $schemeAndHost . $relativeUrlBackupPath . '/bag/' . $contentsSha256FileName;
      $bagFiles = [
        // @todo make this from variable.
        'bagFile' => (string) $bagUrl,
        'checksumFile' => (string) $checksumUrl,
        'manifest' => (string) $manifestFileName,
      ];

      $mappings = [];
      foreach ($snapshotData as $componentData) {
        $mappings[] = [
          'bundle' => (string) $componentData->componentBundle,
          'eid' => (string) $componentData->componentId,
          'machineName' => (string) $componentData->componentMachineName,
          'dumpFile' => (string) $componentData->metadata['contentFilePaths']['tarFilePath'],
          'checksumFile' => (string) $componentData->metadata['contentFilePaths']['sha256FilePath'],
        ];
      }

      // Create the manifest.
      $manifestArray = [
        'version' => '1.0',
        'algorithm' => 'sha256',
        'created' => (int) $timestamp,
        'snapshotMachineName' => (string) $snapshotMachineName,
        // @todo make this agnostic to the domain.
        'snapshot' => 'https://scs.sammlungen.io/soda-scs-manager/snapshot/' . $snapshot->id(),
        'files' => [
          'contentFiles' => $contentFiles,
          'bagFiles' => $bagFiles,
        ],
        'mapping' => $mappings,
      ];
      $manifest = json_encode($manifestArray, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

      // Create the content files string,
      // to get i.e. ../scs_user/new-wisski-component/2025-08-26/1724732400/files/new-wisski-component--1724732400.files.tar.gz.
      $contentFilesString = '';
      foreach ($contentFiles as $bundle => $fileTypes) {
        // @todo This is redundant, should be abstracted.
        foreach ($fileTypes as $fileType => $contentFileName) {
          switch ($bundle) {
            case 'soda_scs_filesystem_component':
              $type = 'files';
              break;

            case 'soda_scs_wisski_component':
              $type = 'drupal-data';
              break;

            case 'soda_scs_sql_component':
              $type = 'sql';
              break;

            case 'soda_scs_triplestore_component':
              $type = 'nq';
              break;

            case 'soda_scs_wisski_stack':
              $type = 'wisski-stack';
              break;

            default:
              $type = $bundle;
          }
          $contentFilesString .= '../' . $type . '/' . $contentFileName . ' ';
        }
      }

      // Write manifest.json to the bag dir; avoid shell echo.
      $backupPath = reset($snapshotData)->metadata['backupPath'];
      $manifestWriteResult = $this->writeFileContent($bagPath . '/' . $manifestFileName, $manifest);
      if (!$manifestWriteResult['success']) {
        return SodaScsResult::failure(
          error: $manifestWriteResult['error'],
          message: 'Snapshot creation failed: Could not write manifest.json.',
        );
      }
      // Create the request params.
      $requestParams = [
        'name' => 'snapshot--' . $this->generateRandomSuffix() . '--' . $snapshotMachineName . '--bag',
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => '33:33',
        'cmd' => [
          'sh',
          '-c',
          'cd /backup/bag && ' .
          'tar czf ' . $contentsTarFileName . ' ' . $manifestFileName . ' ' . $contentFilesString . ' && ' .
          'sha256sum ' . $contentsTarFileName . ' > ' . $contentsSha256FileName,
        ],
        'hostConfig' => [
          'Binds' => [
            $backupPath . ':/backup',
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

      $relativeBagPath = str_replace($this->fileSystem->realpath("private://"), '', $bagPath);

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
            'relativeSha256FilePath' => $relativeBagPath . '/' . $contentsSha256FileName,
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
   * Adjusts the maxAttempts based on sleep interval.
   *
   * @param int $sleepInterval
   *   The sleep interval in seconds.
   *
   * @return int|false
   *   Returns the calculated maxAttempts, or FALSE if the timeout is too low.
   */
  public function adjustMaxAttempts($sleepInterval = 5) {
    // Read the global PHP request timeout setting from the server.
    $phpRequestTimeout = ini_get('max_execution_time');
    // If max_execution_time is 0, it means unlimited,
    // so return a default value.
    if ((int) $phpRequestTimeout === 0) {
      return 18;
    }
    if ((int) $phpRequestTimeout < $sleepInterval) {
      // Show error and return FALSE if timeout is too low.
      return FALSE;
    }
    // Calculate maxAttempts as the number of
    // sleep intervals that fit in the timeout.
    return (int) floor((int) $phpRequestTimeout / $sleepInterval);
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

      // Check if we have any data at all.
      if (empty($sparqlJsonData)) {
        return [
          'success' => FALSE,
          'error' => 'Empty SPARQL response received from triplestore',
        ];
      }

      // Parse the SPARQL JSON data.
      $sparqlResults = json_decode($sparqlJsonData, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        // Log more details about the invalid JSON.
        $this->logger->error('JSON decode error: @error. Raw data (first 1000 chars): @data', [
          '@error' => json_last_error_msg(),
          '@data' => substr($sparqlJsonData, 0, 1000),
        ]);

        return [
          'success' => FALSE,
          'error' => 'Invalid JSON data: ' . json_last_error_msg() . '. Raw response: ' . substr($sparqlJsonData, 0, 200),
        ];
      }

      if (!isset($sparqlResults['results']['bindings'])) {
        return [
          'success' => FALSE,
          'error' => 'Invalid SPARQL JSON format: missing results.bindings',
        ];
      }

      $nquads = [];
      $quadsCount = 0;

      // Process each binding (quad: subject, predicate, object, graph).
      foreach ($sparqlResults['results']['bindings'] as $binding) {
        if (!isset($binding['s'], $binding['p'], $binding['o'])) {
          // Skip incomplete triples.
          continue;
        }

        $subject = $this->formatRdfTerm($binding['s']);
        $predicate = $this->formatRdfTerm($binding['p']);
        $object = $this->formatRdfTerm($binding['o']);

        // Get the graph/context (4th element for N-Quads).
        $graph = isset($binding['g']) ? $this->formatRdfTerm($binding['g']) : NULL;

        if ($subject && $predicate && $object && $graph) {
          // N-Quads format: <subject> <predicate> <object> <graph> .
          $nquads[] = "$subject $predicate $object $graph .";
          $quadsCount++;
        }
      }

      // Create the N-Quads file.
      $fileName = $component->get('machineName')->value . '--' . (string) $timestamp . '.nq';
      $filePath = $backupPath . '/' . $fileName;

      // Write N-Quads to file using the createFile helper.
      // Join statements with actual newlines
      // (newlines within literal strings are already encoded by formatRdfTerm).
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
        'quads_count' => $quadsCount,
      ];

    }
    catch (\Exception $e) {
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
        // Properly escape literal values for N-Quads format.
        $escapedValue = $term['value'];
        // Escape backslashes first (must be done before other escapes).
        $escapedValue = str_replace('\\', '\\\\', $escapedValue);
        // Escape quotes.
        $escapedValue = str_replace('"', '\\"', $escapedValue);
        // Escape newlines as \n.
        $escapedValue = str_replace("\n", '\\n', $escapedValue);
        // Escape carriage returns as \r.
        $escapedValue = str_replace("\r", '\\r', $escapedValue);
        // Escape tabs as \t.
        $escapedValue = str_replace("\t", '\\t', $escapedValue);

        $value = '"' . $escapedValue . '"';

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

      // First, ensure the directory exists using native PHP.
      $directory = dirname($filePath);
      if (!is_dir($directory)) {
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

  /**
   * Generate a random suffix.
   *
   * @return string
   *   The random suffix.
   */
  public function generateRandomSuffix() {
    return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
  }

  /**
   * Validate the checksum of the snapshot file.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot entity.
   * @param string $filePath
   *   The file path to validate.
   *
   * @return array
   *   Array with success status and error message if validation fails.
   */
  public function validateSnapshotChecksum($snapshot, string $filePath): array {
    /** @var \Drupal\file\Entity\File $checksumFile */
    $checksumFile = $this->entityTypeManager->getStorage('file')->load($snapshot->get('checksumFile')->target_id);
    if (!$checksumFile) {
      return ['success' => TRUE];
    }

    $checksumUri = $checksumFile->getFileUri();
    $checksumPath = $this->fileSystem->realpath($checksumUri);

    if (!$checksumPath || !file_exists($checksumPath)) {
      return ['success' => TRUE];
    }

    $expectedChecksum = trim(file_get_contents($checksumPath));
    $actualChecksum = hash_file('sha256', $filePath);

    if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
      Error::logException(
        $this->logger,
        new \Exception('Checksum mismatch'),
        'Snapshot restore failed: Checksum verification failed for snapshot @id. The file may be corrupted.',
        ['@id' => $snapshot->id()],
        LogLevel::ERROR
      );
      return [
        'success' => FALSE,
        'error' => 'Checksum verification failed. The file may be corrupted.',
      ];
    }

    return ['success' => TRUE];
  }

  /**
   * Create a safe temporary directory for unpacking the snapshot.
   *
   * @return string|false
   *   The temporary directory path or FALSE on failure.
   */
  public function createTemporaryDirectory(): string|false {
    $tempBase = sys_get_temp_dir();
    $tempDir = $tempBase . '/soda_scs_restore_' . uniqid();

    if (!mkdir($tempDir, 0755, TRUE)) {
      return FALSE;
    }

    return $tempDir;
  }

  /**
   * Unpack the snapshot tar file to the temporary directory.
   *
   * @param string $filePath
   *   The snapshot tar file path.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return array
   *   Array with success status and error message if unpacking fails.
   */
  public function unpackSnapshotToTempDirectory(string $filePath, string $tempDir): array {
    try {
      $command = sprintf('cd %s && tar -xzf %s', escapeshellarg($tempDir), escapeshellarg($filePath));
      $output = [];
      $returnCode = 0;
      exec($command, $output, $returnCode);

      if ($returnCode !== 0) {
        return [
          'success' => FALSE,
          'error' => 'Failed to extract tar file: ' . implode("\n", $output),
        ];
      }

      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      return [
        'success' => FALSE,
        'error' => 'Exception during extraction: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Parse and validate the manifest.json file.
   *
   * @param string $manifestPath
   *   The path to the manifest.json file.
   *
   * @return array
   *   Array with success status, data, and error message if parsing fails.
   */
  public function parseAndValidateManifest(string $manifestPath): array {
    if (!file_exists($manifestPath)) {
      return [
        'success' => FALSE,
        'error' => 'Manifest file not found in snapshot.',
      ];
    }

    $manifestContent = file_get_contents($manifestPath);
    if ($manifestContent === FALSE) {
      return [
        'success' => FALSE,
        'error' => 'Failed to read manifest file.',
      ];
    }

    $manifestData = json_decode($manifestContent, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      return [
        'success' => FALSE,
        'error' => 'Invalid JSON in manifest file: ' . json_last_error_msg(),
      ];
    }

    // Validate required manifest structure.
    $requiredFields = ['version', 'algorithm', 'created', 'snapshotMachineName', 'files', 'mapping'];
    foreach ($requiredFields as $field) {
      if (!isset($manifestData[$field])) {
        return [
          'success' => FALSE,
          'error' => "Missing required field '{$field}' in manifest.",
        ];
      }
    }

    // Validate version.
    if ($manifestData['version'] !== '1.0') {
      return [
        'success' => FALSE,
        'error' => 'Unsupported manifest version: ' . $manifestData['version'],
      ];
    }

    // Validate algorithm.
    if ($manifestData['algorithm'] !== 'sha256') {
      return [
        'success' => FALSE,
        'error' => 'Unsupported checksum algorithm: ' . $manifestData['algorithm'],
      ];
    }

    return [
      'success' => TRUE,
      'data' => $manifestData,
    ];
  }

  /**
   * Handle bag files restoration if present.
   *
   * @param array $bagFiles
   *   The bag files configuration from manifest.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return array
   *   Array with success status and restoration data.
   */
  public function handleBagFilesRestoration(array $bagFiles, string $tempDir): array {
    try {
      // If bagFile is a URL, download it first.
      if (isset($bagFiles['bagFile']) && filter_var($bagFiles['bagFile'], FILTER_VALIDATE_URL)) {
        $bagFilePath = $this->downloadBagFile($bagFiles['bagFile'], $tempDir);
        if (!$bagFilePath) {
          return [
            'success' => FALSE,
            'error' => 'Failed to download bag file.',
          ];
        }
      }
      else {
        $bagFilePath = $tempDir . '/' . basename($bagFiles['bagFile']);
      }

      // Validate bag file checksum if provided.
      if (isset($bagFiles['checksumFile'])) {
        $checksumValidation = $this->validateBagFileChecksum($bagFiles['checksumFile'], $bagFilePath, $tempDir);
        if (!$checksumValidation['success']) {
          return $checksumValidation;
        }
      }

      // Extract bag file if it exists.
      if (file_exists($bagFilePath)) {
        $extractResult = $this->extractBagFile($bagFilePath, $tempDir);
        if (!$extractResult['success']) {
          return $extractResult;
        }
      }

      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      return [
        'success' => FALSE,
        'error' => 'Exception during bag files handling: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Download a bag file from URL.
   *
   * @param string $url
   *   The bag file URL.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return string|false
   *   The local file path or FALSE on failure.
   */
  private function downloadBagFile(string $url, string $tempDir): string|false {
    $localPath = $tempDir . '/' . basename($url);

    try {
      $content = file_get_contents($url);
      if ($content === FALSE) {
        return FALSE;
      }

      if (file_put_contents($localPath, $content) === FALSE) {
        return FALSE;
      }

      return $localPath;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Validate bag file checksum.
   *
   * @param string $checksumUrl
   *   The checksum file URL or path.
   * @param string $bagFilePath
   *   The bag file path.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return array
   *   Array with success status and error message if validation fails.
   */
  private function validateBagFileChecksum(string $checksumUrl, string $bagFilePath, string $tempDir): array {
    // Download checksum file if it's a URL.
    if (filter_var($checksumUrl, FILTER_VALIDATE_URL)) {
      $checksumContent = file_get_contents($checksumUrl);
      if ($checksumContent === FALSE) {
        return [
          'success' => FALSE,
          'error' => 'Failed to download checksum file.',
        ];
      }
    }
    else {
      $checksumPath = $tempDir . '/' . basename($checksumUrl);
      if (!file_exists($checksumPath)) {
        return ['success' => TRUE];
      }
      $checksumContent = file_get_contents($checksumPath);
    }

    $expectedChecksum = trim($checksumContent);
    $actualChecksum = hash_file('sha256', $bagFilePath);

    if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
      return [
        'success' => FALSE,
        'error' => 'Bag file checksum validation failed.',
      ];
    }

    return ['success' => TRUE];
  }

  /**
   * Extract bag file.
   *
   * @param string $bagFilePath
   *   The bag file path.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return array
   *   Array with success status and error message if extraction fails.
   */
  private function extractBagFile(string $bagFilePath, string $tempDir): array {
    try {
      $bagExtractDir = $tempDir . '/bag_contents';
      if (!mkdir($bagExtractDir, 0755, TRUE)) {
        return [
          'success' => FALSE,
          'error' => 'Failed to create bag extraction directory.',
        ];
      }

      $command = sprintf('cd %s && tar -xzf %s', escapeshellarg($bagExtractDir), escapeshellarg($bagFilePath));
      $output = [];
      $returnCode = 0;
      exec($command, $output, $returnCode);

      if ($returnCode !== 0) {
        return [
          'success' => FALSE,
          'error' => 'Failed to extract bag file: ' . implode("\n", $output),
        ];
      }

      return ['success' => TRUE];
    }
    catch (\Exception $e) {
      return [
        'success' => FALSE,
        'error' => 'Exception during bag file extraction: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Clean up the temporary directory.
   *
   * @param string $tempDir
   *   The temporary directory path to clean up.
   */
  public function cleanupTemporaryDirectory(string $tempDir): void {
    if (is_dir($tempDir)) {
      try {
        $iterator = new \RecursiveIteratorIterator(
          new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
          \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
          if ($file->isDir()) {
            rmdir($file->getRealPath());
          }
          else {
            unlink($file->getRealPath());
          }
        }
        rmdir($tempDir);
      }
      catch (\Exception $e) {
        Error::logException(
          $this->logger,
          $e,
          'Failed to cleanup temporary directory: @message',
          ['@message' => $e->getMessage()],
          LogLevel::WARNING
        );
      }
    }
  }

}
