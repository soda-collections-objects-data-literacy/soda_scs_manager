<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\HtmlCommand;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * SODa SCS Manager in settings form.
 *
 * Route and authentication settings for every service
 * as defined in the SODa SCS Component bundles.
 */
class SodaScsSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return [
      'soda_scs_manager.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);
    $form_state->setCached(FALSE);

    $form['db'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Database settings'),
    ];
    $form['db']['db_host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('db_host'),
    ];
    $form['db']['db_port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database port'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('db_port'),
    ];
    $form['db']['db_root_password'] = [
      '#type' => 'password',
      '#title' => $this->t('Root password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('db_root_password'),
    ];

    // Build main form wit bundle selection and subform for bundle settings.
    $form['bundle'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a bundle'),
      '#options' => $this->getBundleOptions(),
      '#ajax' => [
        'callback' => '::updateSubform',
        'wrapper' => 'soda-scs--settings--subform',
      ],
    ];

    // Build subform container.
    $form['subform'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'soda-scs--settings--subform'],
    ];

    // Build subform for selected bundle.
    if ($form_state->getValue('bundle')) {
      $form['subform'] = $this->buildSubform($form, $form_state);
    }

    return $form;

  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Load the configuration.
    $config = $this->config('soda_scs_manager.settings');

    // Retrieve the current bundle configuration.
    $current_bundle_config = $config->get($form_state->getValue('bundle'));
    // Loop through all form values and update the configuration.

    foreach ($form_state->getValues() as $key => $value) {
      if (!in_array($key, ['db_host', 'db_port', 'root_password', 'bundle', 'form_id', 'form_token', 'form_build_id', 'op'])) {

        // Update the specific key within this bundle configuration.
        // This assumes $current_bundle_config is an array. If it's not, you might need to initialize it as an array first.
        $current_bundle_config[$key] = $value;

        // Save the updated configuration back.
        $config->set($form_state->getValue('bundle'), $current_bundle_config);
      }
      $config->set('db_host', $form_state->getValue('db_host'));
      $config->set('db_port', $form_state->getValue('db_port'));
      $config->set('db_root_password', $form_state->getValue('db_root_password'));
    }
    // Save the configuration.
    $config->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Update the subform based on the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public function updateSubform(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#soda-scs--settings--subform', $form['subform']));
    return $response;
  }

  /**
   * Retrieve bundle options.
   *
   * @return array
   */
  private function getBundleOptions(): array {
    // Retrieve and return bundle options.
    $bundles = \Drupal::service('entity_type.manager')->getStorage('soda_scs_component_bundle')->loadMultiple();
    $options = ['' => $this->t('Select a bundle')];
    foreach ($bundles as $bundle) {
        $options[$bundle->id()] = $bundle->label();
    }
    return $options;
  }

  /**
   * Build the subform for the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function buildSubform(array $form, FormStateInterface $form_state): array{
    $subform[$form_state->getValue('bundle')]['authentication'] = $this->buildAuthenticationSubform($form, $form_state);
    $subform[$form_state->getValue('bundle')]['routes'] = $this->buildRoutesSubform($form, $form_state);

    return $subform;


  }

  /**
   * Build the authentication subform for the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function buildAuthenticationSubform(array $form, FormStateInterface $form_state): array {
    // Build the authentication method selection.
    $authMethods = [
      '' => 'Select an authentication method',
      'basic' => 'Basic',
      'token' => 'Token',
    ];
    // Build and return the subform for the selected authentication.
    $subform['authentication_method'] = [
      '#type' => 'select',
      '#title' => $this->t('Select a auth method'),
      '#options' => $authMethods,
      '#ajax' => [
        'callback' => '::updateAuthenticationMethodSubform',
        'wrapper' => 'soda-scs--settings--authentication-method-subform',
      ],
      '#default_value' => $this->config('soda_scs_manager.settings')->get($form_state->getValue('bundle'))['authentication_method'] ?? '',
    ];

    // Build subform container.
    $subform['authentication_method_subform'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'soda-scs--settings--authentication-method-subform'],
    ];

    $subform['authentication_method_subform'] = $this->buildTokenAuthSubform($form, $form_state);

    // Build method subform for selected authentication method.
#    if ($form_state->getValue('authentication_method')) {
#      switch ($form_state->getValue('authentication_method')) {
#        case 'basic':
#          $subform['authentication_method_subform'] = $this->buildBasicAuthSubform($form, $form_state);
#          break;
#
#        case 'token':
#          $subform['authentication_method_subform'] = $this->buildTokenAuthSubform($form, $form_state);
#          break;
#      }
#    }

    return $subform;
  }

  /**
   * Update the authentication method subform based on the selected method.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   */
  public static function updateAuthenticationMethodSubform(array &$form, FormStateInterface $form_state): AjaxResponse {
    $response = new AjaxResponse();
    $response->addCommand(new HtmlCommand('#soda-scs--settings--authentication-method-subform', $form['subform'][$form_state->getValue('bundle')]['authentication']['authentication_method_subform']));
    return $response;
  }

  /**
   * Build the basic auth subform for the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function buildBasicAuthSubform($form, $form_state): array {
    // Build and return the subform for the selected bundle.
    $subform['basic_auth'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'soda-scs--settings--authentication-method-subform--basic-auth'],
    ];

    $subform['basic_auth']['username'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Username'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get($form_state->getValue('bundle'))['username'],
    ];
    $subform['basic_auth']['password'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Password'),
      '#value' => $this->config('soda_scs_manager.settings')->get($form_state->getValue('bundle'))['password'],
    ];

    return $subform;
  }

  /**
   * Build the token auth subform for the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function buildTokenAuthSubform($form, $form_state): array {
    $currentConfig = $this->config('soda_scs_manager.settings')->get($form_state->getValue('bundle'));
    // Build and return the subform for the selected bundle.
    $subform['token_auth'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'soda-scs--settings--authentication-method-subform--token-auth'],
    ];

    $subform['token_auth']['token'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token'),
      '#default_value' => $currentConfig['token'] ?? '',
    ];

    return $subform;
  }
  /**
   * Build the routes subform for the selected bundle.
   *
   * @param array $form
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *
   * @return array
   */
  private function buildRoutesSubform($form, $form_state): array {
    // Build and return the subform for the selected bundle.
    $currentConfig = $this->config('soda_scs_manager.settings')->get($form_state->getValue('bundle'));
    $subform = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--routes'],
      '#title' => 'Routes for ' . $form_state->getValue('bundle') . ' service',
    ];

    $subform['apiBaseRoute'] = [
        '#type' => 'textfield',
        '#title' => $this->t('API base route'),
        '#default_value' => $currentConfig['apiBaseRoute'] ?? '',
    ];

    $subform['healthCheckContainer'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => 'Health check route',
    ];
    $subform['healthCheckContainer']['healthCheckPath'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route path'),
      '#default_value' => $currentConfig['healthCheckPath'] ?? '',
    ];
    $subform['healthCheckContainer']['check'] = [
      '#type' => 'button',
      '#default_value' => $this->t('Check health'),
    ];

    $subform['getAll'] = [
        '#type' => 'textfield',
        '#title' => $this->t('GET all route path'),
        '#default_value' => $currentConfig['getAll'] ?? '',
    ];
    $subform['getOne'] = [
        '#type' => 'textfield',
        '#title' => $this->t('GET single route path'),
        '#default_value' => $currentConfig['getOne'] ?? '',
    ];
    $subform['post'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Create route path'),
        '#default_value' => $currentConfig['post'] ?? '',
    ];
    $subform['put'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Update route path'),
        '#default_value' => $currentConfig['put'] ?? '',
    ];
    $subform['delete'] = [
        '#type' => 'textfield',
        '#title' => $this->t('DELETE route path'),
        '#default_value' => $currentConfig['delete'] ?? '',
    ];


    return $subform;
  }
}