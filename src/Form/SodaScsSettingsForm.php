<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * WissKI cloud opt in settings form.
 */
class SodaScsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'soda_scs_manager.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\Core\Config\ImmutableConfig $config */
    $config = $this->config('soda_scs_manager.settings');

    $form['distilleryApiEndpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('RESTfull API endpoint URL'),
      '#description' => $this->t('Provide the URL of the distillery API endpoint.'),
      '#default_value' => $config->get('distilleryApiEndpoint'),
    ];

    $form['distilleryToken'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Distillery Token'),
      '#description' => $this->t('Provide the Token of the distillery Database.'),
      '#default_value' => $config->get('distilleryToken'),
    ];

    $form['usernameBlacklist'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Blacklisted user names'),
      '#description' => $this->t('Provide a comma separated list of user names that are not allowed to register.'),
      '#default_value' => $config->get('usernameBlacklist'),
    ];

    $form['emailBlacklist'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Blacklisted email providers'),
      '#description' => $this->t('Provide a comma separated list of email providers that are not allowed to register.'),
      '#default_value' => $config->get('emailBlacklist'),
    ];

    $form['subdomainBlacklist'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Blacklisted subdomains providers'),
      '#description' => $this->t('Provide a comma separated list of subdomains that are not allowed to register.'),
      '#default_value' => $config->get('subdomainBlacklist'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('soda_scs_manager.settings');

    $config
      ->set('distilleryApiEndpoint', $form_state->getValue('distilleryApiEndpoint'))
      ->set('distilleryToken', $form_state->getValue('distilleryToken'))
      ->set('emailBlacklist', $form_state->getValue('emailBlacklist'))
      ->set('subdomainBlacklist', $form_state->getValue('subdomainBlacklist'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}