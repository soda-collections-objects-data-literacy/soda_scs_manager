<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\user\EntityOwnerTrait;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;


/**
 * Form controller for the ScsComponent entity create form.
 *
 * The form is used to create a new component entity.
 * It saves the entity with the fields:
 * - user: The user ID of the user who created the entity.
 * - created: The time the entity was created.
 * - updated: The time the entity was updated.
 * - label: The label of the entity.
 * - notes: Private notes of the user for the entity.
 * - description: The description of the entity (comes from bundle).
 * - image: The image of the entity (comes from bundle).
 * and redirects to the components page.
 */
class SodaScsProjectCreateForm extends ContentEntityForm {

  use EntityOwnerTrait;

  /**
   * The SODa SCS Component bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsComponentActions;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions;

  /**
   * The Soda SCS Keycloak Service Client Actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions;

  /**
   * The Soda SCS Keycloak Service Group Actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions;

  /**
   * The Soda SCS Keycloak Service User Actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions;

  /**
   * The Soda SCS Project Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The Soda SCS SQL Service Actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceSqlServiceActionInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  /**
   * Constructs a new SodaScsComponentCreateForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsComponentActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface $sodaScsSqlServiceActions
   *   The Soda SCS SQL Service Actions service.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    TimeInterface $time,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->sodaScsComponentActions = $sodaScsComponentActions;
    $this->sodaScsDockerRegistryServiceActions = $sodaScsDockerRegistryServiceActions;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('soda_scs_manager.component.actions'),
      $container->get('soda_scs_manager.docker_registry_service.actions'),
      $container->get('soda_scs_manager.keycloak_service.client.actions'),
      $container->get('soda_scs_manager.keycloak_service.group.actions'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
      $container->get('soda_scs_manager.project.helpers'),
      $container->get('soda_scs_manager.sql_service.actions'),
      $container->get('datetime.time'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string|null $bundle = NULL) {

    $this->bundle = $bundle;

    // Build the form.
    $form = parent::buildForm($form, $form_state);

    $form['owner']['widget']['#default_value'] = $this->currentUser->id();
    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Add a description to the form.
    $form['description'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t('Create a new project to organize your components.') . '</p>',
      '#weight' => -10,
    ];

    // Restrict connectedComponents field to only
    // show components owned by the current user
    // unless they have admin permission.
    if (isset($form['connectedComponents'])) {
      $uid = $this->currentUser->id();
      $is_admin = $this->currentUser->hasPermission('soda scs manager admin');

      if (!$is_admin) {
        // Modify the selection handler settings to only show user's components.
        $form['connectedComponents']['widget']['#selection_settings']['filter'] = [
          'owner' => $uid,
        ];
      }
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    /* @todo Move this to a separate service. */
    // Assign rights to all components...
    foreach ($form_state->getValue('connectedComponents') as $componentId) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
      $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId['target_id']);
      // ...and members mentioned in the form.
      $members = $form_state->getValue('members');
      foreach ($members as $memberId) {
        if (!is_array($memberId) || !isset($memberId['target_id'])) {
          continue;
        }
        /** @var \Drupal\user\Entity\User $member */
        $member = $this->entityTypeManager->getStorage('user')->load($memberId['target_id']);
        switch ($component->bundle()) {
          case 'soda_scs_wisski_component':
            $wisskiComponent = $component;
            $wisskiMachineName = $wisskiComponent->machineName->value;
            // Add group to keycloak user.
            // First get a token for the Keycloak service client.
            $keycloakBuildTokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
            $keycloakMakeTokenRequest = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildTokenRequest);
            if (!$keycloakMakeTokenRequest['success']) {
              Error::logException(
                $this->logger,
                new \Exception('Keycloak token request failed.'),
                'Keycloak token request failed. @message',
                ['@message' => $keycloakMakeTokenRequest['error']],
                LogLevel::ERROR
              );
              $form_state->setErrorByName('connectedComponents', $this->t('Keycloak token request failed. See logs for more details.'));
              break;
            }
            $keycloakTokenResponseContents = json_decode($keycloakMakeTokenRequest['data']['keycloakResponse']->getBody()->getContents(), TRUE);
            $keycloakToken = $keycloakTokenResponseContents['access_token'];

            // Give rigths to the group members.
            // 1. We need the UUID of the WissKI User Instance Group .
            // Build the name of the group to add the user to.
            $keycloakWisskiInstanceUserGroupName = $wisskiMachineName . '-user';

            // Get all groups from Keycloak for group ids.
            $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
              'token' => $keycloakToken,
            ]);
            $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

            if (!$keycloakMakeGetAllGroupsResponse['success']) {
              Error::logException(
                $this->logger,
                new \Exception('Keycloak get all groups request failed.'),
                'Keycloak get all groups request failed. @message',
                ['@message' => $keycloakMakeGetAllGroupsResponse['error']],
                LogLevel::ERROR
              );
              $form_state->setErrorByName('connectedComponents', $this->t('Keycloak get all groups request failed. See logs for more details.'));
              break;
            }

            $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
            // Get the admin group id of the WissKI instance.
            $keycloakWisskiInstanceUserGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceUserGroupName) {
              return $group['name'] === $keycloakWisskiInstanceUserGroupName;
            });
            $keycloakWisskiInstanceUserGroup = reset($keycloakWisskiInstanceUserGroup);

            // 2. We need the UUID of the keycloak user.
            // Set up parameters to search for the keycloak user.
            $getUserParams = [
              'token' => $keycloakToken,
              'queryParams' => [
                'username' => $member->getDisplayName(),
              ],
            ];

            // Get the user from Keycloak via getAllUsers,
            // because wie do not have the uuid, but only the username.
            $getAllUsersRequest = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest($getUserParams);
            $getAllUsersResponse = $this->sodaScsKeycloakServiceUserActions->makeRequest($getAllUsersRequest);

            if (!$getAllUsersResponse['success']) {
              Error::logException(
                $this->logger,
                new \Exception('Keycloak get all users request failed.'),
                'Keycloak get all users request failed. @message',
                ['@message' => $getAllUsersResponse['error']],
                LogLevel::ERROR
              );
              $form_state->setErrorByName('connectedComponents', $this->t('Keycloak get all users request failed. See logs for more details.'));
              break;
            }

            $allUserData = json_decode($getAllUsersResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
            // Extract the UUID if user is found.
            if (!empty($allUserData) && is_array($allUserData) && count($allUserData) > 0) {
              $userData = $allUserData[0];
            }
            else {
              Error::logException(
                $this->logger,
                new \Exception('Keycloak user not found.'),
                'Keycloak user @username not found.',
                ['@username' => $member->getDisplayName()],
                LogLevel::ERROR
              );
              $form_state->setErrorByName(
                'connectedComponents',
                $this->t('Keycloak user not found. See logs for more details.'),
              );
              break;
            }

            // 3. Add user to admin group.
            $keycloakBuildAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
              'type' => 'group',
              'routeParams' => [
                'userId' => $userData['id'],
                'groupId' => $keycloakWisskiInstanceUserGroup['id'],
              ],
              'token' => $keycloakToken,
            ]);
            $keycloakMakeAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakBuildAddUserToGroupRequest);

            if (!$keycloakMakeAddUserToGroupRequest['success']) {
              Error::logException(
                $this->logger,
                new \Exception('Keycloak add user to admin group request failed.'),
                'Keycloak add user to admin group request failed. @message',
                ['@message' => $keycloakMakeAddUserToGroupRequest['error']],
                LogLevel::ERROR
              );
              $form_state->setErrorByName(
                'connectedComponents',
                $this->t('Keycloak add user to admin group request failed: @error', [
                  '@error' => $keycloakMakeAddUserToGroupRequest['error'],
                ]),
              );
            }
            break;

          case 'soda_scs_sql_component':
            $sqlComponent = $component;
            $sqlMachineName = $sqlComponent->machineName->value;

            // Grant all privileges for the database user.
            $this->sodaScsSqlServiceActions->grantServiceRights($member->getDisplayName(), $sqlMachineName, ['ALL']);

            break;

          case 'soda_scs_triplestore_component':
            $triplestoreComponent = $component;
            $triplestoreMachineName = $triplestoreComponent->machineName->value;
            break;

          default:
            $this->messenger()->addWarning($this->t('Component @component is not supported yet.', [
              '@component' => $component->label(),
            ]));
            break;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);

    // Create Keycloak group for the project using the group ID.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;

    // Sync keycloak group members with owner and project members.
    $syncKeycloakGroupMembersResponse = $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);
    if (!$syncKeycloakGroupMembersResponse['success']) {
      $this->messenger()->addError($this->t('Failed to sync keycloak group members for project @project: See logs for details.', [
        '@project' => $project->label(),
      ]));
      $this->logger->error('Failed to sync keycloak group members for project @project: @error', [
        '@project' => $project->label(),
        '@error' => $syncKeycloakGroupMembersResponse['error'],
      ]);
    }

    $this->messenger()->addMessage($this->t('Project @project has been created.', [
      '@project' => $this->entity->label(),
    ]));
    // Redirect to the project listing page.
    $form_state->setRedirect('entity.soda_scs_project.canonical', ['soda_scs_project' => $this->entity->id()]);
  }

}
