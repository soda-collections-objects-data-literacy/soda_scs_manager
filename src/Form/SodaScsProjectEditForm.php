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
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the ScsComponent entity edit form.
 */
class SodaScsProjectEditForm extends ContentEntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The bundle.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
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
   * Keycloak client actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions;

  /**
   * Keycloak group actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions;

  /**
   * Keycloak user actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions;

  /**
   * Project helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('soda_scs_manager.keycloak_service.client.actions'),
      $container->get('soda_scs_manager.keycloak_service.group.actions'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
      $container->get('soda_scs_manager.project.helpers'),
    );
  }

  /**
   * Constructs a new SodaScsProjectEditForm.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityTypeManager = $entityTypeManager;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_edit_form';
  }

  /**
   * Cancel form submission handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $current_user = \Drupal::currentUser();

    $form['owner']['widget']['#default_value'] = $current_user->id();
    if (!$current_user->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Remove the delete button.
    unset($form['actions']['delete']);

    // Add an abort button.
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelForm'],
      '#limit_validation_errors' => [],
      '#weight' => 10,
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'button--cancel'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    parent::save($form, $form_state);

    // Ensure all members have the project's Keycloak group (by gid).
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;

    $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);


    // Redirect to the components page.
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }


}
