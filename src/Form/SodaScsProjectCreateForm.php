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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\user\EntityOwnerTrait;
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
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

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
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsComponentActions;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

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
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    EntityTypeManagerInterface $entityTypeManager,
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    TimeInterface $time,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->sodaScsComponentActions = $sodaScsComponentActions;
    $this->sodaScsDockerRegistryServiceActions = $sodaScsDockerRegistryServiceActions;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
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
      $container->get('soda_scs_manager.component.actions'),
      $container->get('soda_scs_manager.docker_registry_service.actions'),
      $container->get('soda_scs_manager.keycloak_service.client.actions'),
      $container->get('soda_scs_manager.keycloak_service.group.actions'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
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

    // Make the machineName field readonly and add
    // JavaScript to auto-generate it.
    if (isset($form['machineName'])) {
      // @todo Check if there is a better way to do this.
      // Add CSS classes for machine name generation.
      $form['label']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-source';
      $form['machineName']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-target';
      // Make the machine name field read-only.
      $form['machineName']['widget'][0]['value']['#attributes']['readonly'] = 'readonly';
      // Attach JavaScript to auto-generate machine name.
      $form['#attached']['library'][] = 'soda_scs_manager/machine-name-generator';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // Check if a project with this machine name already exists.
    $machineName = $form_state->getValue('machineName')[0]['value'];
    $query = $this->entityTypeManager->getStorage('soda_scs_project')
      ->getQuery()
      ->condition('machineName', $machineName)
      ->accessCheck(FALSE);
    $entities = $query->execute();

    if (!empty($entities)) {
      $form_state->setErrorByName('machineName', $this->t('A project with machine name "@machine_name" already exists. Please choose a different name.', [
        '@machine_name' => $machineName,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    #parent::submitForm($form, $form_state);

    /* @todo Move this to a separate service. */
    foreach ($form_state->getValue('connectedComponents') as $componentId) {
      $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId['target_id']);
      switch ($component->bundle()) {
        case 'soda_scs_wisski_component':
          $wisskiComponent = $component;
          $wisskiMachineName = $wisskiComponent->machineName->value;
          // Add group to keycloak user.
          $keycloakBuildTokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
          $keycloakMakeTokenRequest = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildTokenRequest);
          if (!$keycloakMakeTokenRequest['success']) {
            throw new \Exception('Keycloak token request failed.');
          }
          $keycloakTokenResponseContents = json_decode($keycloakMakeTokenRequest['data']['keycloakResponse']->getBody()->getContents(), TRUE);
          $keycloakToken = $keycloakTokenResponseContents['access_token'];

          $keycloakWisskiInstanceUserGroupName = $wisskiMachineName . '-user';

          // Get all groups from Keycloak for group ids.
          $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
            'token' => $keycloakToken,
          ]);
          $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

          if ($keycloakMakeGetAllGroupsResponse['success']) {
            $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
            // Get the admin group id of the WissKI instance.
            $keycloakWisskiInstanceUserGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceUserGroupName) {
              return $group['name'] === $keycloakWisskiInstanceUserGroupName;
            });
            $keycloakWisskiInstanceAdminGroup = reset($keycloakWisskiInstanceUserGroup);
          }

          // Set up parameters to search for the keycloak user.
          $getUserParams = [
            'token' => $keycloakToken,
            'queryParams' => [
              'username' => $wisskiComponent->getOwner()->getDisplayName(),
            ],
          ];

          // Get the user from Keycloak via getAllUsers,
          // because wie do not have the uuid, but only the username.
          $getAllUsersRequest = $this->sodaScsKeycloakServiceUserActions->buildGetAllRequest($getUserParams);
          $getAllUsersResponse = $this->sodaScsKeycloakServiceUserActions->makeRequest($getAllUsersRequest);

          if ($getAllUsersResponse['success']) {
            $allUserData = json_decode($getAllUsersResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);

            // Extract the UUID if user is found.
            if (!empty($allUserData) && is_array($allUserData) && count($allUserData) > 0) {
              $userData = $allUserData[0];
            }
          }

          // Add user to admin group.
          $keycloakBuildAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
            'type' => 'group',
            'routeParams' => [
              'userId' => $userData['id'],
              'groupId' => $keycloakWisskiInstanceAdminGroup['id'],
            ],
            'token' => $keycloakToken,
          ]);
          $keycloakMakeAddUserToGroupRequest = $this->sodaScsKeycloakServiceUserActions->makeRequest($keycloakBuildAddUserToGroupRequest);

          if (!$keycloakMakeAddUserToGroupRequest['success']) {
            throw new \Exception('Keycloak add user to admin group request failed: ' . $keycloakMakeAddUserToGroupRequest['error']);
          }
          break;
      }
    }

    $form_state->setRedirect('entity.soda_scs_project.collection');

  }

}
