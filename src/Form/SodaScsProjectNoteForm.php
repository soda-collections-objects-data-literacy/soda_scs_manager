<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing a project's notes.
 */
final class SodaScsProjectNoteForm extends FormBase {

  /**
   * The current project.
   */
  protected SodaScsProjectInterface $project;

  /**
   * The current user.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs the notes form.
   */
  public function __construct(AccountProxyInterface $current_user) {
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('current_user'));
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_note_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsProjectInterface $project = NULL): array {
    if (!$project instanceof SodaScsProjectInterface) {
      return $form;
    }

    $this->project = $project;
    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#default_value' => $project->get('note')->value ?? '',
      '#rows' => 8,
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save notes'),
      '#button_type' => 'primary',
      '#attributes' => [
        'class' => ['project-note-form__submit btn'],
      ],
    ];
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => [
        'class' => ['project-note-form__cancel btn btn--secondary'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->project->access('update', $this->currentUser)) {
      $form_state->setErrorByName('note', $this->t('You are not allowed to edit this project.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->project->set('note', $form_state->getValue('note'));
    $this->project->save();
    $this->messenger()->addStatus($this->t('Project notes have been saved.'));
  }

}
