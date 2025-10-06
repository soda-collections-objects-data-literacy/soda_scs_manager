<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provides a form for deleting Soda SCS Stack entities.
 */
class SodaScsStackDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS API Actions service.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface
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
   * @param \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface $sodaScsStackActions
   *   The Soda SCS API Actions service.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    SodaScsStackActionsInterface $sodaScsStackActions,
  ) {
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
    // @todo Fix "page not found".
    return Url::fromRoute('entity.soda_scs_stack.canonical', [
      'bundle' => $this->entity->bundle(),
      'soda_scs_stack' => $this->entity->id(),
    ]);
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
      ];
    }

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    return $form;
  }

  /**
   * Custom submit handler for force delete.
   */
  public function forceDeleteSubmit(array &$form, FormStateInterface $form_state) {
    \Drupal::logger('soda_scs_manager')->warning('Force deleting stack: @label', ['@label' => $this->entity->label()]);

    $stack = $this->entity;
    $stack->delete();
    $this->messenger()->addWarning($this->t('Stack force deleted.'));
    $form_state->setRedirect('soda_scs_manager.dashboardboard');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting stack: @label', ['@label' => $this->entity->label()]);

    // Construct properties.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStack $stack */
    $stack = $this->entity;

    // Delete the whole stack.
    $deleteStackResult = $this->sodaScsStackActions->deleteStack($stack);

    if (!$deleteStackResult['success']) {
      \Drupal::messenger()->addError($this->t('%message See logs for more information.', [
        '%message' => $deleteStackResult['message'],
      ]));
      \Drupal::logger('soda_scs_manager')->error('%message %error %trace', [
        '%message' => $deleteStackResult['message'],
        '%error' => $deleteStackResult['error'],
      ]);
      // Set a flag in form state to show the force delete button on rebuild.
      $form_state->set('delete_failed', TRUE);
      $form_state->setRebuild();
      return;
    }

    $this->messenger()->addStatus($deleteStackResult['message']);
    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

}
