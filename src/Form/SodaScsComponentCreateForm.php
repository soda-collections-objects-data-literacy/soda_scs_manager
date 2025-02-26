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
   * @var \Drupal\soda_scs_manager\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
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
   * @param \Drupal\soda_scs_manager\SodaScsServiceRequestInterface $sodaScsDockerRegistryServiceActions
   *   The Soda SCS API Actions service.
   * @param \Drupal\soda_scs_manager\SodaScsComponentActionsInterface $sodaScsComponentActions
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
    return 'soda_scs_manager_component_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string|null $bundle = NULL) {

    $this->bundle = $bundle;
    // Build the form.
    $form = parent::buildForm($form, $form_state);

    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component');

    $form['#title'] = $this->t('Create a new @label', ['@label' => $bundle_info[$this->bundle]['label']]);

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('What is that?'),
      '#value' => $bundle_info[$this->bundle]['description'],
    ];

    $form['owner']['widget']['#default_value'] = $this->currentUser->id();
    if (!\Drupal::currentUser()->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('CREATE COMPONENT');
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';

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
    $label = $form_state->getValue('label')[0]['value'];
    // We call it component here.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $this->entity;
    $component->set('bundle', $this->entity->bundle());
    $component->set('created', $this->time->getRequestTime());
    $component->set('updated', $this->time->getRequestTime());
    $component->set('owner', $form_state->getValue('owner'));
    $machineName = reset($form_state->getValue('machineName'))['value'];
    $component->setLabel($label);
    $component->set('machineName', $machineName);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
    $bundle_info = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')[$this->bundle];
    $component->set('description', $bundle_info['description']);
    $component->set('imageUrl', $bundle_info['imageUrl']);

    // Create external stack.
    $createComponentResult = $this->sodaScsComponentActions->createComponent($component);

    if (!$createComponentResult['success']) {
      $this->messenger()->addMessage($this->t('Cannot create component "@label". See logs for more details.', [
        '@label' => $this->entity->label(),
        '@username' => $this->currentUser->getAccount()->getDisplayName(),
      ]), 'error');
      return;
    }

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.desk');
  }

}
