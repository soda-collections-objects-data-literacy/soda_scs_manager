<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Messenger\MessengerInterface;

/**
 * Form controller for the snapshot entity create/edit forms.
 */
class SodaScsSnapshotCreateForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->entity;
    $form = parent::buildForm($form, $form_state);

    if ($this->operation == 'add') {
      $form['#title'] = $this->t('Add snapshot');
    }
    else {
      $form['#title'] = $this->t(
        'Edit snapshot @label',
        ['@label' => $snapshot->label()]
      );
    }

    // Set default language to English if not set.
    if ($snapshot->get('langcode')->isEmpty()) {
      $snapshot->set('langcode', Language::LANGCODE_DEFAULT);
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->entity;
    $status = $snapshot->save();
    $messenger = \Drupal::messenger();

    if ($status === SAVED_NEW) {
      $messenger->addMessage($this->t('Created new snapshot %label.', [
        '%label' => $snapshot->label(),
      ]));
    }
    else {
      $messenger->addMessage($this->t('Updated snapshot %label.', [
        '%label' => $snapshot->label(),
      ]));
    }

    $form_state->setRedirectUrl($snapshot->toUrl('collection'));
  }

}
