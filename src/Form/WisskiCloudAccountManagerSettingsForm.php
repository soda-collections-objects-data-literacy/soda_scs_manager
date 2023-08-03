<?php

namespace Drupal\wisski_cloud_account_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * WissKI cloud opt in settings form.
 */
class WisskiCloudAccountManagerSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'wisski_cloud_account_manager_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'wisski_cloud_account_manager.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $this->config('wisski_cloud_account_manager.settings');

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('wisski_cloud_account_manager.settings');
    /*
      $config->set('', $form_state->getValue(''))
      ->save();
    */

    parent::submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    /*
    // Hash expiration validation.
    $hash_expiration = intval($form_state->getValue('hash_expiration'));
    if ($hash_expiration < 1) {
      $form_state->setErrorByName('hash_expiration', $this->t('The miminum hash expiration time is @min_value.', ['@min_value' => $this->t('one hour')]));
    }
    elseif ($hash_expiration > 48) {
      $form_state->setErrorByName('hash_expiration', $this->t('The maximum hash expiration time is @max_value.', ['@max_value' => $this->t('@count days', ['@count' => 2])]));
    }
    */
    }
}
