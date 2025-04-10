<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Provides a form for deleting a snapshot entity.
 */
class SodaScsSnapshotDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->getEntity();
    return $this->t('Are you sure you want to delete snapshot %label?', [
      '%label' => $snapshot->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.soda_scs_snapshot.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->getEntity();
    $label = $snapshot->label();
    $snapshot->delete();

    $this->messenger()->addMessage($this->t('Snapshot %label has been deleted.', [
      '%label' => $label,
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
