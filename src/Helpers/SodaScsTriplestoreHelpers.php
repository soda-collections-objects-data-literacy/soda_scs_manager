<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;

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
   * The SCS Service Key actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * The SCS OpenGDB helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers
   */
  protected SodaScsOpenGdbHelpers $sodaScsOpenGdbHelpers;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * Constructor.
   *
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions
   *   The SCS OpenGDB service actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers $sodaScsSnapshotHelpers
   *   The SCS Snapshot helpers.
   * @param \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions
   *   The SCS Service Key actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers $sodaScsOpenGdbHelpers
   *   The SCS OpenGDB helpers.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'soda_scs_manager.opengdb.helpers')]
    SodaScsOpenGdbHelpers $sodaScsOpenGdbHelpers,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
  ) {
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsOpenGdbHelpers = $sodaScsOpenGdbHelpers;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
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

  /**
   * Ensure user data exists.
   *
   * Check if user service key password exists. If not, create it.
   * Check if user token exists. If not, create it.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsTriplestoreComponent $component
   *   The component entity.
   *
   * @return array
   *   The result of the operation.
   *
   * @throws \Drupal\soda_scs_manager\Exception\SodaScsHelpersException
   *   If the operation fails.
   */
  public function ensureUserDataExists($component) {
    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')['soda_scs_triplestore_component'];

    // Check if service key password exists.
    $keyPasswordProps = [
      'bundle'  => $component->bundle(),
      'bundleLabel' => $bundleInfo['label'],
      'type'  => 'password',
      'userId'    => $component->getOwnerId(),
      'username' => $component->getOwner()->getDisplayName(),
    ];

    $componentServiceKeyPassword = $this->sodaScsServiceKeyActions->getServiceKey($keyPasswordProps);

    if (!$componentServiceKeyPassword) {
      // If no password service key, create one.
      $componentServiceKeyPassword = $this->sodaScsServiceKeyActions->createServiceKey($keyPasswordProps);
      // If there is no service, there may be no user either.
    }

    // Check if user exists. If not, create it.
    $userData = $this->sodaScsOpenGdbHelpers->getUserDataByName($component->getOwner()->getDisplayName());
    if (!$userData) {
      $this->sodaScsOpenGdbHelpers->createUser($component->getOwner()->getDisplayName(), $componentServiceKeyPassword->get('servicePassword')->value);
    }
    else {
      // If there is a user, update the the user credentials to sync with the
      // service key.
      $this->sodaScsOpenGdbHelpers->updateUser($component->getOwner()->getDisplayName(), $componentServiceKeyPassword->get('servicePassword')->value);
    }
    $userData = $this->sodaScsOpenGdbHelpers->getUserDataByName($component->getOwner()->getDisplayName());

    $userData['password'] = $componentServiceKeyPassword->get('servicePassword')->value;

    // Check if service key token exists.
    $keyTokenProps = [
      'bundle'  => 'soda_scs_triplestore_component',
      'type'  => 'token',
      'userId'    => $component->getOwnerId(),
      'username' => $component->getOwner()->getDisplayName(),
    ];

    $componentServiceKeyToken = $this->sodaScsServiceKeyActions->getServiceKey($keyTokenProps);

    if (!$componentServiceKeyToken) {
      // If no token service key, create a new one for the user at OpenGDB.
      $userToken = $this->sodaScsOpenGdbHelpers->createUserToken($component->getOwner()->getDisplayName(), $componentServiceKeyPassword->get('servicePassword')->value);

      $keyTokenProps = [
        'bundle'  => $component->bundle(),
        'bundleLabel' => $bundleInfo['label'],
        'token' => $userToken,
        'type'  => 'token',
        'userId'    => $component->getOwnerId(),
        'username' => $component->getOwner()->getDisplayName(),
      ];

      // Create new service key token.
      $componentServiceKeyToken = $this->sodaScsServiceKeyActions->createServiceKey($keyTokenProps);

      $userData['token'] = $userToken;

    }

    return $userData;

  }

}
