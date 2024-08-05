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
      '#type' => 'password',
      '#title' => $this->t('Root password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbRootPassword'),
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
    $wisski_values = $form_state->getValue('wisski');

    // Save the configuration.

    $this->config('soda_scs_manager.settings')
      ->set('scsHost', $form_state->getValue('scsHost'))
      ->set('dbHost', $form_state->getValue('dbHost'))
      ->set('dbPort', $form_state->getValue('dbPort'))
      ->set('wisski', $form_state->getValue('wisski'))
      ->save();
    parent::submitForm($form, $form_state);
  }



}
