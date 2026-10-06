<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for editing the notes of a stack or component.
 */
final class SodaScsEntityNoteForm extends FormBase {

  /**
   * The entity whose notes are edited.
   */
  protected ContentEntityInterface $entity;

  /**
   * Constructs the notes form.
   */
  public function __construct(protected AccountProxyInterface $currentUser) {}

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
    return 'soda_scs_manager_entity_note_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?ContentEntityInterface $entity = NULL): array {
    if (!$entity instanceof ContentEntityInterface || !$entity->hasField('notes')) {
      return $form;
    }

    $this->entity = $entity;
    $form['notes'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Notes'),
      '#default_value' => $entity->get('notes')->value ?? '',
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
    if (!$this->entity->access('update', $this->currentUser)) {
      $form_state->setErrorByName('notes', $this->t('You are not allowed to edit this application.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->entity->set('notes', $form_state->getValue('notes'));
    $this->entity->save();
    $this->messenger()->addStatus($this->t('Notes have been saved.'));
  }

}
