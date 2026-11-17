<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentActionsException;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsTriplestoreComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS OpenGDB service actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface
   */
  protected SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions;

  /**
   * The SCS OpenGDB helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers
   */
  protected SodaScsOpenGdbHelpers $sodaScsOpenGdbHelpers;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * The SCS Snapshot helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * The entity type bundle info service.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The SCS Docker Run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface
   */
  protected SodaScsRunRequestInterface $sodaScsDockerRunServiceActions;

  /**
   * The SCS Triplestore helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers
   */
  protected SodaScsTriplestoreHelpers $sodaScsTriplestoreHelpers;

  /**
   * The SCS Keycloak helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers
   */
  protected SodaScsKeycloakHelpers $sodaScsKeycloakHelpers;

  /**
   * The SCS Project helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.opengdb_service.actions')]
    SodaScsOpenGdbRequestInterface $sodaScsOpenGdbServiceActions,
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'soda_scs_manager.opengdb.helpers')]
    SodaScsOpenGdbHelpers $sodaScsOpenGdbHelpers,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.triplestore_helpers')]
    SodaScsTriplestoreHelpers $sodaScsTriplestoreHelpers,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    SodaScsKeycloakHelpers $sodaScsKeycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    SodaScsProjectHelpers $sodaScsProjectHelpers,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsOpenGdbServiceActions = $sodaScsOpenGdbServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsOpenGdbHelpers = $sodaScsOpenGdbHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsTriplestoreHelpers = $sodaScsTriplestoreHelpers;
    $this->sodaScsKeycloakHelpers = $sodaScsKeycloakHelpers;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
  }

  /**
   * Create Triplestore Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   The created component.
   */
  public function createComponent(SodaScsComponentInterface $entity): array {
    try {
      $triplestoreComponentBundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')['soda_scs_triplestore_component'];

      if (!$triplestoreComponentBundleInfo) {
        throw new \Exception('Triplestore component bundle info not found');
      }
      $machineName = 'ts-' . $entity->get('machineName')->value;
      // @todo We have the entity here already, so we should not create a new one.
      $publicRead = $entity->hasField('publicRead') && !$entity->get('publicRead')->isEmpty()
        ? (bool) $entity->get('publicRead')->value
        : FALSE;

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $triplestoreComponent */
      $triplestoreComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_triplestore_component',
          'label' => $entity->get('label')->value,
          'machineName' => $machineName,
          'owner'  => $entity->getOwnerId(),
          'description' => $triplestoreComponentBundleInfo['description'],
          'imageUrl' => $triplestoreComponentBundleInfo['imageUrl'],
          'health' => 'Unknown',
          'partOfProjects' => $entity->get('partOfProjects'),
          'publicRead' => $publicRead,
        ]
      );

      // Ensure user data exists.
      $userdata = $this->sodaScsTriplestoreHelpers->ensureUserDataExists($triplestoreComponent);

      // Link password and token service keys to the component.
      $triplestoreComponent->set('serviceKey', [
        $userdata['passwordServiceKey']->id(),
        $userdata['tokenServiceKey']->id(),
      ]);

      $username = $triplestoreComponent->getOwner()->getDisplayName();

      // Build repo request.
      $createRepoRequestParams = [
        'type' => 'repository',
        'queryParams' => [],
        'routeParams' => [],
        'body' => [
          'machineName' => $machineName,
          'title' => $triplestoreComponent->label(),
          'publicRead' => $publicRead,
          'publicWrite' => FALSE,
        ],
      ];
      // Create triplestore repository.
      $openGdbCreateRepoRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createRepoRequestParams);
      $createRepoResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateRepoRequest);
      // A failed repository request used to go unnoticed and the component
      // was saved without a repository.
      if (empty($createRepoResponse['success'])) {
        throw new \RuntimeException(sprintf(
          'OpenGDB repository %s could not be created: %s',
          $machineName,
          $createRepoResponse['error'] ?? $createRepoResponse['message'] ?? 'no details',
        ));
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot create triplestore repository: @message',
        [
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      return [
        'message' => $this->t('The triplestore could not be created.'),
        'data' => [
          'createRepoResponse' => $createRepoResponse ?? NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }

    try {
      // Update existing user.
      $roleBefore = ['ROLE_USER'];
      $readRightsBefore = [];
      $writeRightsBefore = [];
      foreach ($userdata['grantedAuthorities'] as $authority) {
        if (strpos($authority, 'ROLE_') === 0) {
          $roleBefore = [$authority];
        }
        elseif (strpos($authority, 'READ_') === 0) {
          $readRightsBefore[] = $authority;
        }
        elseif (strpos($authority, 'WRITE_') === 0) {
          $writeRightsBefore[] = $authority;
        }
      }
      $updateUserRequestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $username],
        'body' => [

          'grantedAuthorities' => array_merge(
            $roleBefore,
            $readRightsBefore,
            $writeRightsBefore,
            [
              "READ_REPO_$machineName",
              "WRITE_REPO_$machineName",
            ]),
          'appSettings' => SodaScsOpenGdbHelpers::USER_APP_SETTINGS,
          // "dateUpdated" => \Drupal::time()->getRequestTime(),
        ],
      ];
      $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
      $openGdbUpdateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot grant triplestore repository rights: @message',
        [
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      $this->rollBackCreateComponent($triplestoreComponent);
      return [
        'message' => $this->t('The triplestore access rights could not be set.'),
        'data' => [
          'createRepoResponse' => $createRepoResponse,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }

    try {
      // Keycloak access groups: {machineName}-admin / {machineName}-user.
      $ownerSsoUuid = $this->sodaScsProjectHelpers->getUserSsoUuid($triplestoreComponent->getOwner());
      $memberSsoUuids = [];
      foreach ($triplestoreComponent->get('partOfProjects')->referencedEntities() as $linkedProject) {
        foreach ($linkedProject->get('members')->referencedEntities() as $member) {
          $memberSsoUuid = $this->sodaScsProjectHelpers->getUserSsoUuid($member);
          if ($memberSsoUuid) {
            $memberSsoUuids[] = $memberSsoUuid;
          }
        }
      }
      $this->sodaScsKeycloakHelpers->createAndPopulateComponentAccessGroups(
        $machineName,
        $ownerSsoUuid,
        array_values(array_unique($memberSsoUuids)),
      );
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot create triplestore Keycloak groups: @message',
        [
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      $this->rollBackCreateComponent($triplestoreComponent);
      return [
        'message' => $this->t('The access groups for the triplestore could not be created.'),
        'data' => [
          'createRepoResponse' => $createRepoResponse ?? NULL,
          'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse ?? NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }

    try {
      $triplestoreComponent->save();
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot save triplestore component @machineName: @message',
        [
          '@machineName' => $machineName,
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      $this->rollBackCreateComponent($triplestoreComponent);
      return [
        'message' => $this->t('The triplestore could not be created.'),
        'data' => [
          'createRepoResponse' => $createRepoResponse,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }

    return [
      'message' => $this->t('Created triplestore component %machineName.', ['%machineName' => $machineName]),
      'data' => [
        'triplestoreComponent' => $triplestoreComponent,
        'createRepoResponse' => $createRepoResponse,
        'userData' => $userdata,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Create SODa SCS Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param int $timestamp
   *   The timestamp of the snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    try {
      // Create paths.
      $snapshotPaths = $this->sodaScsSnapshotHelpers->constructSnapshotPaths($component, $snapshotMachineName, (string) $timestamp);

      // Create the backup directory.
      $this->sodaScsSnapshotHelpers->createDir($snapshotPaths['backupPathWithType']);

      // Export the triplestore repository.
      $repositoryId = $component->get('machineName')->value;
      $filename = $component->get('machineName')->value;
      $this->sodaScsTriplestoreHelpers->exportTriplestoreRepository($repositoryId, $filename, $snapshotPaths['backupPathWithType'], 'nq');

      $randomInt = $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $containerName = 'snapshot--' . $randomInt . '--' . $snapshotMachineName . '--triplestore';

      // Convert container path to host path for bind mount.
      $hostBackupPath = $this->sodaScsSnapshotHelpers
        ->convertContainerPathToHostPath($snapshotPaths['backupPathWithType']);

      // Create and run a short-lived container to tar and checksum the export.
      $createContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest([
        'name' => $containerName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => SodaScsSnapshotHelpers::snapshotDockerRunUser(),
        'cmd' => [
          'sh',
          '-c',
          'tar czf /backup/' . $snapshotPaths['tarFileName'] . ' -C /source . && cd /backup && sha256sum ' . $snapshotPaths['tarFileName'] . ' > ' . $snapshotPaths['sha256FileName'],
        ],
        'hostConfig' => [
          'Binds' => [
            $hostBackupPath . ':/source',
            $hostBackupPath . ':/backup',
          ],
          'AutoRemove' => TRUE,
        ],
      ]);
      $createContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createContainerRequest);
      if (!$createContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $createContainerResponse['error'],
          message: 'Snapshot creation failed: Could not create snapshot container.',
        );
      }

      $containerId = json_decode($createContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ]);
      $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);
      if (!$startContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start snapshot container.',
        );
      }

      $componentData = [
        'componentBundle' => $component->bundle(),
        'componentId' => $component->id(),
        'componentMachineName' => $component->get('machineName')->value,
        'snapshotContainerId' => $containerId,
        'snapshotContainerName' => $containerName,
        'createSnapshotContainerResponse' => $createContainerResponse,
        'metadata' => [
          'backupPath' => $snapshotPaths['backupPath'],
          'relativeUrlBackupPath' => $snapshotPaths['relativeUrlBackupPath'],
          'contentFilePaths' => [
            'tarFilePath' => $snapshotPaths['absoluteTarFilePath'],
            'sha256FilePath' => $snapshotPaths['absoluteSha256FilePath'],
          ],
          'contentFileNames' => [
            'tarFileName' => $snapshotPaths['tarFileName'],
            'sha256FileName' => $snapshotPaths['sha256FileName'],
          ],
          'snapshotMachineName' => $snapshotMachineName,
          'snapshotDirectory' => $snapshotPaths['snapshotDirectory'],
          'snapshotFilesystemRoot' => $snapshotPaths['snapshotFilesystemRoot'],
          'timestamp' => $timestamp,

        ],
        'startSnapshotContainerResponse' => $startContainerResponse,
      ];

      return SodaScsResult::success(
        message: 'Snapshot created successfully.',
        data: [
          $component->bundle() => SodaScsSnapshotData::fromArray($componentData),
        ],
      );
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Snapshot creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      throw SodaScsComponentActionsException::triplestoreFailed(
        message: $e->getMessage(),
        operation: 'create_snapshot',
        context: ['component' => $component->get('machineName')->value],
        previous: $e,
      );
    }
  }

  /**
   * Get all Triplestore Components.
   *
   * @return array
   *   The result array with the Triplestore components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Read all Triplestore components.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Update Triplestore component.
   *
   * Syncs publicRead setting to the remote repository.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the update.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    $machineName = $component->get('machineName')->value;
    $publicRead = $component->hasField('publicRead') && !$component->get('publicRead')->isEmpty()
      ? (bool) $component->get('publicRead')->value
      : FALSE;

    try {
      $updateRepoRequestParams = [
        'type' => 'repository',
        'queryParams' => [],
        'routeParams' => ['machineName' => $machineName],
        'body' => [
          'publicRead' => $publicRead,
          'publicWrite' => FALSE,
        ],
      ];
      $openGdbUpdateRepoRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateRepoRequestParams);
      $openGdbUpdateRepoResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateRepoRequest);

      if (!$openGdbUpdateRepoResponse['success']) {
        return [
          'message' => $this->t('Could not update triplestore repository @machineName', ['@machineName' => $machineName]),
          'data' => ['openGdbUpdateRepoResponse' => $openGdbUpdateRepoResponse],
          'success' => FALSE,
          'error' => $openGdbUpdateRepoResponse['error'] ?? NULL,
        ];
      }

      return [
        'message' => $this->t('Updated triplestore component @machineName', ['@machineName' => $machineName]),
        'data' => ['openGdbUpdateRepoResponse' => $openGdbUpdateRepoResponse],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not update triplestore repository: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => $this->t('Could not update triplestore component @machineName', ['@machineName' => $machineName]),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }

  /**
   * Delete Triplestore component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    if ($component->getOwner() !== NULL && $component->getOwner()->getDisplayName() !== NULL) {
      $username = $component->getOwner()->getDisplayName();
    }
    else {
      $username = 'deleted user';
    }
    $machineName = $component->get('machineName')->value;
    $requestParams = [
      'type' => 'repository',
      'queryParams' => [],
      'routeParams' => ['machineName' => $machineName],
      'body' => [],
    ];

    try {
      $openGdbDeleteRepositoryRequest = $this->sodaScsOpenGdbServiceActions->buildDeleteRequest($requestParams);
      $openGdbDeleteRepositoryResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbDeleteRepositoryRequest);

      if (!$openGdbDeleteRepositoryResponse['success']) {
        $requestException = $openGdbDeleteRepositoryResponse['data']['openGdbResponse'] ?? NULL;
        // Only a missing repository lets the delete go on; any other failure
        // keeps the component, so it is not dropped while its data remains.
        if (!$requestException instanceof \Throwable || $requestException->getCode() !== 404) {
          throw new \RuntimeException(sprintf('Repository %s could not be deleted: %s', $machineName, $openGdbDeleteRepositoryResponse['error'] ?? 'no details'));
        }
        $this->messenger->addError($this->t("Could not delete repository of component %component, because it does not exist. Move on to delete the component.", ['%component' => $machineName]));
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not delete triplestore component: @message',
        [
          '%component' => $machineName,
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      return [
        'message' => $this->t('Could not delete triplestore component %component', ['%component' => $machineName]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => NULL,
          'openGdbUpdateUserResponse' => NULL,
          'openGdbGetUserResponse' => NULL,
          'openGdbDeleteUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      $requestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $username],
        'body' => [],
      ];

      // Get the user.
      $openGdbGetUserRequest = $this->sodaScsOpenGdbServiceActions->buildGetRequest($requestParams);
      $openGdbGetUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbGetUserRequest);

      // A missing user has no grants to remove, and the repository is already
      // gone: stopping here would make the component impossible to delete
      // (renamed or deleted owner). Any other failure stops before the grants
      // are touched; the component stays so the delete can be retried.
      $isUserMissing = !$openGdbGetUserResponse['success']
        && $this->getOpenGdbStatusCode($openGdbGetUserResponse) === 404;
      if ($isUserMissing) {
        $this->loggerFactory->get('soda_scs_manager')->warning(
          'OpenGDB user @username of triplestore component @component does not exist; no grants to remove.',
          [
            '@component' => $machineName,
            '@username' => $username,
          ]
        );
      }
      elseif (!$openGdbGetUserResponse['success']) {
        $this->loggerFactory->get('soda_scs_manager')->error(
          'Could not get OpenGDB user @username of triplestore component @component (HTTP @status): @error',
          [
            '@component' => $machineName,
            '@error' => $openGdbGetUserResponse['error'] ?? 'no details',
            '@status' => $this->getOpenGdbStatusCode($openGdbGetUserResponse),
            '@username' => $username,
          ]
        );
        return [
          'message' => $this->t('Could not get triplestore user of component %component', ['%component' => $machineName]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => NULL,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $openGdbGetUserResponse['error'] ?? '',
        ];
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not get triplestore user information: @message',
        [
          '%component' => $machineName,
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      return [
        'message' => $this->t('Could not get triplestore user of component %component', ['%component' => $machineName]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
          'openGdbUpdateUserResponse' => NULL,
          'openGdbGetUserResponse' => NULL,
          'openGdbDeleteUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    if ($isUserMissing) {
      $authorities = [];
    }
    else {
      $openGdbGetUserResponseData = json_decode((string) $openGdbGetUserResponse['data']['openGdbResponse']->getBody(), TRUE);
      $authorities = $openGdbGetUserResponseData['grantedAuthorities'] ?? NULL;
    }
    if (!is_array($authorities)) {
      $this->loggerFactory->get('soda_scs_manager')->error(
        'OpenGDB user @username of triplestore component @component has no readable grant list.',
        [
          '@component' => $machineName,
          '@username' => $username,
        ]
      );
      return [
        'message' => $this->t('Could not get triplestore user of component %component', ['%component' => $machineName]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
          'openGdbGetUserResponse' => $openGdbGetUserResponse,
          'openGdbUpdateUserResponse' => NULL,
          'openGdbDeleteUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => 'No grant list in the OpenGDB user response.',
      ];
    }

    // Only the grants of this repository are removed. The account stays even
    // without repository grants: its token service key is reused by the next
    // triplestore, and the account offboarding deletes the account.
    $repositoryAuthorities = [
      "READ_REPO_$machineName",
      "WRITE_REPO_$machineName",
    ];
    // array_values() keeps the list a JSON array after array_filter() left
    // gaps in the keys; OpenGDB expects a list.
    $remainingAuthorities = array_values(array_filter(
      $authorities,
      static fn ($authority): bool => !in_array($authority, $repositoryAuthorities, TRUE),
    ));
    $openGdbUpdateUserResponse = NULL;
    $openGdbDeleteUserResponse = NULL;

    if (count($remainingAuthorities) !== count($authorities)) {
      try {
        $updateUserRequestParams = [
          'type' => 'user',
          'queryParams' => [],
          'routeParams' => ['username' => $username],
          'body' => [
            'grantedAuthorities' => $remainingAuthorities,
            'appSettings' => SodaScsOpenGdbHelpers::USER_APP_SETTINGS,
          ],
        ];

        $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
        $openGdbUpdateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
        if (!$openGdbUpdateUserResponse['success']) {
          throw new \RuntimeException(sprintf(
            'OpenGDB answered HTTP %d: %s',
            $this->getOpenGdbStatusCode($openGdbUpdateUserResponse),
            $openGdbUpdateUserResponse['error'] ?? 'no details'
          ));
        }
      }
      catch (\Exception $e) {
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not remove the grants of triplestore component @component from OpenGDB user @username: @message',
          [
            '@component' => $machineName,
            '@message' => $e->getMessage(),
            '@username' => $username,
          ],
          LogLevel::ERROR
        );
        return [
          'message' => $this->t('Could not update triplestore user %username', ['%username' => $username]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    try {
      $keycloakGroupsDeleteResult = $this->sodaScsKeycloakHelpers->deleteComponentAccessGroups($machineName);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not delete Keycloak groups for triplestore component: @message',
        [
          '%component' => $machineName,
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      return [
        'message' => $this->t('Could not delete Keycloak groups for triplestore component %component', ['%component' => $machineName]),
        'data' => [
          'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
          'openGdbGetUserResponse' => $openGdbGetUserResponse,
          'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse ?? NULL,
          'openGdbDeleteUserResponse' => $openGdbDeleteUserResponse ?? NULL,
          'keycloakGroupsDeleteResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $component->delete();
    return [
      'message' => $this->t('Deleted triplestore component %component', ['%component' => $machineName]),
      'data' => [
        'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
        'openGdbGetUserResponse' => $openGdbGetUserResponse,
        'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse,
        'openGdbDeleteUserResponse' => $openGdbDeleteUserResponse,
        'keycloakGroupsDeleteResult' => $keycloakGroupsDeleteResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Restore Component from Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   * @param string|null $tempDirPath
   *   The path to the temporary directory.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDirPath): SodaScsResult {
    try {
      //
      // Collect information about the snapshot's Triplestore component.
      //
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $component */
      $component = $snapshot->get('snapshotOfComponent')->entity ?? NULL;
      if (!$component) {
        return SodaScsResult::failure(
          message: 'Snapshot is not linked to a component.',
          error: 'Missing component on snapshot.',
        );
      }

      $componentMachineName = $component->get('machineName')->value;
      $repositoryId = $componentMachineName;
      $rollbackFilename = 'rollback--' . $componentMachineName;
      // @todo Abstract for different formats.
      $nqPath = $tempDirPath . '/nq';
      //
      // Create a rollback backup of the existing triplestore repository.
      //
      $createRollbackResult = $this->sodaScsTriplestoreHelpers->exportTriplestoreRepository($repositoryId, $rollbackFilename, $nqPath, 'nq', time());
      if (!$createRollbackResult->success) {
        return SodaScsResult::failure(
          message: 'Snapshot restoration failed.',
          error: $createRollbackResult->message,
        );
      }

      //
      // Replace the existing triplestore repository with the snapshot.
      //
      // Its already unpacked and validated within the
      // SodaScsSnapshotActions::restoreFromSnapshot logic.
      //
      $filename = $componentMachineName;
      $importFilename = $nqPath . '/' . $filename . '.nq';
      $importResult = $this->sodaScsTriplestoreHelpers->importTriplestoreRepository($repositoryId, $importFilename, 'nq', time());
      if (!$importResult->success) {
        return SodaScsResult::failure(
          message: 'Snapshot restoration failed.',
          error: $importResult->message,
        );
      }

      return SodaScsResult::success(
        message: 'Snapshot restoration successful.',
        data: $importResult->data,
      );

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Snapshot restoration failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return SodaScsResult::failure(
        message: 'Snapshot restoration failed.',
        error: $e->getMessage(),
      );
    }
    return SodaScsResult::failure(
      message: 'Not yet implemented.',
      error: 'Not yet implemented.',
    );
  }

  /**
   * Returns the HTTP status of a failed OpenGDB request, for the log.
   *
   * @param array $response
   *   The result of SodaScsOpenGdbServiceActions::makeRequest().
   *
   * @return int
   *   The HTTP status, or 0 when the request got no response.
   */
  private function getOpenGdbStatusCode(array $response): int {
    if (isset($response['statusCode'])) {
      return (int) $response['statusCode'];
    }
    $exception = $response['data']['openGdbResponse'] ?? NULL;
    return $exception instanceof \Throwable ? (int) $exception->getCode() : 0;
  }

  /**
   * Removes the repository, rights and groups of a failed createComponent().
   *
   * Only called after this call created the repository. Runs
   * deleteComponent() on the component, which may still be unsaved. A failure
   * is logged; the caller returns its original error either way.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component whose repository this call created.
   */
  private function rollBackCreateComponent(SodaScsComponentInterface $component): void {
    $result = $this->deleteComponent($component);
    if (empty($result['success'])) {
      $error = $result['error'] ?? 'no details';
      $this->loggerFactory->get('soda_scs_manager')->error('Could not remove the repository @machineName of a failed triplestore component: @error', [
        '@error'       => $error instanceof \Throwable ? $error->getMessage() : (string) $error,
        '@machineName' => $component->get('machineName')->value,
      ]);
    }
  }

}
