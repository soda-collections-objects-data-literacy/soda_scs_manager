<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form handler for WissKI Component Version add/edit forms.
 */
class SodaScsWisskiComponentVersionForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $entity = $this->entity;

    $form['version'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Version'),
      '#description' => $this->t('The WissKI component version number, like "1.0.0".'),
      '#default_value' => $entity->getVersion(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['wisskiStack'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WissKI Stack'),
      '#description' => $this->t('The <a href="https://github.com/soda-collections-objects-data-literacy/wisski-base-stack" target="_blank">WissKI stack version</a>, like "2.1.0".'),
      '#default_value' => $entity->getWisskiStack(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['wisskiImage'] = [
      '#type' => 'textfield',
      '#title' => $this->t('WissKI Image'),
      '#description' => $this->t('The <a href="https://github.com/soda-collections-objects-data-literacy/wisski-base-image" target="_blank">WissKI image version</a>, like "2.1.1".'),
      '#default_value' => $entity->getWisskiImage(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['packageEnvironment'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Package Environment'),
      '#description' => $this->t('The <a href="https://github.com/soda-collections-objects-data-literacy/drupal_packages" target="_blank">package environment version</a>, like "1.0.0".'),
      '#default_value' => $entity->getPackageEnvironment(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  protected function actions(array $form, FormStateInterface $form_state): array {
    $actions = parent::actions($form, $form_state);
    return $actions;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $entity */
    $entity = $this->entity;

    // Set the ID from the version number if this is a new entity.
    if ($entity->isNew()) {
      $version = $form_state->getValue('version');
      // Use version as ID, sanitized for config entity ID.
      $id = preg_replace('/[^a-z0-9_]/', '_', strtolower($version));
      $entity->set('id', $id);
    }

    $status = $entity->save();

    $this->messenger()->addMessage($this->t('WissKI Component Version %version has been %action.', [
      '%version' => $entity->getVersion(),
      '%action' => $status === SAVED_NEW ? $this->t('created') : $this->t('updated'),
    ]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));
  }

}
