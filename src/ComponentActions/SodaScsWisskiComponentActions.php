<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
   * The SCS helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpers
   */
  protected SodaScsHelpers $sodaScsHelpers;

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
   * The SCS Portainer helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers
   */
  protected SodaScsPortainerHelpers $sodaScsPortainerHelpers;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsPortainerServiceActions
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The SCS Project helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The SCS Service helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

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
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.helpers')]
    SodaScsHelpers $sodaScsHelpers,
    #[Autowire(service: 'soda_scs_manager.docker_exec_service.actions')]
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    SodaScsKeycloakHelpers $sodaScsKeycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.client.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.group.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    #[Autowire(service: 'soda_scs_manager.portainer.helpers')]
    SodaScsPortainerHelpers $sodaScsPortainerHelpers,
    #[Autowire(service: 'soda_scs_manager.portainer_service.actions')]
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    #[Autowire(service: 'soda_scs_manager.stack.helpers')]
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
    $this->fileSystem = $fileSystem;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsHelpers = $sodaScsHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsKeycloakHelpers = $sodaScsKeycloakHelpers;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsPortainerHelpers = $sodaScsPortainerHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create WissKI component.
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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component entity.
   *
   * @return array
   *   The created WissKI component configuration.
   */
  public function createComponent(SodaScsComponentInterface $component): array {
    try {

      // Get some basic information about the WissKI component.
      // Get the bundle info for the WissKI component.
      $bundleInfos = $this->bundleInfo->getBundleInfo('soda_scs_component');
      $wisskiComponentBundleInfo = $bundleInfos['soda_scs_wisski_component'] ?? NULL;
      if (!$wisskiComponentBundleInfo) {
        throw new \Exception('WissKI component bundle info not found');
      }
      // Set imageUrl and description for the WissKI component.
      $component->set('imageUrl', $wisskiComponentBundleInfo['imageUrl']);
      $component->set('description', $wisskiComponentBundleInfo['description']);

      // Get and set the machine name for the WissKI component.
      $machineName = 'wisski-' . $component->get('machineName')->value;
      $component->set('machineName', $machineName);

      // Collect the project colleques and user groups.
      // The owner of the component and all members of linked project
      // should have access to the WissKI instance. So we collect all
      // the project colleques for role assignment and the userGroups
      // for filesystem permissions groups.
      // Get the owner of the component.
      $owner = $component->getOwner();
      $projectColleques[] = $owner;
      $userGroups = [];

      // Get the members of the linked projects.
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectsItemList */
      $linkedProjectsItemList = $component->get('partOfProjects');
      $linkedProjects = $linkedProjectsItemList->referencedEntities();
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $linkedProject */
      foreach ($linkedProjects as $linkedProject) {
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectMembersItemList */
        $linkedProjectMembersItemList = $linkedProject->get('members');
        $linkedProjectMembers = $linkedProjectMembersItemList->referencedEntities();
        $projectColleques = array_merge($projectColleques, $linkedProjectMembers);
        $userGroups[] = $linkedProject->get('groupId')->value;
      }

      // Service key.
      // Create service key for WissKI component if it does not exist.
      $keyProps = [
        'bundle'  => 'soda_scs_wisski_component',
        'bundleLabel' => $wisskiComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'  => $component->getOwnerId(),
        'username' => $component->getOwner()->getDisplayName(),
      ];
      $wisskiComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $wisskiComponentServiceKeyPassword = $wisskiComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('WissKI service key password not found.');

      // Add the service key to the WissKI component entity.
      $component->serviceKey[] = $wisskiComponentServiceKeyEntity;

      // Get the connected components if any.
      // Get information about the connected SQL and triplestore components.
      $resolvedComponents = $this->sodaScsComponentHelpers->resolveConnectedComponents($component);
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $sqlComponent */
      $sqlComponent = $resolvedComponents['sql'];

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $triplestoreComponent */
      $triplestoreComponent = $resolvedComponents['triplestore'];

      // SQL connection if any.
      if ($sqlComponent) {
        // Set the database name.
        $dbName = $sqlComponent->get('machineName')->value;

        // Get the service key for the SQL component.
        $sqlKeyProps = [
          'bundle'  => 'soda_scs_sql_component',
          'type'  => 'password',
          'userId'  => $sqlComponent->getOwnerId(),
        ];

        // Get the service key for the SQL component.
        $sqlComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($sqlKeyProps) ?? throw new \Exception('SQL service key not found.');
        $sqlComponentServiceKeyPassword = $sqlComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('SQL service key password not found.');

      }

      // Triplestore connection if any.
      if ($triplestoreComponent) {

        $triplestoreComponentMachineName = $triplestoreComponent->get('machineName')->value;

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

      }

      // Get the flavours for the WissKI component.
      // @todo Implement the flavour logic.
      $flavoursList = $component->get('flavours')->value;

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

      // Create random openid connect client secret.
      $openidConnectClientSecret = $this->sodaScsComponentHelpers->createSecret();

      // Create openid connect client.
      $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();
      $wisskiInstanceUrl = str_replace('{instanceId}', $machineName, $wisskiInstanceSettings['baseUrl']);
      $keycloakBuildCreateClientRequest = $this->sodaScsKeycloakServiceClientActions->buildCreateRequest([
        'clientId' => $machineName,
        'name' => $component->get('label')->value,
        'description' => 'Change me',
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        'rootUrl' => $wisskiInstanceUrl,
        'adminUrl' => $wisskiInstanceUrl,
        'logoutUrl' => $wisskiInstanceUrl . '/logout',
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
      $uuidsOfwisskiAdmins[] = $this->sodaScsProjectHelpers->getUserSsoUuid($component->getOwner());
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

      if ($component->get('developmentInstance')->value) {
        $versionSettings = [
          'mode' => 'development',
          'varnishImageVersion' => $wisskiInstanceSettings['varnishImageDevelopmentVersion'],
          'wisskiComposeStackVersion' => $wisskiInstanceSettings['stackDevelopmentVersion'],
          'wisskiDefaultDataModelRecipeVersion' => $wisskiInstanceSettings['defaultDataModelRecipeDevelopmentVersion'],
          'wisskiBaseImageVersion' => $wisskiInstanceSettings['imageDevelopmentVersion'],
          'wisskiStarterRecipeVersion' => $wisskiInstanceSettings['starterRecipeDevelopmentVersion'],
          // Need to be empty for latest dev version.
          'wisskiVersion' => '',
        ];
      }
      else {
        // Get component version to look up version config.
        $componentVersion = $component->get('version')->value ?? '';
        $versionConfig = NULL;

        // Try to get version config from entities.
        if (!empty($componentVersion)) {
          $versionStorage = $this->entityTypeManager->getStorage('soda_scs_wisski_component_ver');
          $versionEntities = $versionStorage->loadMultiple();
          foreach ($versionEntities as $versionEntity) {
            /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $versionEntity */
            if ($versionEntity->getVersion() === $componentVersion) {
              $versionConfig = [
                'wisskiStack' => $versionEntity->getWisskiStack(),
                'wisskiImage' => $versionEntity->getWisskiImage(),
                'packageEnvironment' => $versionEntity->getPackageEnvironment(),
                'wisskiDefaultDataModelRecipe' => $versionEntity->getWisskiDefaultDataModelRecipe(),
                'wisskiStarterRecipe' => $versionEntity->getWisskiStarterRecipe(),
              ];
              break;
            }
          }
        }

        // Use version config if found, otherwise fall back to default values.
        if ($versionConfig) {
          $versionSettings = [
            'mode' => '',
            'varnishImageVersion' => '',
            'wisskiComposeStackVersion' => $versionConfig['wisskiStack'] ?? '',
            'wisskiDefaultDataModelRecipeVersion' => $versionConfig['wisskiDefaultDataModelRecipe'] ?? '',
            'wisskiBaseImageVersion' => $versionConfig['wisskiImage'] ?? '',
            'wisskiStarterRecipeVersion' => $versionConfig['wisskiStarterRecipe'] ?? '',
            'wisskiVersion' => $versionConfig['packageEnvironment'] ?? '',
          ];
        }
        else {
          // Fall back to default values from settings.
          $versionSettings = [
            'mode' => '',
            'varnishImageVersion' => '',
            'wisskiComposeStackVersion' => $wisskiInstanceSettings['wisskiStackProductionVersion'] ?? '',
            'wisskiDefaultDataModelRecipeVersion' => $wisskiInstanceSettings['defaultDataModelRecipeProductionVersion'] ?? '',
            'wisskiBaseImageVersion' => $wisskiInstanceSettings['wisskiBaseImageProductionVersion'] ?? '',
            'wisskiStarterRecipeVersion' => $wisskiInstanceSettings['starterRecipeProductionVersion'] ?? '',
            'wisskiVersion' => $componentVersion ?? '',
          ];
        }
      }

      //
      // Create the WissKI instance at portainer.
      // @todo Replace "wisski" at one point of the domain name.
      //
      $requestParams = [
        'dbName' => $dbName ?? '',
        'defaultLanguage' => $component->get('defaultLanguage')->value,
        'flavours' => $flavours,
        'keycloakAdminGroup' => $keycloakWisskiInstanceAdminGroupName,
        'keycloakUserGroup' => $keycloakWisskiInstanceUserGroupName,
        'machineName' => $machineName,
        'openidConnectClientSecret' => $openidConnectClientSecret,
        'sqlServicePassword' => $sqlComponentServiceKeyPassword ?? '',
        'triplestoreServicePassword' => $triplestoreComponentServiceKeyPassword ?? '',
        'triplestoreServiceToken' => $triplestoreComponentServiceTokenString ?? '',
        'tsRepository' => $triplestoreComponentMachineName ?? '',
        'userGroups' => $userGroups ? implode(' ', $userGroups) : '',
        'userId' => $component->getOwnerId(),
        'username' => $component->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKeyPassword ?? '',
        'wisskiType' => ($sqlComponent && $triplestoreComponent) ? 'bundled' : 'single',
        ...$versionSettings,
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
    $component->set('externalId', $portainerResponsePayload['Id']);
    $component->set('containerId', $containerId);
    $component->set('containerName', $containerName);
    $component->save();
    // Save the component.
    $component->save();

    $wisskiComponentServiceKeyEntity->scsComponent[] = $component->id();
    $wisskiComponentServiceKeyEntity->save();

    return [
      'message' => 'Created WissKI component.',
      'data' => [
        'wisskiComponent' => $component,
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

      //
      // Inspect if the WissKI component
      //
      // Construct the inspect request parameters.
      $inspectWisskicontainerRequestParams = [
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ];
      // Build and send the inspect request.
      $inspectWisskicontainerRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($inspectWisskicontainerRequestParams);
      $inspectWisskicontainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectWisskicontainerRequest);
      if (!$inspectWisskicontainerResponse['success']) {
        return SodaScsResult::failure(
          error: $inspectWisskicontainerResponse['error'],
          message: 'Snapshot creation failed: Could not inspect container.',
        );
      }
      $inspectWisskicontainerResponsePayload = json_decode($inspectWisskicontainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $wisskiContainerState = $inspectWisskicontainerResponsePayload['State'];

      if ($wisskiContainerState['Status'] !== 'running') {

        //
        // Stop the WissKI component container.
        //
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

        // Wait for the container to be stopped.
        $waitForContainerToStopResponse = $this->sodaScsContainerHelpers->waitForContainerState($component->get('containerId')->value, 'exited');
        if (!$waitForContainerToStopResponse->success) {
          return SodaScsResult::failure(
            error: $waitForContainerToStopResponse->error,
            message: 'Snapshot creation failed: Could not wait for container to stop.',
          );
        }
      }

      //
      // Create the snapshot container.
      //
      // Get the snapshot paths.
      $snapshotPaths = $this->sodaScsSnapshotHelpers->constructSnapshotPaths($component, $snapshotMachineName, (string) $timestamp);

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

      // Convert container path to host path for bind mount.
      // Inside Drupal container: /var/scs-manager/snapshots
      // On host: /srv/backups/scs-manager/snapshots.
      $hostBackupPath = $this->sodaScsSnapshotHelpers
        ->convertContainerPathToHostPath($snapshotPaths['backupPathWithType']);

      // Construct the snapshot container create request parameters.
      $createSnapshotContainerRunCommandRequestParams = [
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
            $hostBackupPath . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Build the create request for docker run command and send it.
      $createSnapshotContainerRunCommandRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($createSnapshotContainerRunCommandRequestParams);
      $createSnapshotContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createSnapshotContainerRunCommandRequest);

      if (!$createSnapshotContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $createSnapshotContainerResponse['error'],
          message: 'Snapshot creation failed: Could not create snapshot container.',
        );
      }

      // Get container ID from response.
      $snapshotContainerId = json_decode($createSnapshotContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      // Build the start request for docker run command and send it.
      $startSnapshotContainerRunCommandRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $snapshotContainerId,
        ],
      ]);
      $startSnapshotContainerRunCommandResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startSnapshotContainerRunCommandRequest);

      if (!$startSnapshotContainerRunCommandResponse['success']) {
        return SodaScsResult::failure(
          error: $startSnapshotContainerRunCommandResponse['error'],
          message: 'Snapshot creation failed: Could not start snapshot container.',
        );
      }

      // Construct component data for the snapshot.
      $componentData = [
        'componentBundle' => $component->bundle(),
        'componentId' => $component->id(),
        'componentMachineName' => $component->get('machineName')->value,
        'snapshotContainerId' => $snapshotContainerId,
        'snapshotContainerName' => $snapshotContainerName,
        'snapshotContainerStatus' => NULL,
        'snapshotContainerRemoved' => NULL,
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
          'snapshotDirectory' => $snapshotPaths['snapshotDirectory'],
          'timestamp' => $timestamp,
        ],
        'startSnapshotContainerResponse' => $startSnapshotContainerRunCommandResponse,
      ];

      $snapshotContainerData = SodaScsSnapshotData::fromArray($componentData);

      // Since we can snapshot whole stack with multiple components,
      // we need to construct an array with the component bundle as key
      // and the snapshot data.
      $containers = [
        $component->bundle() => $snapshotContainerData,
      ];

      //
      // Wait for the snapshot container to be finished.
      //
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

      //
      // Start the component container again.
      //
      // Construct the request parameters.
      $startWisskiContainerRequestParams = [
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ];

      // Build the start request for docker run command and send it.
      $startWisskiContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest($startWisskiContainerRequestParams);
      $startWisskiContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startWisskiContainerRequest);

      if (!$startWisskiContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startWisskiContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start container.',
        );
      }

      // Set the start WissKI container response.
      $snapshotContainerData->startWisskiContainerResponse = $startWisskiContainerResponse;

      // Return the success result.
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
    // Construct the request parameters.
    // @todo We should use the correct wording of the params (path, query, body, etc.).
    $requestParams = [
      'routeParams' => [
        'stackId' => $component->get('externalId')->value,
      ],
    ];
    try {
      // Build the get request for the portainer service.
      // to get the stack informations and send it.
      //
      $portainerGetRequest = $this->sodaScsPortainerServiceActions->buildGetRequest($requestParams);
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
      // Build the delete request with the informations from the portainer
      // service.
      $portainerDeleteRequest = $this->sodaScsPortainerServiceActions->buildDeleteRequest($requestParams);
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
      // Send the delete request to the portainer service.
      /** @var array $portainerResponse */
      $portainerDeleteResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerDeleteRequest);
      if (!$portainerDeleteResponse['success']) {
        $errorMessage = $portainerDeleteResponse['error'] ?? 'Unknown error occurred';
        Error::logException(
          $this->logger,
          new \Exception($errorMessage),
          'Could not delete WissKI stack at portainer: @message',
          ['@message' => $errorMessage],
          LogLevel::ERROR
        );
        $this->messenger->addError($this->t("Could not delete WissKI stack at portainer, but will delete the component anyway. See logs for more details."));
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot get WissKI component at portainer: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
    }

    // Delete connected docker volumes of the WissKI component.
    try {
      $removeVolumesOfComposeStackResponse = $this->sodaScsPortainerHelpers->removeVolumesOfComposeStack($component->get('machineName')->value);
      if (!$removeVolumesOfComposeStackResponse->success) {
        Error::logException(
          $this->logger,
          new \Exception($removeVolumesOfComposeStackResponse->error),
          'Cannot delete WissKI component at keycloak: @message',
          ['@message' => $removeVolumesOfComposeStackResponse->error],
          LogLevel::ERROR
        );
      }
    }
    catch (\Exception $e) {
      Error::logException(
      $this->logger,
      $e,
      'Cannot delete WissKI component at keycloak: @message',
      ['@message' => $e->getMessage()],
      LogLevel::ERROR
      );
    }

    try {
      // Delete the client in keycloak.
      // @todo export this to own helper function.
      //
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
            'portainerResponse' => $portainerDeleteResponse,
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
            'portainerResponse' => $portainerDeleteResponse,
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
            'portainerResponse' => $portainerDeleteResponse,
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
            'portainerResponse' => $portainerDeleteResponse,
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
          'portainerResponse' => $portainerDeleteResponse,
          'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
          'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
          'keycloakUserGroupResponse' => $keycloakMakeDeleteUserGroupResponse,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => $portainerDeleteResponse['statusCode'],
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
   * We get the container id of the WissKI component container,
   * because routes do not work with container names.
   * We stop the container gracefully. Wait for 30 seconds.
   * We back up current volume to rollback tar.
   * We restore into fresh state: purge volume and extract snapshot tar.
   * We start the original container again.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   * @param string|null $tempDirPath
   *   The path to the temporary directory.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   *
   * @todo Are rollback really working?
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDirPath): SodaScsResult {
    try {
      //
      // Collect information about the snapshot's WissKI component.
      //
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

      //
      // Check if the container is alreay stopped.
      // @todo Add a check if the container is already stopped.
      $inspectContainerRequestParams = [
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ];
      $inspectContainerRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($inspectContainerRequestParams);
      $inspectContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectContainerRequest);
      if (!$inspectContainerResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to inspect container.',
          error: (string) $inspectContainerResponse['error'],
        );
      }
      $inspectContainerResponseContents = json_decode($inspectContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $containerState = $inspectContainerResponseContents['State'];
      if ($containerState['Running'] == TRUE) {
        //
        // Stop the WissKI component container gracefully.
        //
        // Wait for 20 seconds before forcing stop container.
        $stopContainerRequestParams = [
          'routeParams' => [
            'containerId' => $containerId,
          ],
          'timeout' => 20,
        ];
        // Build and make the stop container request.
        $stopContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest($stopContainerRequestParams);
        $stopContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopContainerRequest);

        if (!$stopContainerResponse['success']) {
          return SodaScsResult::failure(
            message: 'Failed to stop container.',
            error: (string) $stopContainerResponse['error'],
          );
        }

        $waitForContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($containerId, 'exited');
        if (!$waitForContainerStateResponse->success) {
          return SodaScsResult::failure(
            message: 'Failed to wait for container to stop.',
            error: (string) $waitForContainerStateResponse->error,
          );
        }
      }

      //
      // Back up current volume to rollback tar,
      // then restore from snapshot into volume.
      //
      // Collect information about the snapshot.
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

      $rollbackContainerName = 'rollback--' . $machineName . '--drupal__' . $this->sodaScsSnapshotHelpers->generateRandomSuffix();

      // Create a short-lived container to back up the current volume.
      // Construct the request parameters.
      $rollbackContainerCreateRequestParams = [
        'name' => $rollbackContainerName,
        'image' => 'alpine:latest',
        'user' => '0:0',
        'cmd' => ['sh', '-c', 'tar czf /backup/' . basename($rollbackTarPath) . ' -C /source .'],
        'hostConfig' => [
          'Binds' => [
            $volumeName . ':/source:ro',
            $snapshotDir . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ];
      // Build and make the create container request.
      $rollbackContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($rollbackContainerCreateRequestParams);
      $rollbackContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerCreateRequest);

      // Check if the create container request was successful.
      if (!$rollbackContainerCreateResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to create rollback backup container.',
          error: (string) $rollbackContainerCreateResponse['error'],
        );
      }

      // Build and make the start container request.
      $rollbackContainerId = json_decode($rollbackContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $rollbackContainerStartRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $rollbackContainerId],
      ]);
      $rollbackContainerStartResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerStartRequest);
      if (!$rollbackContainerStartResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to start rollback backup container.',
          error: (string) $rollbackContainerStartResponse['error'],
        );
      }

      // Wait for the rollback container to finish.
      $waitForRollbackContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($rollbackContainerId, 'exited');
      if (!$waitForRollbackContainerStateResponse->success) {
        return SodaScsResult::failure(
          message: 'Failed to wait for rollback backup container to finish.',
          error: (string) $waitForRollbackContainerStateResponse->error,
        );
      }

      // Get the filepath on disk.
      // @todo This is a temporary solution to get the filepath on disk.
      // We need to find a better way to do this.
      $tarFilePathOnDisk = $tempDirPath . '/drupal-data';

      //
      // Restore into fresh state: purge original drupalvolume
      // and extract snapshot tar. Create restore container.
      //
      // Validate volume path for safe deletion (hardcoded /volume is safe,
      // but we validate to ensure no path manipulation).
      $volumePath = '/volume';
      $validationResult = $this->sodaScsHelpers->validatePathForSafeDeletion(
        $volumePath,
        forbiddenPaths: ['/', '/opt/drupal', '/opt/drupal/'],
        requiredPatterns: ['/\b(volume)\b/i'],
      );

      if (!$validationResult['isValid']) {
        throw new \RuntimeException(
          'Invalid volume path for restore operation: ' . htmlspecialchars($volumePath) . ' (' . $validationResult['errorCode'] . ')'
        );
      }

      // Construct the request parameters.
      $restoreCmd = [
        'sh',
        '-c',
        'rm -rf /volume/* /volume/.[!.]* /volume/..?* && ' .
        'tar -xzf /restore/' . basename($snapshotPath) . ' -C /volume && ' .
        'chown -R 33:33 /volume',
      ];

      $restoreContainerName = 'restore--' . $machineName . '--drupal__' . $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $restoreContainerCreateRequestParams = [
        'name' => $restoreContainerName,
        'image' => 'alpine:latest',
        'user' => '0:0',
        'cmd' => $restoreCmd,
        'hostConfig' => [
          'Binds' => [
            $volumeName . ':/volume',
            $tarFilePathOnDisk . ':/restore:ro',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Build and make the create restore container request.
      $restoreContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($restoreContainerCreateRequestParams);
      $restoreContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($restoreContainerCreateRequest);
      if (!$restoreContainerCreateResponse['success']) {
        // Delete the rollback container if the
        // restore container creation failed.
        $rollbackContainerDeleteRequestParams = [
          'routeParams' => ['containerId' => $rollbackContainerId],
        ];
        $rollbackContainerDeleteRequest = $this->sodaScsDockerRunServiceActions->buildRemoveRequest($rollbackContainerDeleteRequestParams);

        $rollbackContainerDeleteResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerDeleteRequest);
        if (!$rollbackContainerDeleteResponse['success']) {
          return SodaScsResult::failure(
            message: 'Failed to delete rollback container.',
            error: (string) $rollbackContainerDeleteResponse['error'],
          );
        }
        return SodaScsResult::failure(
          message: 'Failed to create restore container.',
          error: (string) $restoreContainerCreateResponse['error'],
        );
      }
      // Build and make the start container request.
      $restoreContainerId = json_decode($restoreContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

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

      // Wait for the restore container to finish.
      $waitForRestoreContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($restoreContainerId, 'exited');
      if (!$waitForRestoreContainerStateResponse->success) {
        return SodaScsResult::failure(
          message: 'Failed to wait for restore container to finish.',
          error: (string) $waitForRestoreContainerStateResponse->error,
        );
      }

      //
      // Start the original container again.
      //
      // Build and make the start container request.
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

      // Log the restore success.
      // @todo Fix this.
      $this->logger->info('WissKI component @name (@componentMachineName) restored from snapshot @snapshotName successfully.', [
        'name' => $component->label(),
        'componentMachineName' => $component->get('machineName')->value,
       // 'snapshotMachineName' => $snapshot->get('machineName')->value,
        'snapshotName' => $snapshot->label(),
      ]);

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

}
