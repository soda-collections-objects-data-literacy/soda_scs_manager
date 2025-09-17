<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;

/**
 * Class for SODa SCS Component filesystem actions.
 *
 * @todo Provide correct result arrays in the data array.
 */
class SodaScsFilesystemComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

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
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS Docker Volumes actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface
   */
  protected SodaScsExecRequestInterface $sodaScsDockerExecServiceActions;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   Result information with the created component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {

    $createExecPermissionsResults = [];
    $startExecPermissionsResults = [];

    $entity->set('machineName', 'fs-' . $entity->get('machineName')->value);

    // Create shared folders and set permissions in the access proxy container.
    try {
      // Prepare command to create shared folders.
      // Load project entities from target_id.
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
      $projectEntityReferencesList = $entity->get('partOfProjects');
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject[] $projectEntities */
      $projectEntities = $projectEntityReferencesList->referencedEntities();

      // Create commands to create shared folders.
      $accessProxycmds = [];

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $projectEntity */
      foreach ($projectEntities as $projectEntity) {
        // Format each command as an array of arguments.
        $accessProxycmds[] = [
          'addgroup',
          '-g',
          (string) $projectEntity->get('groupId')->value,
          (string) $projectEntity->get('groupId')->value,
        ];
        $accessProxycmds[] = [
          'adduser',
          'filemanager',
          (string) $projectEntity->get('groupId')->value,
        ];
        $accessProxycmds[] = [
          'mkdir',
          '-p',
          '/shared/' . $entity->get('machineName')->value,
        ];
        $accessProxycmds[] = [
          'chown',
          '-R',
          'filemanager:' . (string) $projectEntity->get('groupId')->value,
          '/shared/' . $entity->get('machineName')->value,
        ];

        $accessProxycmds[] = [
          'chmod',
          '-R',
          '070',
          '/shared/' . $entity->get('machineName')->value,
        ];
      }

      foreach ($accessProxycmds as $accessProxycmd) {
        // Create shared folders via access proxy.
        // @todo Create setting for access proxy.
        $accessProxyRequestParams = [
          'containerName' => 'access-proxy',
          'label' => $entity->get('label')->value,
          'machineName' => $entity->get('machineName')->value,
          'partOfProjects' => $entity->get('partOfProjects')->value,
          'connectedComponents' => $entity->get('connectedComponents')->value,
          'cmd' => $accessProxycmd,
          'user' => 'root',
          'workingDir' => '',
          'env' => [],
        ];

        // Create the exec command.
        $createExecCommandForFolderAtAccessProxyRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest($accessProxyRequestParams);
        $createExecCommandForFolderAtAccessProxyResult = $this->sodaScsDockerExecServiceActions->makeRequest($createExecCommandForFolderAtAccessProxyRequest);

        if (!$createExecCommandForFolderAtAccessProxyResult['success']) {
          return [
            'message' => 'Could not create exec request for the shared folders via access proxy.',
            'data' => [
              'filesystemComponent' => NULL,
              'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
              'startExecCommandForFolderAtAccessProxyResult' => NULL,
              'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
              'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
            ],
            'success' => FALSE,
            'error' => $createExecCommandForFolderAtAccessProxyResult['error'],
          ];
        }

        // Get the exec command result.
        $execCommandForFolderAtAccessProxyResult = json_decode($createExecCommandForFolderAtAccessProxyResult['data']['portainerResponse']->getBody()->getContents(), TRUE);

        // Start the exec command.
        $startExecCommandForFolderAtAccessProxyRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest(['execId' => $execCommandForFolderAtAccessProxyResult['Id']]);
        $startExecCommandForFolderAtAccessProxyResult = $this->sodaScsDockerExecServiceActions->makeRequest($startExecCommandForFolderAtAccessProxyRequest);

        if (!$startExecCommandForFolderAtAccessProxyResult['success']) {
          return [
            'message' => 'Could not start exec request for the shared folders via access proxy.',
            'data' => [
              'filesystemComponent' => NULL,
              'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
              'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
              'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
              'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
            ],
            'success' => FALSE,
            'error' => $startExecCommandForFolderAtAccessProxyResult['error'],
          ];
        }
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot create exec request for the shared folders via access proxy: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Exec request for the shared folders via access proxy failed.',
        'data' => [
          'filesystemComponent' => NULL,
          'createExecCommandForFolderAtAccessProxyResult' => NULL,
          'startExecCommandForFolderAtAccessProxyResult' => NULL,
          'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
          'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      // If no project entities found, escape.
      if (empty($projectEntities)) {
        return [
          'message' => 'No project entities found.',
          'data' => [
            'filesystemComponent' => NULL,
            'createExecCommandForFolderAtAccessProxyResult' => NULL,
            'startExecCommandForFolderAtAccessProxyResult' => NULL,
            'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
            'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
        ];
      }

      // Create shared folders and set permissions in the component containers.
      foreach ($projectEntities as $projectEntity) {

        // Get included components.
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemList */
        $sharedWithComponentReferencesList = $entity->get('sharedWith');

        /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent[] $componentsWithAccess */
        $componentsWithAccess = $sharedWithComponentReferencesList->referencedEntities();

        foreach ($componentsWithAccess as $componentWithAccess) {
          $containerCmds = [];
          switch ($componentWithAccess->get('bundle')->value) {
            case 'soda_scs_wisski_component':
              $containerType = 'drupal';
              break;

            case 'soda_scs_filesystem_component':
              continue 2;

            default:
              throw new \Exception('Unknown component bundle: ' . $componentWithAccess->get('bundle')->value);
          }
          $containerCmds[] = [
            'addgroup',
            '-gid',
            $projectEntity->get('groupId')->value,
            $projectEntity->get('groupId')->value,
          ];
          $containerCmds[] = [
            'adduser',
            'www-data',
            $projectEntity->get('groupId')->value,
          ];
          $containerCmds[] = [
            'chown',
            '-h',
            'www-data:' . $projectEntity->get('groupId')->value,
            '/var/www/html/sites/default/private-files/' . $projectEntity->get('groupId')->value,
          ];
          // Create shared folders via access proxy.
          // @todo Create setting for access proxy.
          foreach ($containerCmds as $containerCmd) {
            $accessProxyRequestParams = [
              'containerName' => $componentWithAccess->get('machineName')->value . '--' . $containerType,
              'label' => $entity->get('label')->value,
              'machineName' => $entity->get('machineName')->value,
              'partOfProjects' => $entity->get('partOfProjects')->value,
              'connectedComponents' => $entity->get('connectedComponents')->value,
              'cmd' => $containerCmd,
              'user' => '',
              'workingDir' => '',
              'env' => [],
            ];

            // Set permissions for the shared folders in the containers.
            // Create the exec command.
            $createExecCommandForSetFolderPermissionInContainersRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest($accessProxyRequestParams);
            $createExecCommandForSetFolderPermissionInContainersResult = $this->sodaScsDockerExecServiceActions->makeRequest($createExecCommandForSetFolderPermissionInContainersRequest);
            $createExecPermissionsResults[] = $createExecCommandForSetFolderPermissionInContainersResult;
            if (!$createExecCommandForSetFolderPermissionInContainersResult['success']) {
              return [
                'message' => 'Could not create exec request for the shared folders in the containers.',
                'data' => [
                  'filesystemComponent' => NULL,
                  'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
                  'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
                  'createExecCommandForSetFolderPermissionInContainersResult' => $createExecCommandForSetFolderPermissionInContainersResult,
                  'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
                ],
                'success' => FALSE,
                'error' => $createExecCommandForSetFolderPermissionInContainersResult['error'],
              ];
            }

            // Start the exec command.
            $execCommandIdForSetFolderPermissionInContainers = json_decode($createExecCommandForSetFolderPermissionInContainersResult['data']['portainerResponse']->getBody()->getContents(), TRUE);
            $startExecCommandForSetFolderPermissionInContainersRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest(['execId' => $execCommandIdForSetFolderPermissionInContainers['Id']]);
            $startExecCommandForSetFolderPermissionInContainersResult = $this->sodaScsDockerExecServiceActions->makeRequest($startExecCommandForSetFolderPermissionInContainersRequest);
            $permissionsResults[] = $startExecCommandForSetFolderPermissionInContainersResult;
            $startExecPermissionsResults = $startExecCommandForSetFolderPermissionInContainersResult['data']['portainerResponse']->getBody()->getContents();

            if (!$startExecCommandForSetFolderPermissionInContainersResult['success']) {
              return [
                'message' => 'Could not start exec request for the shared folders in the containers.',
                'data' => [
                  'filesystemComponent' => NULL,
                  'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
                  'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
                  'createExecCommandForSetFolderPermissionInContainersResult' => $createExecCommandForSetFolderPermissionInContainersResult,
                  'startExecCommandForSetFolderPermissionInContainersResult' => $startExecCommandForSetFolderPermissionInContainersResult,
                ],
                'success' => FALSE,
                'error' => $startExecCommandForSetFolderPermissionInContainersResult['error'],
              ];
            }
          }
        }
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot set permissions for the shared folders in the containers: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Could not set permissions for the shared folders in the containers.',
        'data' => [
          'filesystemComponent' => NULL,
          'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
          'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
          'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
          'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      // Save the component.
      $entity->save();
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot save component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Could not save component.',
        'data' => [
          'filesystemComponent' => NULL,
          'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
          'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
          'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
          'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    return [
      'message' => 'Created Filesystem component.',
      'data' => [
        'filesystemComponent' => $entity,
        'createExecCommandForFolderAtAccessProxyResult' => $createExecCommandForFolderAtAccessProxyResult,
        'startExecCommandForFolderAtAccessProxyResult' => $startExecCommandForFolderAtAccessProxyResult,
        'createExecPermissionsResults' => $createExecPermissionsResults,
        'startExecPermissionsResults' => $startExecPermissionsResults,
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
   * @return array
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    try {
      // @todo Implement createSnapshot() method.
    }
    catch (\Exception $e) {
      // @todo Implement createSnapshot() method.
    }

    return SodaScsResult::success(
      message: 'Snapshot created successfully.',
      data: [
        $component->bundle() => [
          'createSnapshotResult' => NULL,
        ],
        'snapshotMachineName' => $snapshotMachineName,
        'timestamp' => $timestamp,
        'componentBundle' => $component->bundle(),
        'componentId' => $component->id(),
        'componentMachineName' => $component->get('machineName')->value,
      ],
    );
  }

  /**
   * Get all SODa SCS Component.
   *
   * @return array
   *   Result information with all component.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Get SODa SCS Component.
   *
   * @param array $props
   *   The properties of the component you are looking for.
   *
   * @return array
   *   Result information with component.
   */
  public function getComponent(SodaScsComponentInterface $props): array {
    return [];
  }

  /**
   * Update SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with updated component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Delete SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with deleted component.
   */
  public function deleteComponent(SodaScsComponentInterface $entity): array {
    // Delete shared folders.
    try {
      if (empty($entity->get('machineName')->value)) {
        return [
          'message' => 'Filesystem machine name is empty.',
          'data' => [
            'filesystemComponent' => NULL,
            'createExecCommandForFolderAtAccessProxyResult' => NULL,
            'startExecCommandForFolderAtAccessProxyResult' => NULL,
            'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
            'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
        ];
      }
      $accessProxyDeleteDirCmd = [
        'rmdir',
        '-p',
        '/shared/' . $entity->get('machineName')->value,
      ];

      // Delete shared folders via access proxy.
      $accessProxyRequestParams = [
        'containerName' => 'access-proxy',
        'label' => $entity->get('label')->value,
        'machineName' => $entity->get('machineName')->value,
        'partOfProjects' => $entity->get('partOfProjects')->value,
        'connectedComponents' => $entity->get('connectedComponents')->value,
        'cmd' => $accessProxyDeleteDirCmd,
        'user' => 'www-data',
        'workingDir' => '',
        'env' => [],
      ];

      // Create the exec command.
      $createExecCommandForDeleteDirAtAccessProxyRequest = $this->sodaScsDockerExecServiceActions->buildCreateRequest($accessProxyRequestParams);
      $createExecCommandForDeleteDirAtAccessProxyResult = $this->sodaScsDockerExecServiceActions->makeRequest($createExecCommandForDeleteDirAtAccessProxyRequest);

      if (!$createExecCommandForDeleteDirAtAccessProxyResult['success']) {
        return [
          'message' => 'Could not create exec request for the shared folders via access proxy.',
          'data' => [
            'filesystemComponent' => NULL,
            'createExecCommandForDeleteDirAtAccessProxyResult' => $createExecCommandForDeleteDirAtAccessProxyResult,
            'startExecCommandForFolderAtAccessProxyResult' => NULL,
          ],
          'success' => FALSE,
          'error' => $createExecCommandForDeleteDirAtAccessProxyResult['error'],
        ];
      }

      // Get the exec command result.
      $execCommandForDeleteDirAtAccessProxyResult = json_decode($createExecCommandForDeleteDirAtAccessProxyResult['data']['portainerResponse']->getBody()->getContents(), TRUE);

      // Start the exec command.
      $startExecCommandForDeleteDirAtAccessProxyRequest = $this->sodaScsDockerExecServiceActions->buildStartRequest(['execId' => $execCommandForDeleteDirAtAccessProxyResult['Id']]);
      $startExecCommandForDeleteDirAtAccessProxyResult = $this->sodaScsDockerExecServiceActions->makeRequest($startExecCommandForDeleteDirAtAccessProxyRequest);

      if (!$startExecCommandForDeleteDirAtAccessProxyResult['success']) {
        return [
          'message' => 'Could not start exec request for the shared folders via access proxy.',
          'data' => [
            'filesystemComponent' => NULL,
            'createExecCommandForDeleteDirAtAccessProxyResult' => $createExecCommandForDeleteDirAtAccessProxyResult,
            'startExecCommandForDeleteDirAtAccessProxyResult' => $startExecCommandForDeleteDirAtAccessProxyResult,
            'createExecCommandForSetFolderPermissionInContainersResult' => NULL,
            'startExecCommandForSetFolderPermissionInContainersResult' => NULL,
          ],
          'success' => FALSE,
          'error' => $startExecCommandForDeleteDirAtAccessProxyResult['error'],
        ];
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot delete shared folders via access proxy: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Could not delete shared folders via access proxy.',
        'data' => [
          'filesystemComponent' => NULL,
          'createExecCommandForDeleteDirAtAccessProxyResult' => NULL,
          'startExecCommandForDeleteDirAtAccessProxyResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      // Delete the component.
      $entity->delete();
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot delete component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return [
        'message' => 'Could not delete component.',
        'data' => [
          'filesystemComponent' => NULL,
          'createExecCommandForDeleteDirAtAccessProxyResult' => NULL,
          'startExecCommandForDeleteDirAtAccessProxyResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    return [
      'message' => $this->t('Deleted Filesystem component @name.', ['@name' => $entity->get('label')->value]),
      'data' => [
        'filesystemComponent' => $entity,
        'createExecCommandForDeleteDirAtAccessProxyResult' => $createExecCommandForDeleteDirAtAccessProxyResult,
        'startExecCommandForDeleteDirAtAccessProxyResult' => $startExecCommandForDeleteDirAtAccessProxyResult,
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
   * @param string|null $stackBagPath
   *   The path to the stack bag.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $stackBagPath): SodaScsResult {
    return SodaScsResult::success(
      message: 'Component restored from snapshot successfully.',
      data: [],
    );
  }

}
