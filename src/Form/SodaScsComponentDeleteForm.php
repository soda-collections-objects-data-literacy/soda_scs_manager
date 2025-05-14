<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting Soda SCS Component entities.
 */
class SodaScsComponentDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface
   */
  protected SodaScsStackActionsInterface $sodaScsStackActions;

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsComponentActions;

  /**
   * Constructs a new SodaScsStackDeleteForm.
   *
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsComponentActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, SodaScsComponentActionsInterface $sodaScsComponentActions) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityRepository = $entity_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->time = $time;
    $this->sodaScsComponentActions = $sodaScsComponentActions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.component.actions')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
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
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    // If a previous delete attempt failed, show the force delete button.
    if ($form_state->get('delete_failed')) {
      $form['actions']['force_delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Force delete'),
        '#button_type' => 'danger',
        '#submit' => ['::forceDeleteSubmit'],
        '#attributes' => ['class' => ['soda-scs-component--component--form-submit']],
      ];
    }

    // Add throbber overlay classes to the default delete button.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
    }

    // @todo Not working yet!
    // Attach the throbber overlay library.
    $form['#attached']['library'][] = 'soda_scs_manager/throbber_overlay';

    return $form;
  }

  /**
   * Custom submit handler for force delete.
   */
  public function forceDeleteSubmit(array &$form, FormStateInterface $form_state) {
    \Drupal::logger('soda_scs_manager')->warning('Force deleting component: @label', ['@label' => $this->entity->label()]);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
    $component = $this->entity;
    // Directly delete the entity, bypassing the component actions.
    $component->delete();
    $this->messenger()->addWarning($this->t('Component force deleted.'));
    $form_state->setRedirect('soda_scs_manager.desk');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    \Drupal::logger('soda_scs_manager')->notice('Deleting component: @label', ['@label' => $this->entity->label()]);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
    $component = $this->entity;
    $deleteComponentResult = $this->sodaScsComponentActions->deleteComponent($component);
    if (!$deleteComponentResult['success']) {
      $this->messenger()->addError($deleteComponentResult['message']);
      // Set a flag in form state to show the force delete button on rebuild.
      $form_state->set('delete_failed', TRUE);
      $form_state->setRebuild();
      return;
    }
    $this->messenger()->addStatus($deleteComponentResult['message']);
    $form_state->setRedirect('soda_scs_manager.desk');
  }

}
