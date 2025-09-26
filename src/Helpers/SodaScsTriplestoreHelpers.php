<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;


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



  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions,
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
        $result = $this->sodaScsSnapshotHelpers->transformSparqlJsonToNquads($dumpData, $filename, $exportPath, $timestamp ?? time());
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
}
