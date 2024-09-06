<?php

namespace Drupal\soda_scs_manager\Form;

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

    // Add vertical tabs container.
    $form['tabs'] = [
      '#type' => 'vertical_tabs',
      '#default_tab' => 'edit-general',
    ];

    // General settings tab.
    $form['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General settings'),
      '#group' => 'tabs',
    ];

    $form['general']['fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];
    $form['general']['fields']['scsHost'] = [
      '#type' => 'textfield',
      '#title' => $this->t('SODa SCS host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('scsHost'),
    ];

    // Database settings tab.
    $form['database'] = [
      '#type' => 'details',
      '#title' => $this->t('Database settings'),
      '#group' => 'tabs',
    ];

    $form['database']['fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['database']['fields']['dbHost'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbHost'),
    ];
    $form['database']['fields']['dbPort'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database port'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbPort'),
    ];
    $form['database']['fields']['dbRootPassword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Root password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbRootPassword'),
    ];

    // Triplestore settings tab.
    $form['triplestore'] = [
      '#type' => 'details',
      '#title' => $this->t('Triplestore settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['triplestore']['openGdpSettings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['triplestore']['openGdpSettings']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Triplestore host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['openGdpSettings']['host'] ?? '',
    ];
    $form['triplestore']['openGdpSettings']['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Tripelstore port'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['openGdpSettings']['port'] ?? '',
    ];
    $form['triplestore']['openGdpSettings']['adminUsername'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Triplestore admin username'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['openGdpSettings']['adminUsername'] ?? '',
    ];
    $form['triplestore']['openGdpSettings']['adminPassword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Triplestore admin password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['openGdpSettings']['adminPassword'] ?? '',
    ];

    $form['triplestore']['routes'] = [
      '#type' => 'fieldset',
      '#title' => 'Routes for ' . $form_state->getValue('bundle') . ' service',
    ];

    $form['triplestore']['routes']['healthCheck'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => 'Health check route',
    ];
    $form['triplestore']['routes']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['healthCheck']['url'] ?? '',
    ];
    $form['triplestore']['routes']['healthCheck']['checkButton'] = [
      '#type' => 'button',
      '#default_value' => $this->t('Check health'),
    ];

    $form['triplestore']['routes']['repository']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repository']['createUrl'] ?? '',
    ];

    $form['triplestore']['routes']['repository']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repository']['readOneUrl'] ?? '',
    ];

    $form['triplestore']['routes']['repository']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repository']['readAllUrl'] ?? '',
    ];

    $form['triplestore']['routes']['repository']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repository']['updateUrl'] ?? '',
    ];

    $form['triplestore']['routes']['repository']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repository']['deleteUrl'] ?? '',
    ];

    // WissKI bundle settings tab.
    $form['wisski'] = [
      '#type' => 'details',
      '#title' => $this->t('WissKI settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['wisski']['info'] = [
      '#type' => 'item',
      '#title' => $this->t('Options for portainer service'),
      '#markup' => $this->t('Visit <a href="https://portainer.dena-dev.de" target="_blank">portainer.dena-dev.de</a> for more information.'),
    ];

    $form['wisski']['portainerOptions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Portainer options'),
    ];

    $form['wisski']['portainerOptions']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['portainerOptions']['host'] ?? '',
    ];

    $form['wisski']['portainerOptions']['authenticationToken'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authentication token'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['portainerOptions']['authenticationToken'] ?? '',
    ];

    $form['wisski']['portainerOptions']['endpoint'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['portainerOptions']['endpoint'] ?? '',
    ];

    $form['wisski']['portainerOptions']['swarmId'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Swarm Id'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['portainerOptions']['swarmId'] ?? '',
    ];

    $form['wisski']['routes'] = [
      '#type' => 'fieldset',
      '#title' => 'Routes for ' . $form_state->getValue('bundle') . ' service',
    ];

    $form['wisski']['routes']['healthCheck'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => 'Health check route',
    ];
    $form['wisski']['routes']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['healthCheck']['url'] ?? '',
    ];
    $form['wisski']['routes']['healthCheck']['checkButton'] = [
      '#type' => 'button',
      '#default_value' => $this->t('Check health'),
    ];

    $form['wisski']['routes']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['createUrl'] ?? '',
    ];

    $form['wisski']['routes']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['readOneUrl'] ?? '',
    ];

    $form['wisski']['routes']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['readAllUrl'] ?? '',
    ];

    $form['wisski']['routes']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['updateUrl'] ?? '',
    ];

    $form['wisski']['routes']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete route path'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['routes']['deleteUrl'] ?? '',
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {

    // Save the configuration.
    $this->config('soda_scs_manager.settings')
      ->set('scsHost', $form_state->getValue('scsHost'))
      ->set('dbHost', $form_state->getValue('dbHost'))
      ->set('dbPort', $form_state->getValue('dbPort'))
      ->set('dbRootPassword', $form_state->getValue('dbRootPassword'))
      ->set('triplestore', $form_state->getValue('triplestore'))
      ->set('wisski', $form_state->getValue('wisski'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
