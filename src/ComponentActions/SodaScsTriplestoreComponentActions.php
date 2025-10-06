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
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentActionsException;
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
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.triplestore_helpers')]
    SodaScsTriplestoreHelpers $sodaScsTriplestoreHelpers,
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
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsTriplestoreHelpers = $sodaScsTriplestoreHelpers;
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
        ]
      );
      // Create service key if it does not exist.
      $keyProps = [
        'bundle'  => $triplestoreComponent->bundle(),
        'bundleLabel' => $triplestoreComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'    => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];

      $triplestoreComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $triplestoreComponent->serviceKey[] = $triplestoreComponentServiceKey;

      $tokenProps = [
        'bundle'  => 'soda_scs_triplestore_component',
        'type'  => 'token',
        'userId'    => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
        'servicePassword' => $triplestoreComponentServiceKey->get('servicePassword')->value,
      ];

      if ($triplestoreComponentServiceToken = $this->sodaScsServiceKeyActions->getServiceKey($tokenProps)) {
        $triplestoreComponent->serviceKey[] = $triplestoreComponentServiceToken;
      }

      $username = $triplestoreComponent->getOwner()->getDisplayName();

      // Build repo request.
      $createRepoRequestParams = [
        'type' => 'repository',
        'queryParams' => [],
        'routeParams' => [],
        'body' => [
          'machineName' => $machineName,
          'title' => $triplestoreComponent->label(),
          'publicRead' => FALSE,
          'publicWrite' => FALSE,
        ],
      ];
      // Create triplestore repository.
      $openGdbCreateRepoRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createRepoRequestParams);
      $createRepoResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateRepoRequest);
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot assemble Request: @message',
        [
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'createRepoResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      // Look for existing user.
      $getUserRequestParams = [
        'type' => 'user',
        'queryParams' => [],
        'routeParams' => ['username' => $triplestoreComponent->getOwner()->getDisplayName()],
      ];

      $openGdbGetUserRequest = $this->sodaScsOpenGdbServiceActions->buildGetRequest($getUserRequestParams);
      $getUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbGetUserRequest);

      // @todo Make good error handling
      if ($getUserResponse['success'] === FALSE) {
        $response = $getUserResponse['data']['openGdbResponse']->getResponse();
        if ($response->getStatusCode() === 404) {
          // If there is no create repo user, create one.
          try {
            $createUserRequestParams = [
              'type' => 'user',
              'queryParams' => [],
              'routeParams' => ['username' => $username],
              'body' => [
                'password' => $triplestoreComponentServiceKey->get('servicePassword')->value,
                'machineName' => $machineName,
              ],
            ];
            $openGdbCreateUserRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($createUserRequestParams);
            $createUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateUserRequest);

            $this->messenger->addMessage($this->t("Created OpenGDB user: @username", ['@username' => $username]));

            if (!$createUserResponse['success']) {
              return [
                'message' => 'Could not create user.',
                'data' => [
                  'createRepoResponse' => $createRepoResponse,
                  'getUserResponse' => $getUserResponse,
                  'createUserResponse' => $createUserResponse,
                  'updateUserResponse' => NULL,
                ],
                'success' => FALSE,
                'error' => $createUserResponse['error'],
              ];
            }

            // Create user token.
            try {
              $createUserTokenRequestParams = [
                'type' => 'user',
                'queryParams' => [],
                'routeParams' => [
                  'username' => $username,
                ],
                'body' => [
                  'username' => $username,
                  'password' => $triplestoreComponentServiceKey->get('servicePassword')->value,
                ],
              ];
              $openGdbCreateUserTokenRequest = $this->sodaScsOpenGdbServiceActions->buildTokenRequest($createUserTokenRequestParams);
              $createUserTokenResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateUserTokenRequest);
              if (!$createUserTokenResponse['success']) {
                return [
                  'message' => 'Could not create user token.',
                  'data' => [
                    'createRepoResponse' => $createRepoResponse,
                    'getUserResponse' => $getUserResponse,
                    'createUserResponse' => $createUserResponse,
                    'updateUserResponse' => NULL,
                  ],
                  'success' => FALSE,
                  'error' => $createUserResponse['error'],
                ];
              }
              $createUserTokenData = json_decode($createUserTokenResponse['data']['openGdbResponse']->getBody()->getContents(), TRUE);
              $tokenProps = [
                'bundle'  => 'soda_scs_triplestore_component',
                'bundleLabel' => $triplestoreComponentBundleInfo['label'],
                'type'  => 'token',
                'token' => $createUserTokenData['token'],
                'userId'    => $entity->getOwnerId(),
                'username' => $entity->getOwner()->getDisplayName(),
              ];
              $triplestoreComponentServiceToken = $this->sodaScsServiceKeyActions->createServiceKey($tokenProps);
              $triplestoreComponent->serviceKey[] = $triplestoreComponentServiceToken;
            }
            catch (\Exception $e) {
              Error::logException(
                $this->loggerFactory->get('soda_scs_manager'),
                $e,
                'Cannot assemble Request: @message',
                [
                  '@message' => $e->getMessage(),
                ],
                LogLevel::ERROR
              );
              $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
              return [
                'message' => 'Cannot assemble Request.',
                'data' => [
                  'createRepoResponse' => $createRepoResponse,
                  'getUserResponse' => $getUserResponse,
                  'createUserResponse' => $createUserResponse,
                  'updateUserResponse' => NULL,
                ],
                'success' => FALSE,
                'error' => $e->getMessage(),
              ];
            }

          }
          catch (MissingDataException $e) {
            Error::logException(
              $this->loggerFactory->get('soda_scs_manager'),
              $e,
              'Cannot assemble Request: @message',
              [
                '@message' => $e->getMessage(),
              ],
              LogLevel::ERROR
            );
            $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
            return [
              'message' => 'Cannot assemble Request.',
              'data' => [
                'createRepoResponse' => $createRepoResponse,
                'getUserResponse' => $getUserResponse,
                'createUserResponse' => NULL,
                'updateUserResponse' => NULL,
              ],
              'success' => FALSE,
              'error' => $e->getMessage(),
            ];
          }
        }
      }
      else {
        try {
          $getUserResponseData = json_decode($getUserResponse['data']['openGdbResponse']->getBody()->getContents(), TRUE);
          // Update existing user.
          $roleBefore = ['ROLE_USER'];
          $readRightsBefore = [];
          $writeRightsBefore = [];
          foreach ($getUserResponseData['grantedAuthorities'] as $authority) {
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
              "appSettings" => [
                "DEFAULT_INFERENCE" => TRUE,
                "DEFAULT_SAMEAS" => TRUE,
                "DEFAULT_VIS_GRAPH_SCHEMA" => TRUE,
                "EXECUTE_COUNT" => TRUE,
                "IGNORE_SHARED_QUERIES" => FALSE,
              ],
              // "dateUpdated" => \Drupal::time()->getRequestTime(),
            ],
          ];
          $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
          $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
          $createUserResponse = NULL;
        }
        catch (MissingDataException $e) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            $e,
            'Cannot assemble Request: @message',
            [
              '@message' => $e->getMessage(),
            ],
            LogLevel::ERROR
          );
          $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
          return [
            'message' => 'Cannot assemble Request.',
            'data' => [
              'createRepoResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $e->getMessage(),
          ];
        }
      }
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot assemble Request: @message',
        [
          '@message' => $e->getMessage(),
        ],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'createRepoResponse' => $createRepoResponse,
          'getUserResponse' => NULL,
          'createUserResponse' => NULL,
          'updateUserResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    // Save the component.
    $triplestoreComponent->save();

    // Save the service key.
    $triplestoreComponentServiceKey->scsComponent[] = $triplestoreComponent->id();
    $triplestoreComponentServiceKey->save();

    // Save the service token.
    /* @todo Make sure there is a triplestore component service token. */
    if (empty($triplestoreComponentServiceToken)) {
      throw new \Exception('There is no triplestore component service token.');
    }

    $triplestoreComponentServiceToken->scsComponent[] = $triplestoreComponent->id();
    $triplestoreComponentServiceToken->save();

    return [
      'message' => $this->t('Created triplestore component %machineName.', ['%machineName' => $machineName]),
      'data' => [
        'triplestoreComponent' => $triplestoreComponent,
        'createRepoResponse' => $createRepoResponse,
        'getUserResponse' => $getUserResponse,
        'createUserResponse' => NULL,
        'updateUserResponse' => NULL,

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
      // Create and run a short-lived container to tar and checksum the export.
      $createContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest([
        'name' => $containerName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => '33:33',
        'cmd' => [
          'sh',
          '-c',
          'tar czf /backup/' . $snapshotPaths['tarFileName'] . ' -C /source . && cd /backup && sha256sum ' . $snapshotPaths['tarFileName'] . ' > ' . $snapshotPaths['sha256FileName'],
        ],
        'hostConfig' => [
          'Binds' => [
            $snapshotPaths['backupPathWithType'] . ':/source',
            $snapshotPaths['backupPathWithType'] . ':/backup',
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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component.
   *
   * @return array
   *   The result array of the created component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
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
        /** @var \GuzzleHttp\Exception\ClientException $clientException */
        $clientException = $openGdbDeleteRepositoryResponse['data']['openGdbResponse'];
        if ($clientException->getCode() === 404) {
          $this->messenger->addError($this->t("Could not delete repository of component %component, because it does not exist. Move on to delete the component.", ['%component' => $machineName]));
        }
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

      // Check if the user exists.
      if (!$openGdbGetUserResponse['success']) {
        /** @var \GuzzleHttp\Exception\ClientException $clientException */
        $clientException = $openGdbGetUserResponse['data']['openGdbResponse'];
        if ($clientException->getResponse()->getStatusCode() === 404) {

          return [
            'message' => 'Could not get User information for triplestore component.',
            'data' => [
              'openGdbResponse' => $openGdbGetUserResponse,
              'openGdbUpdateUserResponse' => NULL,
              'openGdbGetUserResponse' => $clientException,
              'openGdbDeleteUserResponse' => NULL,
            ],
            'success' => FALSE,
            'error' => $openGdbGetUserResponse['error'],
          ];
        }
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

    // Get the response data.
    $openGdbGetUserResponseData = json_decode($openGdbGetUserResponse['data']['openGdbResponse']->getBody()->getContents(), TRUE);

    // Get the authorities.
    $authorities = $openGdbGetUserResponseData['grantedAuthorities'];

    // Check if the user has more than 3 authorities.
    if ($authorities > 3) {

      try {
        $authorities = array_filter($authorities, function ($authority) use ($machineName) {
          return !in_array($authority, [
            "READ_REPO_$machineName",
            "WRITE_REPO_$machineName",
          ]);
        });

        $updateUserRequestParams = [
          'type' => 'user',
          'queryParams' => [],
          'routeParams' => ['username' => $username],
          'body' => [

            'grantedAuthorities' => $authorities,
            "appSettings" => [
              "DEFAULT_INFERENCE" => TRUE,
              "DEFAULT_SAMEAS" => TRUE,
              "DEFAULT_VIS_GRAPH_SCHEMA" => TRUE,
              "EXECUTE_COUNT" => TRUE,
              "IGNORE_SHARED_QUERIES" => FALSE,
            ],
              // "dateUpdated" => \Drupal::time()->getRequestTime(),
          ],
        ];

        // Update the user.
        $openGdbUpdateUserRequest = $this->sodaScsOpenGdbServiceActions->buildUpdateRequest($updateUserRequestParams);
        $openGdbUpdateUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbUpdateUserRequest);
        $openGdbDeleteUserResponse = NULL;

      }
      catch (\Exception $e) {
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not update triplestore user: @message',
          [
            '%component' => $machineName,
            '@message' => $e->getMessage(),
          ],
          LogLevel::ERROR
        );
        return [
          'message' => $this->t('Could not update triplestore user %username', ['%username' => $username]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => NULL,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    elseif ($authorities === 3) {

      try {
        $deleteUserRequestParams = [
          'type' => 'user',
          'queryParams' => [],
          'routeParams' => ['username' => $username],
          'body' => [],
        ];

        $openGdbDeleteUserRequest = $this->sodaScsOpenGdbServiceActions->buildDeleteRequest($deleteUserRequestParams);
        $openGdbDeleteUserResponse = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbDeleteUserRequest);
        $openGdbUpdateUserResponse = NULL;
      }
      catch (\Exception $e) {
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not delete OpenGDB user: @message',
          [
            '%username' => $username,
            '@message' => $e->getMessage(),
          ],
          LogLevel::ERROR
        );
        return [
          'message' => $this->t('Could not delete OpenGDB user %username', ['%username' => $username]),
          'data' => [
            'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
            'openGdbGetUserResponse' => $openGdbGetUserResponse,
            'openGdbUpdateUserResponse' => NULL,
            'openGdbDeleteUserResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $e->getMessage(),
        ];
      }
    }
    $component->delete();
    return [
      'message' => $this->t('Deleted triplestore component %component', ['%component' => $machineName]),
      'data' => [
        'openGdbDeleteRepositoryResponse' => $openGdbDeleteRepositoryResponse,
        'openGdbGetUserResponse' => $openGdbGetUserResponse,
        'openGdbUpdateUserResponse' => $openGdbUpdateUserResponse,
        'openGdbDeleteUserResponse' => $openGdbDeleteUserResponse,
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

}
