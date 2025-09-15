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
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $sodaScsProjectHelpers
   *   The Soda SCS Project Helpers service.
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
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);

    // Create Keycloak group for the project using the group ID.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;

    $kcToken = $this->sodaScsProjectHelpers->getKeycloakToken();
    if (!$kcToken) {
      return;
    }

    $createProjectGroupResult = $this->sodaScsProjectHelpers->createProjectGroup($project);
    if (!$createProjectGroupResult->success) {
      return;
    }
    $keycloakUuid = $createProjectGroupResult->data['keycloakGroupData']->uuid;

    if (is_string($keycloakUuid) && $keycloakUuid !== '') {
      $project->set('keycloakUuid', $keycloakUuid);
      $project->save();
    }

    // Sync keycloak group members with owner and project members.
    $syncKeycloakGroupMembersResponse = $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);
    if (!$syncKeycloakGroupMembersResponse->success) {
      $this->messenger()->addError($this->t('Failed to sync keycloak group members for project @project: See logs for details.', [
        '@project' => $project->label(),
      ]));
      $this->logger->error('Failed to sync keycloak group members for project @project: @error', [
        '@project' => $project->label(),
        '@error' => $syncKeycloakGroupMembersResponse->error,
      ]);
    }

    $this->messenger()->addMessage($this->t('Project @project has been created.', [
      '@project' => $this->entity->label(),
    ]));
    // Redirect to the project listing page.
    $form_state->setRedirect('entity.soda_scs_project.canonical', ['soda_scs_project' => $this->entity->id()]);
  }

}
