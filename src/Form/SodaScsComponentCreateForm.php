<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\SodaScsStackActions;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Component\Datetime\TimeInterface;

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
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActions
   */
  protected SodaScsStackActions $sodaScsStackActions;


  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * Constructs a new SodaScsComponentCreateForm.
   *
   * @param \Drupal\soda_scs_manager\SodaScsStackActions $sodaScsStackActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, ConfigFactoryInterface $configFactory, TimeInterface $time, SodaScsStackActions $sodaScsStackActions) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityRepository = $entity_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->time = $time;
    $this->sodaScsStackActions = $sodaScsStackActions;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('config.factory'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.stack.actions')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function form_id(): string {
    return 'soda_scs_component_create_form';
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get the bundle of the entity
    $bundle = \Drupal::service('entity_type.manager')->getStorage('soda_scs_component_bundle')->load($this->entity->bundle());

    // Build the form
    $form = parent::buildForm($form, $form_state);

    // Change the title of the page.
    $form['#title'] = $this->t('Create a new @component', ['@component' => $bundle->label()]);

    // Add the bundle information
    $options = [
      'user' => \Drupal::currentUser()->getDisplayName(),
      'date' => date('Y-m-d H:i:s'),
      'component' => $bundle->label(),
      'description' => $bundle->getDescription(),
    ];
    $form['info'] = [
      '#type' => 'item',
      '#markup' => $this->t('<h3 class="text-center">This creates a <em>@component</em> for the user <em>@user</em></h3> <p class="text-justify">@description</p>', [
        '@component' => $options['component'],
        '@user' => $options['user'],
        '@description' => $options['description'],
      ]),
    ];

    // Change the label of the submit button.
    $form['actions']['submit']['#value'] = $this->t('CREATE COMPONENT');
    $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';

    return $form;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {

    // We call it component here.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $this->entity;
    $component->set('bundle', $this->entity->bundle());
    $component->set('created', $this->time->getRequestTime());
    $component->set('updated', $this->time->getRequestTime());
    $component->set('user', \Drupal::currentUser()->id());
    $subdomain = reset($form_state->getValue('subdomain'))['value'];
    $component->set('label', $subdomain . '.' . $this->settings->get('scsHost'));
    $component->set('subdomain',$subdomain);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundleInterface $bundle */
    $bundle = $this->entityTypeManager->getStorage('soda_scs_component_bundle')->load($this->entity->bundle());
    $component->set('description', $bundle->getDescription());
    $component->set('imageUrl', $bundle->getImageUrl());

    // Create external stack
    $createComponentResult = $this->sodaScsStackActions->createStack($component);

    if (!$createComponentResult['success']) {
      $this->messenger()->addMessage($this->t("Cannot create component \"@label\". See logs for more details.", [
        '@label' => $this->entity->label(),
        '@username' => \Drupal::currentUser()->getDisplayName(),
      ]), 'error');
      return;
    }

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.desk');
  }
}
