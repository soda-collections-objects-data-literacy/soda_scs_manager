<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\SodaScsApiActions;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Drush;

/**
 * Provides a form for deleting Soda SCS Component entities.
 */
class SodaScsComponentDeleteForm extends ContentEntityDeleteForm {

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
  public function form_id() {
    return 'soda_scs_manager_component_delete_form';
  }


  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete component: @label?', ['@label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('soda_scs_manager.desk');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting component: @label', ['@label' => $this->entity->label()]);

    // Construct properties.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $entity */
    $entity = $this->entity;
    $bundle = $entity->bundle();
    
    $serviceKeyId = $entity->get('serviceKey')->target_id;
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKey $serviceKey */
    $serviceKey = \Drupal::entityTypeManager()->getStorage('soda_scs_service_key')->load($serviceKeyId);
    $servicePassword = $serviceKey->get('servicePassword')->value;
    $options = [
      'userId' => $entity->getOwner()->id(),
      'userName' => $entity->getOwner()->getAccountName(),
      'subdomain' => $entity->get('subdomain')->value,
      'externalId' => $entity->get('externalId')->value,
      'servicePassword' => $servicePassword,
    ];
    // Delete the whole stack with database
    $deleteComponentResult = $this->sodaScsApiActions->deleteStack($bundle, $options);

    if (!$deleteComponentResult['success']) {
      \Drupal::messenger()->addError($this->t('Failed to delete component @label. See logs for more information.', ['@label' => $this->entity->label()]));
      return;
    }
    // Call the parent submit handler to delete the entity.
    parent::submitForm($form, $form_state);

    // Redirect to the desk.
    $form_state->setRedirect('soda_scs_manager.desk');
  }

}
