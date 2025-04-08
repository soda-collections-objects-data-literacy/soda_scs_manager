<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting Soda SCS Stack entities.
 */
class SodaScsStackDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * Constructs a new SodaScsStackDeleteForm.
   *
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\SodaScsStackActionsInterface $sodaScsStackActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, SodaScsStackActionsInterface $sodaScsStackActions) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityRepository = $entity_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->time = $time;
    $this->sodaScsStackActions = $sodaScsStackActions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.stack.actions')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_stack_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete stack: @label?', ['@label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    //TODO: Fix "page not found"
    return Url::fromRoute('entity.soda_scs_stack.canonical', ['soda_scs_stack' => $this->entity->id()]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting component: @label', ['@label' => $this->entity->label()]);

    // Construct properties.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $entity */
    $component = $this->entity;

    // Delete the whole stack with database.
    $deleteComponentResult = $this->sodaScsStackActions->deleteStack($component);

    if (!$deleteComponentResult['success']) {
      \Drupal::messenger()->addError($this->t('%message See logs for more information.', [
        '%message' => $deleteComponentResult['message'],
      ]));
      \Drupal::logger('soda_scs_manager')->error('%message %error %trace', [
        '%message' => $deleteComponentResult['message'],
        '%error' => $deleteComponentResult['error'],
      ]);
      return;
    }

    $this->messenger()->addStatus($deleteComponentResult['message']);
    // Call the parent submit handler to delete the entity.
    // We don't do this here.
    // parent::submitForm($form, $form_state);.
    // Redirect to the desk.
    $form_state->setRedirect('soda_scs_manager.desk');
  }

}
