<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Builds the form to delete a WissKI Component Version entity.
 */
class SodaScsWisskiComponentVersionDeleteForm extends EntityConfirmFormBase {

  /**
   * {@inheritdoc}
   */
  public function getQuestion(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $entity = $this->entity;
    return $this->t('Are you sure you want to delete WissKI Component Version %version?', [
      '%version' => $entity->getVersion(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $entity = $this->entity;
    return $entity->toUrl('collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText(): \Drupal\Core\StringTranslation\TranslatableMarkup {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $entity = $this->entity;
    $entity->delete();

    $this->messenger()->addMessage($this->t('WissKI Component Version %version has been deleted.', [
      '%version' => $entity->getVersion(),
    ]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));
  }

}
