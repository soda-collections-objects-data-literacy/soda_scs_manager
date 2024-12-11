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
use Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
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
class SodaScsStackCreateForm extends ContentEntityForm {

  /**
   * The SODa SCS Stack bundle.
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
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * Constructs a new SodaScsComponentCreateForm.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\SodaScsStackActions $sodaScsStackActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    ConfigFactoryInterface $configFactory,
    TimeInterface $time,
    SodaScsStackActionsInterface $sodaScsStackActions,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->sodaScsStackActions = $sodaScsStackActions;
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
      $container->get('soda_scs_manager.stack.actions'),

    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_stack_create_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $bundle = NULL) {

    $this->bundle = $bundle;
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_stack');
    // Build the form.
    $form = parent::buildForm($form, $form_state);

    // Add the bundle description.
    #$form['info'] = [
    #  '#type' => 'item',
    #  '#markup' => $this->bundle->getDescription(),
    #];

    // Change the title of the page.
    #$form['#title'] = $this->t('Create a new @stack stack.', ['@stack' => $this->stackType->label()]);

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('CREATE STACK');
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-stack--stack--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    parent::validateForm($form, $form_state);
    $subdomain = $form_state->getValue('subdomain')[0]['value'];

    $pattern = '/^[a-z0-9-]+$/';

    if (!preg_match($pattern, $subdomain)) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain can only contain small letters, digits, and minus.'));
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

    // Check if the subdomain contains any disallowed words.
    foreach ($disallowed_words as $word) {

      if ($subdomain === $disallowed_words) {
        $form_state->setErrorByName('subdomain', $this->t('The subdomain cannot contain the word "@word"', ['@word' => $word]));
      }
    }

    // Check if the subdomain is already in use by another SodaScsComponent entity.
    $entity_query = \Drupal::entityQuery('soda_scs_component')
      ->accessCheck(FALSE)
      ->condition('subdomain', $subdomain);
    $existing_entities = $entity_query->execute();

    if (!empty($existing_entities)) {
      $form_state->setErrorByName('subdomain', $this->t('The subdomain is already in use by another Soda SCS Component entity'));
    }
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {

    // We call it stack here.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $component */
    $stack = $this->entity;
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
    $stack->set('bundle', $this->bundle);
    $stack->set('created', $this->time->getRequestTime());
    $stack->set('updated', $this->time->getRequestTime());
    $stack->set('user', $this->currentUser->getAccount()->id());
    $subdomain = reset($form_state->getValue('subdomain'))['value'];
    $stack->set('label', $subdomain . '.' . $this->settings->get('scsHost') . ' Stack');
    $stack->set('subdomain', $subdomain);

    // Create external stack.
    $createStackResult = $this->sodaScsStackActions->createStack($stack);

    if (!$createStackResult['success']) {
      $this->messenger()->addMessage($this->t('Cannot create stack "@label". See logs for more details.', [
        '@label' => $this->entity->label(),
        '@username' => $this->currentUser->getAccount()->getDisplayName(),
      ]), 'error');
      return;
    }

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.desk');
  }

}
