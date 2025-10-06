<?php

declare(strict_types=1);

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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
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
class SodaScsComponentCreateForm extends ContentEntityForm {

  /**
   * The SODa SCS Component bundle.
   *
   * @var string
   */
  protected string $bundle;

  /**
   * The SODa SCS Component bundle info.
   *
   * @var array
   */
  protected array $bundleInfo;

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

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
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
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
    LoggerChannelFactoryInterface $loggerFactory,
    TimeInterface $time,
    SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions,
    SodaScsComponentActionsInterface $sodaScsComponentActions,
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->sodaScsDockerRegistryServiceActions = $sodaScsDockerRegistryServiceActions;
    $this->sodaScsComponentActions = $sodaScsComponentActions;
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
      $container->get('logger.factory'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.docker_registry_service.actions'),
      $container->get('soda_scs_manager.component.actions'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_component_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string|null $bundle = NULL) {

    $this->bundle = $bundle;
    $this->bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')[$this->bundle];

    // Ensure the entity has the correct bundle before building the form.
    if (!$this->entity->bundle() && $this->bundle) {
      $this->entity = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        'type' => $this->bundle,
      ]);
    }

    // Set the imageUrl and description for the entity.
    $this->entity->set('imageUrl', $this->bundleInfo['imageUrl']);
    $this->entity->set('description', $this->bundleInfo['description']);
    // @todo Do we need to set the health anymore?
    $this->entity->set('health', 'Unknown');

    // Build the form.
    $form = parent::buildForm($form, $form_state);

    // Hide the flavours field.
    $form['flavours']['#access'] = FALSE;

    $form['#title'] = $this->t('Create a new @label', ['@label' => $this->bundleInfo['label']]);

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('What is that?'),
      '#value' => $this->bundleInfo['description'],
    ];

    $form['owner']['widget']['#default_value'] = $this->currentUser->id();
    if (!$this->currentUser->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Make the machineName field readonly and
    // add JavaScript to auto-generate it.
    if (isset($form['machineName'])) {
      // @todo Check if there is a better way to do this.
      // Add CSS classes for machine name generation.
      $form['label']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-source';
      $form['machineName']['widget'][0]['value']['#attributes']['class'][] = 'soda-scs-manager--machine-name-target';
      // Make the machine name field read-only.
      $form['machineName']['widget'][0]['value']['#attributes']['readonly'] = 'readonly';
      // Attach JavaScript to auto-generate machine name.
      $form['#attached']['library'][] = 'soda_scs_manager/machineNameGenerator';
    }

    // Make partOfProjects field required for filesystem components.
    // @todo This is deprecated and handled by the component entity.
    if ($this->bundle === 'soda_scs_filesystem_component' && isset($form['partOfProjects'])) {
      $form['partOfProjects']['widget']['#required'] = TRUE;
      $form['partOfProjects']['widget']['#description'] = $this->t('Mandatory field. Choose existing project or add new project <a href=":url">here</a>.', [
        ':url' => Url::fromRoute('entity.soda_scs_project.add_form')->toString(),
      ]);

      // Hide the sharedWith field.
      // @todo This is deprecated and handled by projects
      // so we have to remove it.
      $form['sharedWith']['#access'] = FALSE;
    }

    // Get the default project of the current user.
    $currentUser = $this->currentUser->getAccount();
    $defaultProjectOfCurrentUser = $currentUser->default_project;

    // Set the default project of the current user
    // as the default value of the partOfProjects field.
    if (isset($form['partOfProjects']) && !empty($defaultProjectOfCurrentUser)) {
      $form['partOfProjects']['widget']['#default_value'] = [$defaultProjectOfCurrentUser];
    }

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('CREATE');
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    parent::validateForm($form, $form_state);
    $machineName = $form_state->getValue('machineName')[0]['value'];

    $pattern = '/^[a-z0-9-_]+$/';

    if (!preg_match($pattern, $machineName)) {
      $form_state->setErrorByName('machineName', $this->t('The machineName can only contain small letters, digits, minus, and underscore'));
    }

    $disallowed_words = [
      "all",
      "alter",
      "and",
      "any",
      "between",
      "case",
      "create",
      "delete",
      "drop",
      "else",
      "end",
      "exists",
      "false",
      "from",
      "group",
      "having",
      "if",
      "in",
      "insert",
      "is",
      "join",
      "like",
      "limit",
      "not",
      "null",
      "offset",
      "order",
      "or",
      "regexp",
      "rlike",
      "select",
      "some",
      "then",
      "truncate",
      "true",
      "union",
      "update",
      "where",
      "when",
      "xor",
    ];

    // Check if the machineName contains any disallowed words.
    foreach ($disallowed_words as $word) {

      if ($machineName === $disallowed_words) {
        $form_state->setErrorByName('machineName', $this->t('The machineName cannot contain the word "@word"', ['@word' => $word]));
      }
    }

    // Check if the machineName is already in use by another SodaScsComponent entity.
    $entity_query = $this->entityTypeManager->getStorage('soda_scs_component')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machineName', $machineName);
    $existing_entities = $entity_query->execute();

    if (!empty($existing_entities)) {
      $form_state->setErrorByName('machineName', $this->t('The machineName is already in use by another Soda SCS Component entity'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {

    // Set the label of the entity.
    $this->entity->set('label', $this->entity->get('label')->value . ' (' . $this->bundleInfo['label'] . ')');
    // Create external stack.
    $createComponentResult = $this->sodaScsComponentActions->createComponent($this->entity);

    if (!$createComponentResult['success']) {
      $this->messenger()->addMessage($this->t('Cannot create component "@label". See logs for more details.', [
        '@label' => $this->entity->label(),
        '@username' => $this->currentUser->getAccount()->getDisplayName(),
      ]), 'error');
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot create component: @message", [
        '@message' => $createComponentResult['message'],
      ]);
      return;
    }

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

}
