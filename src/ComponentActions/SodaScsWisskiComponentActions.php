<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsWisskiComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The SCS Docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface
   */
  protected SodaScsExecRequestInterface $sodaScsDockerExecServiceActions;

  /**
   * The SCS Docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface
   */
  protected SodaScsRunRequestInterface $sodaScsDockerRunServiceActions;

  /**
   * The config config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  /**
   * The SCS component helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The SCS container helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

  /**
   * The SCS snapshot helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS Keycloak helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers
   */
  protected SodaScsKeycloakHelpers $sodaScsKeycloakHelpers;

  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions;


  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions;

  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The SCS Project helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Class constructor.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $bundleInfo,
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    FileSystemInterface $fileSystem,
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    SodaScsKeycloakHelpers $sodaScsKeycloakHelpers,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->bundleInfo = $bundleInfo;
    $this->config = $configFactory->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->fileSystem = $fileSystem;
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsKeycloakHelpers = $sodaScsKeycloakHelpers;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a WissKI component entity with necessary dependencies.
   *
   * This function handles the creation of a WissKI component by:
   * - Retrieving information about the connected SQL and
   *   triplestore components.
   * - Creating/retrieving a service key for authentication
   *   in dependend services.
   * - Create new openid connect client in keycloak and
   *   admin and user groups for the instance.
   * - Add the user to the wisski instance admin group.
   * - Creating a new WissKI instance at portainer.
   * - Create the Wisski component entity.
   * - Linking the component to its parent stack if necessary.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The parent SODa SCS stack or
   *   component that this WissKI component belongs to.
   *
   * @return array
   *   The created WissKI component configuration.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    try {

      // The owner of the entity and all members of linked project
      // should have access to the WissKI instance. So we collect all
      // the project colleques for role assignment and the userGroups
      // for filesystem permissions groups.
      // Get the owner of the component.
      $owner = $entity->getOwner();
      $projectColleques[] = $owner;
      $userGroups = [];

      // Get the members of the linked projects.
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectsItemList */
      $linkedProjectsItemList = $entity->get('partOfProjects');
      $linkedProjects = $linkedProjectsItemList->referencedEntities();
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $linkedProject */
      foreach ($linkedProjects as $linkedProject) {
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectMembersItemList */
        $linkedProjectMembersItemList = $linkedProject->get('members');
        $linkedProjectMembers = $linkedProjectMembersItemList->referencedEntities();
        $projectColleques = array_merge($projectColleques, $linkedProjectMembers);
        $userGroups[] = $linkedProject->get('groupId')->value;
      }

      // Get the bundle info for the WissKI component.
      $wisskiComponentBundleInfo = $this->bundleInfo->getBundleInfo('soda_scs_component')['soda_scs_wisski_component'];

      if (!$wisskiComponentBundleInfo) {
        throw new \Exception('WissKI component bundle info not found');
      }

      // Get the machine name for the WissKI component.
      $machineName = 'wisski-' . $entity->get('machineName')->value;

      // Get information about the connected SQL and triplestore components.
      // Get included SQL component if this is a stack (not a component)
      // @todo This is legacy code and should be refactored.
      if ($entity instanceof SodaScsStackInterface) {
        // Retrieve the SQL component that this WissKI component will use.
        $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_sql_component');
        if (!$sqlComponent) {
          throw new \Exception('SQL component not found for WissKI component');
        }
        $dbName = $sqlComponent->get('machineName')->value;
      }

      // Create service key for WissKI component if it does not exist.
      $keyProps = [
        'bundle'  => 'soda_scs_wisski_component',
        'bundleLabel' => $wisskiComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'  => $entity->getOwnerId(),
        'username' => $entity->getOwner()->getDisplayName(),
      ];
      $wisskiComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $wisskiComponentServiceKeyPassword = $wisskiComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('WissKI service key password not found.');

      // Create the WissKI component entity.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent */
      $wisskiComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_wisski_component',
          'label' => $entity->get('label')->value,
          'machineName' => $machineName,
          'owner'  => $entity->getOwner(),
          'description' => $wisskiComponentBundleInfo['description'],
          'imageUrl' => $wisskiComponentBundleInfo['imageUrl'],
          'flavours' => $entity->get('flavours')->value,
          'health' => 'Unknown',
          'partOfProjects' => $entity->get('partOfProjects'),
        ]
      );

      // Add the service key to the WissKI component entity.
      $wisskiComponent->serviceKey[] = $wisskiComponentServiceKeyEntity;

      // If it is a stack, we need to retrieve the included components.
      if ($entity instanceof SodaScsStackInterface) {
        // Set the WissKI type to stack.
        $wisskiType = 'stack';

        // Get the SQL component.
        $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_sql_component');

        // Get the service key for the SQL component.
        $sqlKeyProps = [
          'bundle'  => 'soda_scs_sql_component',
          'type'  => 'password',
          'userId'  => $sqlComponent->getOwnerId(),
        ];

        // Get the service key for the SQL component.
        $sqlComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($sqlKeyProps) ?? throw new \Exception('SQL service key not found.');
        $sqlComponentServiceKeyPassword = $sqlComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('SQL service key password not found.');

        // Get the triplestore component.
        $triplestoreComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($entity, 'soda_scs_triplestore_component');

        // Get the password for the triplestore component.
        $triplestoreKeyProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'password',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreKeyProps) ?? throw new \Exception('Triplestore service key not found.');
        $triplestoreComponentServiceKeyPassword = $triplestoreComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service key password not found.');

        // Get the service token for the triplestore component.
        $triplestoreTokenProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'token',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceTokenEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreTokenProps) ?? throw new \Exception('Triplestore service token not found.');
        $triplestoreComponentServiceTokenString = $triplestoreComponentServiceTokenEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service token not found.');

        // Get the flavours for the WissKI component.
        // @todo Implement the flavour logic.
        $flavoursList = $wisskiComponent->get('flavours')->value;

        // Initialize the flavours string.
        $flavours = '';

        // Process flavours array into a space-separated string.
        if (is_array($flavoursList)) {
          // Extract values from each flavour entry.
          foreach ($flavoursList as $flavour) {
            if (isset($flavour['value'])) {
              $flavoursArray[] = $flavour['value'];
            }
          }

          // Join flavours with spaces if any were found.
          if (!empty($flavours)) {
            $flavours = implode(' ', $flavoursArray);
          }
        }

      }
      else {
        // If it is not a stack we set the values to empty strings.
        $dbName = '';
        $wisskiComponentServiceKeyPassword = '';
        $sqlComponentServiceKeyPassword = '';
        $triplestoreComponentServiceKeyPassword = '';
        $triplestoreComponentServiceTokenString = '';
        $flavours = '';
        $wisskiType = 'component';
      }

      // Create random openid connect client secret.
      $openidConnectClientSecret = $this->sodaScsComponentHelpers->createSecret();

      // Create openid connect client.
      $keycloakBuildCreateClientRequest = $this->sodaScsKeycloakServiceClientActions->buildCreateRequest([
        // @todo Use url of component.
        'clientId' => $machineName,
        'name' => $entity->get('label')->value,
        'description' => 'Change me',
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        'rootUrl' => 'https://' . $machineName . '.wisski.scs.sammlungen.io',
        'adminUrl' => 'https://' . $machineName . '.wisski.scs.sammlungen.io',
        'logoutUrl' => 'https://' . $machineName . '.wisski.scs.sammlungen.io/logout',
        // @todo Use secret from service key.
        'secret' => $openidConnectClientSecret,
      ]);

      $keycloakMakeCreateClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildCreateClientRequest);
      if (!$keycloakMakeCreateClientResponse['success']) {
        throw new \Exception('Keycloak create client request failed: ' . $keycloakMakeCreateClientResponse['error']);
      }

      // Create keycloak group for admin.
      $keycloakWisskiInstanceAdminGroupName = $machineName . '-admin';

      $keycloakBuildCreateAdminGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $keycloakWisskiInstanceAdminGroupName,
          'path' => '/' . $keycloakWisskiInstanceAdminGroupName,
        ],
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);

      $keycloakMakeCreateAdminGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildCreateAdminGroupRequest);
      if (!$keycloakMakeCreateAdminGroupResponse['success']) {
        throw new \Exception('Keycloak create admin group request failed: ' . $keycloakMakeCreateAdminGroupResponse['error']);
      }

      // Create keycloak group for users.
      $keycloakWisskiInstanceUserGroupName = $machineName . '-user';

      $keycloakBuildCreateUserGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $keycloakWisskiInstanceUserGroupName,
          'path' => '/' . $keycloakWisskiInstanceUserGroupName,
        ],
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);

      $keycloakMakeCreateUserGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildCreateUserGroupRequest);
      if (!$keycloakMakeCreateUserGroupResponse['success']) {
        throw new \Exception('Keycloak create user group request failed: ' . $keycloakMakeCreateUserGroupResponse['error']);
      }

      // Get all groups from Keycloak for group ids.
      $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);
      $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

      if ($keycloakMakeGetAllGroupsResponse['success']) {
        $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        // Get the admin group id of the WissKI instance.
        $keycloakWisskiInstanceAdminGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceAdminGroupName) {
          return $group['name'] === $keycloakWisskiInstanceAdminGroupName;
        });
        $keycloakWisskiInstanceAdminGroup = reset($keycloakWisskiInstanceAdminGroup);
      }
      $uuidsOfwisskiAdmins = [];
      $uuidsOfwisskiAdmins[] = $this->sodaScsProjectHelpers->getUserSsoUuid($wisskiComponent->getOwner());
      foreach ($projectColleques as $member) {
        $uuidsOfwisskiAdmins[] = $this->sodaScsProjectHelpers->getUserSsoUuid($member);
      }

      // Iterate over the uuids of the wisski
      // admins and add them to the admin group.
      foreach ($uuidsOfwisskiAdmins as $uuidOfwisskiAdmin) {
        // Add user to admin group.
        $keycloakBuildAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
          'type' => 'addUserToGroup',
          'routeParams' => [
            'userId' => $uuidOfwisskiAdmin,
            'groupId' => $keycloakWisskiInstanceAdminGroup['id'],
          ],
          'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        ]);
        $keycloakMakeAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakBuildAddUserToGroupRequest);

        if (!$keycloakMakeAddUserToGroupRequest['success']) {
          throw new \Exception('Keycloak add user to admin group request failed: ' . $keycloakMakeAddUserToGroupRequest['error']);
        }
      }

      //
      // Create the WissKI instance at portainer.
      //
      $requestParams = [
        'dbName' => $dbName,
        'flavours' => $flavours,
        'keycloakAdminGroup' => $keycloakWisskiInstanceAdminGroupName,
        'keycloakUserGroup' => $keycloakWisskiInstanceUserGroupName,
        'machineName' => $machineName,
        'openidConnectClientSecret' => $openidConnectClientSecret,
        'userGroups' => $userGroups ? implode(' ', $userGroups) : '',
        'sqlServicePassword' => $sqlComponentServiceKeyPassword,
        'triplestoreServicePassword' => $triplestoreComponentServiceKeyPassword,
        'triplestoreServiceToken' => $triplestoreComponentServiceTokenString,
        'tsRepository' => $triplestoreComponent->get('machineName')->value,
        'userId' => $wisskiComponent->getOwnerId(),
        'username' => $wisskiComponent->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKeyPassword,
        'wisskiType' => $wisskiType,
      ];
      // Create the WissKI instance at portainer.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Request failed. See logs for more details."));
      return [
        'message' => 'Request failed.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      $portainerCreateRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerCreateRequest);
    }
    catch (\Exception $e) {
      $this->logger->error("Portainer request failed: @error", [
        '@error' => $e->getMessage(),
      ]);
      return [
        'message' => 'Portainer request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'portainerCreateRequestResult' => $portainerCreateRequestResult,
        ],
        'statusCode' => $portainerCreateRequestResult['statusCode'],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    if (!$portainerCreateRequestResult['success']) {
      return [
        'message' => 'Portainer request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'portainerCreateRequestResult' => $portainerCreateRequestResult,
        ],
        'statusCode' => $portainerCreateRequestResult['statusCode'],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    $portainerResponsePayload = json_decode($portainerCreateRequestResult['data']['portainerResponse']->getBody()->getContents(), TRUE);

    // Get the container name and id of the WissKI component container.
    // Construct the request parameters.
    $containerName = $machineName . '--drupal';
    $dockerGetAllContainersRequestParams = [
      'queryParams' => [
        'all' => TRUE,
        'filters' => json_encode(['name' => [$containerName]]),
      ],
    ];

    // Build and make the get all containers request.
    $dockerGetAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest($dockerGetAllContainersRequestParams);
    $dockerGetAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($dockerGetAllContainersRequest);
    if (!$dockerGetAllContainersResponse['success']) {
      return [
        'message' => 'Docker request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'dockerGetAllContainersResponse' => $dockerGetAllContainersResponse,
        ],
      ];
    }

    // Get the response payload.
    $dockerGetAllContainersResponsePayload = json_decode($dockerGetAllContainersResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
    $containerId = $dockerGetAllContainersResponsePayload[0]['Id'];

    // Set the external ID and container name and id.
    $wisskiComponent->set('externalId', $portainerResponsePayload['Id']);
    $wisskiComponent->set('containerId', $containerId);
    $wisskiComponent->set('containerName', $containerName);

    // Save the component.
    $wisskiComponent->save();

    $wisskiComponentServiceKeyEntity->scsComponent[] = $wisskiComponent->id();
    $wisskiComponentServiceKeyEntity->save();

    return [
      'message' => 'Created WissKI component.',
      'data' => [
        'wisskiComponent' => $wisskiComponent,
        'portainerCreateRequestResult' => $portainerCreateRequestResult,

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

      // Stop the container.
      // Construct the request parameters.
      $stopWisskiContainerRequestParams = [
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ];

      // Build and make the stop container request.
      $stopWisskiContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest($stopWisskiContainerRequestParams);
      $stopWisskiContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopWisskiContainerRequest);
      if (!$stopWisskiContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $stopWisskiContainerResponse['error'],
          message: 'Snapshot creation failed: Could not stop container.',
        );
      }

      // @todo Check if the container is stopped.
      // @todo Abstract this.
      $snapshotPaths = $this->sodaScsSnapshotHelpers->constructSnapshotPaths($component, $snapshotMachineName, $timestamp);

      // Create the backup directory.
      $dirCreateResult = $this->sodaScsSnapshotHelpers->createDir($snapshotPaths['backupPathWithType']);
      if (!$dirCreateResult['success']) {
        return SodaScsResult::failure(
          error: $dirCreateResult['error'],
          message: 'Snapshot creation failed: Could not create backup directory.',
        );
      }

      // We need a random int to avoid conflicts with other snapshots.
      $randomInt = $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $snapshotContainerName = 'snapshot--' . $randomInt . '--' . $snapshotMachineName . '--drupal';
      // Create and run the snapshot container to create tar and sha256 file.
      $createSnapshotContainerRequestParams = [
        'name' => $snapshotContainerName,
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
            $component->get('machineName')->value . '_drupal-root:/source',
            $snapshotPaths['backupPathWithType'] . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Make the create container request.
      $createSnapshotContainerRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($createSnapshotContainerRequestParams);
      $createSnapshotContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createSnapshotContainerRequest);

      if (!$createSnapshotContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $createSnapshotContainerResponse['error'],
          message: 'Snapshot creation failed: Could not create snapshot container.',
        );
      }

      // Get container ID from response.
      $snapshotContainerId = json_decode($createSnapshotContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      // Make the start container request.
      $startSnapshotContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $snapshotContainerId,
        ],
      ]);
      $startSnapshotContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startSnapshotContainerRequest);

      if (!$startSnapshotContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startSnapshotContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start snapshot container.',
        );
      }

      $componentData = [
        'componentBundle' => $component->bundle(),
        'componentId' => $component->id(),
        'componentMachineName' => $component->get('machineName')->value,
        'snapshotContainerId' => $snapshotContainerId,
        'snapshotContainerName' => $snapshotContainerName,
        'createSnapshotContainerResponse' => $createSnapshotContainerResponse,
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
          'timestamp' => $timestamp,
        ],
        'startSnapshotContainerResponse' => $startSnapshotContainerResponse,
      ];

      $snapshotContainerData = SodaScsSnapshotData::fromArray($componentData);

      $containers = [
        $component->bundle() => $snapshotContainerData,
      ];

      // Wait for the container to be finished.
      $waitForSnapshotContainerToFinishResponse = $this->sodaScsContainerHelpers->waitContainersToFinish(
        $containers,
        FALSE,
        'snapshot creation');
      if (!$waitForSnapshotContainerToFinishResponse->success) {
        return SodaScsResult::failure(
          error: $waitForSnapshotContainerToFinishResponse->error,
          message: 'Snapshot creation failed: Could not wait for container to finish.',
        );
      }

      // Start the component container again.
      $startWisskiContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ]);
      $startWisskiContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startWisskiContainerRequest);

      if (!$startWisskiContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startWisskiContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start container.',
        );
      }

      $snapshotContainerData->startWisskiContainerResponse = $startWisskiContainerResponse;

      return SodaScsResult::success(
        data: [
          $component->bundle() => $snapshotContainerData,
        ],
        message: 'Created and started snapshot container successfully.',
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
   * Get all WissKI Components.
   *
   * @return array
   *   The result array with the WissKI components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Retrieves a SODa SCS WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to retrieve.
   *
   * @return array
   *   The result array of the created component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Updates a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to update.
   *
   * @return array
   *   The result array of the created component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Deletes a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to delete.
   *
   * @return array
   *   The result array of the created component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    $queryParams['externalId'] = $component->get('externalId')->value;
    try {
      $portainerGetRequest = $this->sodaScsPortainerServiceActions->buildGetRequest($queryParams);
      $portainerGetResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerGetRequest);
      if (!$portainerGetResponse['success']) {
        return [
          'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
          'data' => [
            'portainerResponse' => $portainerGetResponse,
            'keycloakClientResponse' => NULL,
            'keycloakAdminGroupResponse' => NULL,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $portainerGetResponse['error'],
          'statusCode' => $portainerGetResponse['statusCode'],
        ];
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot get WissKI component at portainer: @message',
        ['@component' => $component->getLabel(), '@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot get WissKI component @component at portainer. See logs for more details.", ['@component' => $component->getLabel()]));
      return [
        'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      $portainerDeleteRequest = $this->sodaScsPortainerServiceActions->buildDeleteRequest($queryParams);
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot assemble WissKI delete request: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot assemble WissKI component delete request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      /** @var array $portainerResponse */
      $requestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerDeleteRequest);
      if (!$requestResult['success']) {
        Error::logException(
          $this->logger,
          new \Exception($requestResult['error']),
          'Could not delete WissKI stack at portainer: @message',
          ['@message' => $requestResult['error']],
          LogLevel::ERROR
        );
        $this->messenger->addError($this->t("Could not delete WissKI stack at portainer, but will delete the component anyway. See logs for more details."));
      }

      // Request keycloak token.
      $keycloakBuildTokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
      $keycloakMakeTokenRequest = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildTokenRequest);
      if (!$keycloakMakeTokenRequest['success']) {
        throw new \Exception('Keycloak token request failed.');
      }
      $keycloakTokenResponseContents = json_decode($keycloakMakeTokenRequest['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $keycloakToken = $keycloakTokenResponseContents['access_token'];

      // Get client uuid.
      $getAllClientsRequestParams = [
        'queryParams' => [
          'clientId' => $component->get('machineName')->value,
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildGetAllClientRequest = $this->sodaScsKeycloakServiceClientActions->buildGetAllRequest($getAllClientsRequestParams);
      $keycloakMakeGetAllClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildGetAllClientRequest);
      if (!$keycloakMakeGetAllClientResponse['success']) {
        return [
          'message' => 'Cannot get WissKI component client uuid at keycloak.',
          'data' => [
            'portainerResponse' => $requestResult,
            'keycloakClientResponse' => $keycloakMakeGetAllClientResponse,
          ],
        ];
      }
      $keycloakGetAllClientResponseContents = json_decode($keycloakMakeGetAllClientResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $clientUuid = $keycloakGetAllClientResponseContents[0]['id'];

      // Delete the client in keycloak.
      $deleteRequestParams = [
        'routeParams' => [
          'clientUuid' => $clientUuid,
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteClientRequest = $this->sodaScsKeycloakServiceClientActions->buildDeleteRequest($deleteRequestParams);
      $keycloakMakeDeleteClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildDeleteClientRequest);

      if (!$keycloakMakeDeleteClientResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component at keycloak.',
          'data' => [
            'portainerResponse' => $requestResult,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => NULL,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteClientResponse['statusCode'],
        ];
      }

      $keycloakWisskiInstanceAdminGroupName = $component->get('machineName')->value . '-admin';
      $keycloakWisskiInstanceUserGroupName = $component->get('machineName')->value . '-user';
      // Get all groups from Keycloak for group ids.
      $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $keycloakToken,
      ]);
      $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

      if ($keycloakMakeGetAllGroupsResponse['success']) {
        $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        // Get the admin group id of the WissKI instance.
        // Get admin group details.
        $keycloakWisskiInstanceAdminGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceAdminGroupName) {
          return $group['name'] === $keycloakWisskiInstanceAdminGroupName;
        });
        $keycloakWisskiInstanceAdminGroup = reset($keycloakWisskiInstanceAdminGroup);

        // Get user group details.
        $keycloakWisskiInstanceUserGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceUserGroupName) {
          return $group['name'] === $keycloakWisskiInstanceUserGroupName;
        });
        $keycloakWisskiInstanceUserGroup = reset($keycloakWisskiInstanceUserGroup);
      }

      // Delete the admin group in keycloak.
      $deleteAdminGroupRequestParams = [
        'routeParams' => [
          'groupId' => $keycloakWisskiInstanceAdminGroup['id'],
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteAdminGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest($deleteAdminGroupRequestParams);
      $keycloakMakeDeleteAdminGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildDeleteAdminGroupRequest);

      if (!$keycloakMakeDeleteAdminGroupResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component admin group at keycloak.',
          'data' => [
            'portainerResponse' => $requestResult,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteAdminGroupResponse['statusCode'],
        ];
      }

      // Delete the user group in keycloak.
      $deleteUserGroupRequestParams = [
        'routeParams' => [
          'groupId' => $keycloakWisskiInstanceUserGroup['id'],
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteUserGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest($deleteUserGroupRequestParams);
      $keycloakMakeDeleteUserGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildDeleteUserGroupRequest);

      if (!$keycloakMakeDeleteUserGroupResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component user group at keycloak.',
          'data' => [
            'portainerResponse' => $requestResult,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
            'keycloakUserGroupResponse' => $keycloakMakeDeleteUserGroupResponse,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteUserGroupResponse['statusCode'],
        ];
      }

      // Delete the component.
      $component->delete();

      return [
        'message' => 'Deleted WissKI component.',
        'data' => [
          'portainerResponse' => $requestResult,
          'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
          'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
          'keycloakUserGroupResponse' => $keycloakMakeDeleteUserGroupResponse,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => $requestResult['statusCode'],
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot delete WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot delete WissKI component. See logs for more details."));
      return [
        'message' => 'Cannot delete WissKI component.',
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
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
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $component */
      $component = $snapshot->get('snapshotOfComponent')->entity ?? NULL;
      if (!$component) {
        return SodaScsResult::failure(
          message: 'Snapshot is not linked to a component.',
          error: 'Missing component on snapshot.',
        );
      }

      $machineName = $component->get('machineName')->value;
      $containerName = $machineName . '--drupal';

      // Get the container id of the WissKI component container,
      // because routes do not work with container names.
      $getAllContainersRequestParams = [
        'queryParams' => [
          'all' => TRUE,
          'filters' => json_encode(['name' => [$containerName]]),
        ],
      ];
      $getAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest($getAllContainersRequestParams);
      $getAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($getAllContainersRequest);
      if (!$getAllContainersResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to get container id.',
          error: (string) $getAllContainersResponse['error'],
        );
      }
      $getAllContainersResponseContents = json_decode($getAllContainersResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $containerId = $getAllContainersResponseContents[0]['Id'];

      // Stop the container gracefully. Wait for 30 seconds.
      $stopContainerRequestParams = [
        'routeParams' => [
          'containerId' => $containerId,
        ],
        'queryParams' => [
          't' => 30,
        ],
      ];
      $stopContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest($stopContainerRequestParams);
      $stopContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopContainerRequest);
      if (!$stopContainerResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to stop container.',
          error: (string) $stopContainerResponse['error'],
        );
      }
      // Back up current volume to rollback tar,
      // then restore from snapshot into volume.
      $volumeName = $machineName . '_drupal-root';
      /** @var \Drupal\file\Entity\File|null $snapshotFile */
      $snapshotFile = $snapshot->getFile();
      if (!$snapshotFile) {
        return SodaScsResult::failure(
          message: 'Snapshot file not found on entity.',
          error: 'Missing snapshot file.',
        );
      }
      $snapshotUri = $snapshotFile->getFileUri();
      $snapshotPath = $this->fileSystem->realpath($snapshotUri);
      if (!$snapshotPath || !file_exists($snapshotPath)) {
        return SodaScsResult::failure(
          message: 'Snapshot file does not exist on filesystem.',
          error: 'Snapshot file missing at path.',
        );
      }
      $snapshotDir = dirname($snapshotPath);
      $rollbackTarName = 'rollback--' . $volumeName . '--' . date('Ymd-His') . '.tar.gz';
      $rollbackTarPath = $snapshotDir . '/' . $rollbackTarName;

      // Create a short-lived container to back up the current volume.
      $backupContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest([
        'name' => 'rollback--' . $machineName . '--drupal',
        'image' => 'alpine:latest',
        'user' => '0:0',
        'cmd' => ['sh', '-c', 'tar czf /backup/' . basename($rollbackTarPath) . ' -C /source .'],
        'hostConfig' => [
          'Binds' => [
            $volumeName . ':/source:ro',
            $snapshotDir . ':/backup',
          ],
          'AutoRemove' => TRUE,
        ],
      ]);
      $backupContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($backupContainerCreateRequest);
      if (!$backupContainerCreateResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to create rollback backup container.',
          error: (string) $backupContainerCreateResponse['error'],
        );
      }
      $backupContainerId = json_decode($backupContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $backupContainerStartRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $backupContainerId],
      ]);
      $backupContainerStartResponse = $this->sodaScsDockerRunServiceActions->makeRequest($backupContainerStartRequest);
      if (!$backupContainerStartResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to start rollback backup container.',
          error: (string) $backupContainerStartResponse['error'],
        );
      }

      // Restore into fresh state: purge volume and extract snapshot tar.
      // Create restore container.
      $restoreCmd = [
        'sh',
        '-c',
        'rm -rf /volume/* /volume/.[!.]* /volume/..?* && ' .
        'tar -xzf /restore/' . basename($snapshotPath) . ' -C /volume && ' .
        'chown -R 33:33 /volume',
      ];
      $restoreContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest([
        'name' => 'restore--' . $machineName . '--drupal',
        'image' => 'alpine:latest',
        'user' => '0:0',
        'cmd' => $restoreCmd,
        'hostConfig' => [
          'Binds' => [
            $volumeName . ':/volume',
            $snapshotDir . ':/restore:ro',
          ],
          // @todo CHANGE THIS TO TRUE WHEN DONE TESTING.
          'AutoRemove' => FALSE,
        ],
      ]);
      $restoreContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($restoreContainerCreateRequest);
      if (!$restoreContainerCreateResponse['success']) {
        // Delete the backup container if the
        // restore container creation failed.
        $backupContainerDeleteRequestParams = [
          'routeParams' => ['containerId' => $backupContainerId],
        ];
        $backupContainerDeleteRequest = $this->sodaScsDockerRunServiceActions->buildRemoveRequest($backupContainerDeleteRequestParams);

        $backupContainerDeleteResponse = $this->sodaScsDockerRunServiceActions->makeRequest($backupContainerDeleteRequest);
        if (!$backupContainerDeleteResponse['success']) {
          return SodaScsResult::failure(
            message: 'Failed to delete backup container.',
            error: (string) $backupContainerDeleteResponse['error'],
          );
        }
        return SodaScsResult::failure(
          message: 'Failed to create restore container.',
          error: (string) $restoreContainerCreateResponse['error'],
        );
      }
      $restoreContainerId = json_decode($restoreContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      // Start the restore container.
      $restoreContainerStartRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $restoreContainerId],
      ]);
      $restoreContainerStartResponse = $this->sodaScsDockerRunServiceActions->makeRequest($restoreContainerStartRequest);
      if (!$restoreContainerStartResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to start restore container.',
          error: (string) $restoreContainerStartResponse['error'],
        );
      }

      // Start the original container again.
      $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $containerId],
      ]);
      $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);
      if (!$startContainerResponse['success']) {
        return SodaScsResult::failure(
          message: 'Restore completed, but failed to start the container.',
          error: (string) $startContainerResponse['error'],
        );
      }

      return SodaScsResult::success(
        message: 'WissKI component restored from snapshot successfully.',
        data: [
          'containerId' => $containerId,
          'volumeName' => $volumeName,
          'rollbackTarPath' => $rollbackTarPath,
          'snapshotPath' => $snapshotPath,
        ],
      );

    }
    catch (\Throwable $e) {
      Error::logException(
        $this->logger,
        $e,
        'WissKI restore failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return SodaScsResult::failure(
        message: 'Failed to restore component from snapshot.',
        error: $e->getMessage(),
      );
    }
  }

  /**
   * Recursively copies a directory.
   *
   * @param string $source
   *   Source directory path.
   * @param string $destination
   *   Destination directory path.
   */
  private function copyDirectoryRecursively(string $source, string $destination): void {
    $source = rtrim($source, '/');
    $destination = rtrim($destination, '/');
    if (!is_dir($source)) {
      throw new \RuntimeException('Source directory does not exist: ' . $source);
    }
    if (!is_dir($destination) && !mkdir($destination, 0755, TRUE) && !is_dir($destination)) {
      throw new \RuntimeException('Failed to create destination directory: ' . $destination);
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
      $absolutePath = $item->getPathname();
      $relative = ltrim(substr($absolutePath, strlen($source)), DIRECTORY_SEPARATOR);
      $targetPath = $destination . DIRECTORY_SEPARATOR . $relative;
      if ($item->isDir()) {
        if (!is_dir($targetPath) && !mkdir($targetPath, 0755, TRUE) && !is_dir($targetPath)) {
          throw new \RuntimeException('Failed to create directory: ' . $targetPath);
        }
      }
      else {
        if (!copy($item->getPathname(), $targetPath)) {
          throw new \RuntimeException('Failed to copy file to: ' . $targetPath);
        }
      }
    }
  }

  /**
   * Recursively removes a directory or file.
   *
   * @param string $path
   *   Path to remove.
   */
  private function removeDirectoryRecursively(string $path): void {
    if (!file_exists($path)) {
      return;
    }
    if (is_file($path) || is_link($path)) {
      @unlink($path);
      return;
    }
    $iterator = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
      \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $fileinfo) {
      if ($fileinfo->isDir()) {
        @rmdir($fileinfo->getRealPath());
      }
      else {
        @unlink($fileinfo->getRealPath());
      }
    }
    @rmdir($path);
  }

  /**
   * Checks whether a directory is empty.
   *
   * @param string $dir
   *   Directory path.
   *
   * @return bool
   *   TRUE if empty, FALSE otherwise.
   */
  private function directoryIsEmpty(string $dir): bool {
    if (!is_dir($dir)) {
      return TRUE;
    }
    $files = scandir($dir) ?: [];
    return count($files) <= 2;
  }

}
