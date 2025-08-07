<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the ScsComponent entity edit form.
 */
class SodaScsProjectEditForm extends ContentEntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The bundle.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_edit_form';
  }

  /**
   * Cancel form submission handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $current_user = \Drupal::currentUser();

    $form['owner']['widget']['#default_value'] = $current_user->id();
    if (!$current_user->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Remove the delete button.
    unset($form['actions']['delete']);

    // Add an abort button.
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelForm'],
      '#limit_validation_errors' => [],
      '#weight' => 10,
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'button--cancel'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    parent::save($form, $form_state);

    // Redirect to the components page.
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }

}
