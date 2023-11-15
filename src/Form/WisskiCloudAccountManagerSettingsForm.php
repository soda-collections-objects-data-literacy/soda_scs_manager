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

    $form['daemonUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The WissKI Cloud API Daemon URL'),
      '#description' => $this->t('Provide the complete base URL with protocol, domain (resp. service name in docker), ports and API path, i. e. "http://wisski_cloud_api_daemon:3000/wisski-cloud-daemon/api/v1"'),
      '#default_value' => $config->get('daemonUrl'),
    ];

     $form['wsUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('The websocket URL of the WissKI Cloud'),
      '#description' => $this->t('Provide the complete base URL with protocol, domain (resp. service name in docker), ports and API path, i. e. "wss://panel.wisski.cloud/api/v1/ws"'),
      '#default_value' => $config->get('wsUrl'),
    ];

    $form['wsToken'] = [
      '#type' => 'password',
      '#title' => $this->t('The websocket access token of the WissKI Cloud provisioning account.'),
      '#description' => $this->t('Provide the access token for the websocket, i. e. "1234567890"'),
      '#default_value' => $config->get('wsToken'),
    ];

    $form['provisionRoute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instance provision URL path'),
      '#description' => $this->t('Provide the path to the account validation PUT endpoint, i. e. "/provision"'),
      '#default_value' => $config->get('provisionRoute'),
    ];

    $form['deleteRoute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Instance delete URL path'),
      '#description' => $this->t('Provide the path to the account validation DELETE endpoint, i. e. "/delete"'),
      '#default_value' => $config->get('deleteRoute'),
    ];

    $form['healthCheckRoute'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Deamon health check URL path'),
      '#description' => $this->t('Provide the path to the health check GET endpoint, i. e. "/health-check"'),
      '#default_value' => $config->get('healthCheckRoute'),
    ];

    $form['usernameBlacklist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Username blacklist'),
      '#rows' => '5',
      '#cols' => '60',
      '#description' => $this->t('Provide blocked usernames separeated by new lines, i. e. "\n admin \n root"'),
      '#default_value' => $config->get('usernameBlacklist'),
    ];
    $form['emailProviderBlacklist'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Email provider blacklist'),
      '#rows' => '5',
      '#cols' => '60',
      '#resizable' => 'vertical',
      '#description' => $this->t('Provide blocked email providers with a comma separated list, i. e. "\n admin\nroot"'),
      '#default_value' => $config->get('emailProviderBlacklist'),
    ];
    $form['subdomainBlacklist'] = [
      '#type' => 'textarea',
      '#rows' => '5',
      '#cols' => '60',
      '#title' => $this->t('Subdomain blacklist'),
      '#description' => $this->t('Provide blocked subdomain with a comma separated list, i. e. "\nwww\nadmin\nroot"'),
      '#default_value' => $config->get('subdomainBlacklist'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!preg_match("/^[a-zA-Z0-9\-]+(\r?\n[a-zA-Z0-9\-]+)*$/", $form_state->getValue('usernameBlacklist'))) {
      $form_state->setErrorByName('usernameBlacklist', $this->t('The username blacklist is not valid. Only words separated by new lines are allowed.'));
    }
    if (!preg_match("/^([a-zA-Z0-9-]+\.[a-zA-Z0-9-]+)+(\r?\n[a-zA-Z0-9-]+\.[a-zA-Z0-9-]+)*$/", $form_state->getValue('emailProviderBlacklist'))) {
      $form_state->setErrorByName('emailProviderBlacklist', $this->t('The email provider blacklist is not valid. Only &lt;second level domain&gt; &lt;dot&gt; &lt;first level domain&gt; separated by new lines are allowed.'));
    }
    if (!preg_match("/^[a-zA-Z0-9\-]+(\r?\n[a-zA-Z0-9\-]+)*$/", $form_state->getValue('subdomainBlacklist'))) {
      $form_state->setErrorByName('subdomainBlacklist', $this->t('The subdomain blacklist is not valid. Only words separated by new lines are allowed.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('wisski_cloud_account_manager.settings');

    $config
      ->set('daemonUrl', $form_state->getValue('daemonUrl'))
      ->set('deleteRoute', $form_state->getValue('deleteRoute'))
      ->set('emailProviderBlacklist', $form_state->getValue('emailProviderBlacklist'))
      ->set('healthCheckRoute', $form_state->getValue('healthCheckRoute'))
      ->set('provisionRoute', $form_state->getValue('provisionRoute'))
      ->set('usernameBlacklist', $form_state->getValue('usernameBlacklist'))
      ->set('subdomainBlacklist', $form_state->getValue('subdomainBlacklist'))
      ->set('wsUrl', $form_state->getValue('wsUrl'))
      ->set('wsToken', $form_state->getValue('wsToken'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
