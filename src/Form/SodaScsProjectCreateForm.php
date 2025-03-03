<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

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
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsComponentActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions,
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->sodaScsDockerRegistryServiceActions = $sodaScsDockerRegistryServiceActions;
    $this->sodaScsComponentActions = $sodaScsComponentActions;
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('current_user'),
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.docker_registry_service.actions'),
      $container->get('soda_scs_manager.component.actions'),
      $container->get('entity_type.manager')
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
    if (!\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
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
  public function save(array $form, FormStateInterface $form_state) {
    parent::save($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);
    // Check if a project with this machine name already exists.
    $machineName = $form_state->getValue('machineName')[0]['value'];
    $query = \Drupal::entityQuery('soda_scs_project')
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
    parent::submitForm($form, $form_state);

    $form_state->setRedirect('entity.soda_scs_project.collection');

  }

}
