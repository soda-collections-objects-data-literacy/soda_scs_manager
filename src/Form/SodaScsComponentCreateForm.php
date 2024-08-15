<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal;
use Drupal\Core\Database\Database;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
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
   * @var \Drupal\soda_scs_manager\SodaScsApiActions
   */
  protected SodaScsApiActions $sodaScsApiActions;

  /**
   * Constructs a new SodaScsComponentCreateForm.
   *
   * @param \Drupal\soda_scs_manager\SodaScsApiActions $sodaScsApiActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, SodaScsApiActions $sodaScsApiActions) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityRepository = $entity_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->time = $time;
    $this->sodaScsApiActions = $sodaScsApiActions;
  }

  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.api.actions')
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

    // Get the entity and bundle.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $entity */
    $entity = $this->entity;
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundle $bundle */
    $bundle = \Drupal::service('entity_type.manager')->getStorage('soda_scs_component_bundle')->load($this->entity->bundle());


    // Check if the service key for this bundle and user already exists.
    $userId = \Drupal::currentUser()->id();
    $serviceKeys = \Drupal::entityTypeManager()->getStorage('soda_scs_service_key')->loadByProperties([
      'bundle' => $bundle->id(), 'user' => $userId,
    ]);

    if (empty($serviceKeys)) {
      $servicePassword = $this->sodaScsApiActions->generateRandomPassword();
      // Create a new service key entity.
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKey $serviceKey */
      $serviceKey = \Drupal::entityTypeManager()->getStorage('soda_scs_service_key')->create([
        'label' => $entity->label() . ' - ' . $bundle->label() . ' service key',
        'servicePassword' => $servicePassword,
        'bundle' => $bundle->id(),
        'user' => \Drupal::currentUser()->id(),
      ]);
      $serviceKey->save();
      $entity->set('serviceKey', $serviceKey);
    } else {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKey $serviceKey */
      $serviceKey = reset($serviceKeys);
      $entity->set('serviceKey', $serviceKey);
      $servicePassword = $serviceKey->get('servicePassword')->value;
    }
    // Create options array for the API call.
    $options = [
      'subdomain' => $entity->get('subdomain')->value,
      'project' => 'my_project',
      'userId' => \Drupal::currentUser()->id(),
      'userName' => \Drupal::currentUser()->getDisplayName(),
      'servicePassword' => $servicePassword,
    ];

    $entity->set('user', $options['userId']);

    if ($entity->isNew()) {
      $entity->set('created', time());
    } else {
      $entity->set('updated', time());
    }

    $entity->set('label', $options['subdomain'] . '.' . $this->config('soda_scs_manager.settings')->get('scsHost'));

    // Set the information coming from the bundle.
    $entity->set('description', $bundle->getDescription());
    $entity->set('imageUrl', $bundle->getImageUrl());

    // Create external stack
    $createComponentResult = $this->sodaScsApiActions->createStack($this->entity->bundle(), $options);


    if (!$createComponentResult['success']) {
      $this->messenger()->addMessage($this->t("Cannot create component \"@label\". See logs for more details.", [
        '@label' => $entity->label(),
        '@username' => \Drupal::currentUser()->getDisplayName(),
      ]), 'error');
      return;
    }

    // Set the external ID.
    $entity->set('externalId', $createComponentResult['data']['portainerResponse']['data']['Id']);

    $status = $entity->save();

    $serviceKey->set('scsComponent', [$entity->id()]);
    $serviceKey->save();

    // Check if the entity was saved.
    if ($status) {
      // Setting a message with the entity label
      $this->messenger()->addMessage($this->t('The @label component for @username has been created.', [
        '@label' => $entity->label(),
        '@username' => \Drupal::currentUser()->getDisplayName(),
      ]));
    } else {
      $this->messenger()->addMessage($this->t('The @label component for @username could not be created.', [
        '@label' => $entity->label(),
        '@username' => \Drupal::currentUser()->getDisplayName(),
      ]), 'error');
    }

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.desk');
  }
}
