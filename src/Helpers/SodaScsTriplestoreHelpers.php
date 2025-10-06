<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;

/**
 * Helper class for SCS triplestore operations.
 */
class SodaScsTriplestoreHelpers {

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The SCS OpenGDB service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface
   */
  protected SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions;

  /**
   * The SCS Snapshot helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions
   *   The SCS OpenGDB service actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers $sodaScsSnapshotHelpers
   *   The SCS Snapshot helpers.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
  ) {
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
  }

  /**
   * Export triplestore repository.
   *
   * @param string $repositoryId
   *   The id of the repository to export.
   * @param string $filename
   *   The filename of the export, without the format extension.
   * @param string $exportPath
   *   The export path.
   * @param string $format
   *   The format of the export. Defaults to 'nq'.
   * @param int $timestamp
   *   The timestamp of the export. Defaults to the current time.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the export.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsHelpersException
   *   If the export fails.
   */
  public function exportTriplestoreRepository(string $repositoryId, string $filename, string $exportPath, string $format = 'nq', ?int $timestamp = NULL): SodaScsResult {
    // Build the request parameters.
    // Query to get all triples from all named graphs.
    $requestParams = [
      'type' => 'select',
      'queryParams' => [
        'query' => 'SELECT ?s ?p ?o ?g WHERE { GRAPH ?g { ?s ?p ?o } }',
      ],
      'routeParams' => [
        'repositoryId' => $repositoryId,
      ],
      'body' => [],
    ];

    // Construct and do the dump request.
    $dumpRequest = $this->sodaScsOpenGdbServiceActions->buildDumpRequest($requestParams);
    $dumpResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($dumpRequest);

    // @todo This should be a SodaScsResult object.
    if (!$dumpResponse['success']) {
      throw new SodaScsRequestException($dumpResponse['data']['openGdbResponse']);
    }

    // Get the dump data.
    $dumpData = $dumpResponse['data']['openGdbResponse']->getBody()->getContents();

    // Transform the dump data to the requested format.
    switch ($format) {
      case 'nq':
        $result = $this->sodaScsSnapshotHelpers->transformSparqlJsonToNquads($dumpData, $filename, $exportPath);
        break;

      default:
        throw new SodaScsHelpersException(
          message: 'Invalid format. Only N-Quads is supported yet.',
          operationCategory: 'snapshot',
          operation: $format . '_conversion',
          context: [],
          code: 0,
        );
    }
    if (!$result['success']) {
      throw new SodaScsHelpersException(
        message: $result['error'],
        operationCategory: 'snapshot',
        operation: $format . '_conversion',
        context: [],
        code: 0,
      );
    }
    return SodaScsResult::success(
      message: 'Repository exported successfully.',
      data: [
        'repositoryId' => $repositoryId,
        'filePath' => $result['file_path'],
        'fileName' => $result['file_name'],
        'quadsCount' => $result['quads_count'],
      ],
    );
  }

  /**
   * Import triplestore repository.
   *
   * @param string $repositoryId
   *   The id of the repository to import.
   * @param string $importFilename
   *   The full path to the import file.
   * @param string $format
   *   The format of the import. Defaults to 'nq'.
   * @param int $timestamp
   *   The timestamp of the import. Defaults to the current time.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the import.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsHelpersException
   *   If the import fails.
   */
  public function importTriplestoreRepository(string $repositoryId, string $importFilename, string $format = 'nq', ?int $timestamp = NULL): SodaScsResult {
    // Read the file content to send as body.
    if (!file_exists($importFilename)) {
      throw new SodaScsHelpersException(
        message: 'Import file does not exist: ' . $importFilename,
        operationCategory: 'snapshot',
        operation: 'import_file_read',
        context: ['file' => $importFilename],
        code: 0,
      );
    }

    $fileContent = file_get_contents($importFilename);
    if ($fileContent === FALSE) {
      throw new SodaScsHelpersException(
        message: 'Failed to read import file: ' . $importFilename,
        operationCategory: 'snapshot',
        operation: 'import_file_read',
        context: ['file' => $importFilename],
        code: 0,
      );
    }

    // Build the request parameters.
    $requestParams = [
      'format' => $format,
      'routeParams' => [
        'repositoryId' => $repositoryId,
      ],
      'body' => $fileContent,
    ];

    // Construct and do the import request.
    $importRequest = $this->sodaScsOpenGdbServiceActions->buildReplaceRepositoryRequest($requestParams);
    $importResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($importRequest);

    if (!$importResponse['success']) {
      throw new SodaScsRequestException($importResponse['data']['openGdbResponse']);
    }

    return SodaScsResult::success(
      message: 'Repository imported successfully.',
      data: [
        'repositoryId' => $repositoryId,
      ],
    );
  }

}
