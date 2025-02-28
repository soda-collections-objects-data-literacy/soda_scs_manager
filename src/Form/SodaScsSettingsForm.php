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

    $form['info'] = [
      '#type' => 'details',
      '#title' => $this->t('Informations'),
      '#group' => 'tabs',
    ];

    $form['info']['fields'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Informations'),
    ];

    $form['info']['fields']['info'] = [
      '#type' => 'item',
      '#title' => $this->t('Placeholders'),
      '#markup' => $this->t('You can use the following placeholders in the settings form:
      <ul>
        <li><strong>{containerId}</strong> - The Docker container ID</li>
        <li><strong>{endpointId}</strong> - The Portainer endpoint ID</li>
        <li><strong>{execId}</strong> - The Docker exec ID</li>
        <li><strong>{instanceId}</strong> - The WissKI instance ID</li>
        <li><strong>{repositoryId}</strong> - The triplestore repository ID</li>
        <li><strong>{stackId}</strong> - The Portainer stack ID</li>
        <li><strong>{userId}</strong> - The triplestore user ID</li>
        <li><strong>{volumeId}</strong> - The Docker volume ID</li>
      </ul>
      <br>
      <ul>
      <li><strong>{empty}</strong> - Use if there is no value</li>
      </ul>
      '),
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
      '#description' => $this->t('The SODa SCS host, like https://scs.sammlungen.io.'),
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
      '#description' => $this->t('The database host, like https://db.scs.sammlungen.io.'),
    ];
    $form['database']['fields']['dbPort'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Database port'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbPort'),
      '#description' => $this->t('The database port, like 3306.'),
    ];
    $form['database']['fields']['dbRootPassword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Root password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbRootPassword'),
      '#description' => $this->t('The root password, like root.'),
    ];

    $form['database']['fields']['dbManagementHost'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Management host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('dbManagementHost'),
      '#description' => $this->t('The management host, like https://adminer-db.scs.sammlungen.io.'),
    ];

    // Triplestore settings tab.
    $form['triplestore'] = [
      '#type' => 'details',
      '#title' => $this->t('Triplestore settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['triplestore']['generalSettings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['triplestore']['generalSettings']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Triplestore host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['generalSettings']['host'] ?? '',
      '#description' => $this->t('The triplestore host, like https://ts.scs.sammlungen.io.'),
    ];
    $form['triplestore']['generalSettings']['port'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Port'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['generalSettings']['port'] ?? '',
      '#description' => $this->t('The triplestore port, like 80.'),
    ];
    $form['triplestore']['generalSettings']['adminUsername'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin username'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['generalSettings']['adminUsername'] ?? '',
      '#description' => $this->t('The admin username, like admin.'),
    ];
    $form['triplestore']['generalSettings']['adminPassword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['generalSettings']['adminPassword'] ?? '',
      '#description' => $this->t('The admin password, like password.'),
    ];

    $form['triplestore']['routes'] = [
      '#type' => 'fieldset',
      '#title' => 'Routes for ' . $form_state->getValue('bundle') . ' service',
    ];

    // Repositories routes.
    $form['triplestore']['routes']['repositories'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--repositories'],
      '#title' => 'Repositories routes',
    ];

    $form['triplestore']['routes']['repositories']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like /rest/repositories.'),
    ];

    $form['triplestore']['routes']['repositories']['crud'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--repositories-crud'],
      '#title' => 'CRUD routes',
    ];

    $form['triplestore']['routes']['repositories']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create repositories route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create repositories route.'),
    ];

    $form['triplestore']['routes']['repositories']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one repositories route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one repositories route, like /{repositoryId}.'),
    ];

    $form['triplestore']['routes']['repositories']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all repositories route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all repositories route.'),
    ];

    $form['triplestore']['routes']['repositories']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update repositories route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update repositories route, like /{repositoryId}.'),
    ];

    $form['triplestore']['routes']['repositories']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete repositories route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete repositories route, like /{repositoryId}.'),
    ];

    $form['triplestore']['routes']['repositories']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['repositories']['healthCheck']['url'] ?? '',
      '#description' => $this->t('The health check route, like /{repositoryId}/size.'),
    ];

    // Triplestore user routes.
    $form['triplestore']['routes']['users'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--user'],
      '#title' => 'User routes',
    ];

    $form['triplestore']['routes']['users']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like /rest/security/users.'),
    ];

    $form['triplestore']['routes']['users']['crud'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--user-crud'],
      '#title' => 'User CRUD routes',
    ];

    $form['triplestore']['routes']['users']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create user route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create route, like /.'),
    ];

    $form['triplestore']['routes']['users']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one user route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one route, like /{userId}.'),
    ];

    $form['triplestore']['routes']['users']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all users route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all route.'),
    ];

    $form['triplestore']['routes']['users']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update user route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update route, like /{userId}.'),
    ];

    $form['triplestore']['routes']['users']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete user route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['users']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete route, like /{userId}.'),
    ];

    $form['triplestore']['routes']['misc'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--misc'],
      '#title' => 'Miscellaneous routes',
    ];

    $form['triplestore']['routes']['misc']['healthCheck'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => 'Health check route',
    ];
    $form['triplestore']['routes']['misc']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['misc']['healthCheck']['url'] ?? '',
      '#description' => $this->t('The health check route, like /protocol.'),
    ];

    $form['triplestore']['routes']['misc']['healthCheck']['check'] = [
      '#type' => 'button',
      '#value' => $this->t('Check'),
      '#ajax' => [
        'callback' => [static::class, 'healthCheck'],
        'wrapper' => 'soda-scs--routes-subform--health-check',
      ],
    ];

    $form['triplestore']['routes']['misc']['token'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--token'],
      '#title' => 'Token route',
    ];

    $form['triplestore']['routes']['misc']['token']['tokenUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authentification token URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('triplestore')['routes']['misc']['token']['tokenUrl'] ?? '',
      '#description' => $this->t('The authentification token URL, like /api-token-auth/{userId}.'),
    ];

    // Portainer settings tab.
    $form['portainer'] = [
      '#type' => 'details',
      '#title' => $this->t('Portainer settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['portainer']['portainerOptions'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Portainer options'),
    ];

    $form['portainer']['portainerOptions']['host'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['portainerOptions']['host'] ?? '',
      '#description' => $this->t('The host, like https://portainer.scs.sammlungen.io'),
    ];

    $form['portainer']['portainerOptions']['authenticationToken'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Authentication token'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['portainerOptions']['authenticationToken'] ?? '',
      '#description' => $this->t('The authentication token, like 1234'),
    ];

    $form['portainer']['portainerOptions']['endpointId'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['portainerOptions']['endpointId'] ?? '',
      '#description' => $this->t('The endpoint, like "1".'),
    ];

    $form['portainer']['portainerOptions']['swarmId'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Swarm Id'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['portainerOptions']['swarmId'] ?? '',
      '#description' => $this->t('The swarm Id, like "1".'),
    ];

    // Endpoint routes.
    $form['portainer']['routes']['endpoints'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--endpoints'],
      '#title' => $this->t('Endpoint routes'),
    ];

    $form['portainer']['routes']['endpoints']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Endpoint base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['baseUrl'] ?? '',
      '#description' => $this->t('The endpoint base URL, like /api/endpoints.'),
    ];

    // Portainer health check.
    $form['portainer']['routes']['endpoints']['healthCheck'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => $this->t('Health check route'),
    ];
    $form['portainer']['routes']['endpoints']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['healthCheck']['url'] ?? '',
      '#description' => $this->t('The health check route, like "/{endpointId}"'),
    ];

    $form['portainer']['routes']['endpoints']['healthCheck']['check'] = [
      '#type' => 'button',
      '#value' => $this->t('Check'),
      '#ajax' => [
        'callback' => [static::class, 'healthCheck'],
        'wrapper' => 'soda-scs--routes-subform--health-check',
      ],
    ];

    // Docker API route.
    $form['portainer']['routes']['endpoints']['dockerApi'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api'],
      '#title' => $this->t('Docker API routes'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "/docker".'),
    ];

    // Docker exec routes.
    $form['portainer']['routes']['endpoints']['dockerApi']['exec'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api-exec'],
      '#title' => $this->t('Docker exec routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['exec']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker exec create route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['exec']['createUrl'] ?? '',
      '#description' => $this->t('Route to create a command inside a running container, like "/containers/{containerId}/exec".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['exec']['startUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker exec start route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['exec']['startUrl'] ?? '',
      '#description' => $this->t('Route to start a command inside a running container, like "/exec/{execId}/start".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['exec']['resizeUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker exec resize route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['exec']['resizeUrl'] ?? '',
      '#description' => $this->t('Route to resize a command inside a running container, like "/exec/{execId}/resize".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['exec']['inspectUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker exec inspect route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['exec']['inspectUrl'] ?? '',
      '#description' => $this->t('Route to inspect a command inside a running container, like "/exec/{execId}/json".'),
    ];

    // Docker volumes routes.
    $form['portainer']['routes']['endpoints']['dockerApi']['volumes'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api'],
      '#title' => $this->t('Docker Volume routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker API base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "/volumes".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api-volumes-crud'],
      '#title' => $this->t('Docker Volume CRUD routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create volume route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create volume route, like "/create".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one volume route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one volume route, like "/{volumeId}".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all volumes route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all volumes route, like "".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update volume route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update volume route, like "/{volumeId}".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete volume route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['volumes']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete volume route, like /{volumeId}.'),
    ];

    // Docker containers routes.
    $form['portainer']['routes']['endpoints']['dockerApi']['containers'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api'],
      '#title' => $this->t('Docker Container routes'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Container base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "/containers".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Docker Container CRUD routes'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create container route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create container route, like "/create".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one container route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one container route, like "/{containerId}".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all containers route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all containers route, like "/containers".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update container route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update container route, like "/{containerId}".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete container route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete container route, like "/{containerId}".'),
    ];

    $form['portainer']['routes']['stacks'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--stacks'],
      '#title' => $this->t('Docker Stacks routes'),
    ];

    $form['portainer']['routes']['stacks']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "/stacks".'),
    ];

    $form['portainer']['routes']['stacks']['crud'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Portainer Stack CRUD routes'),
    ];

    $form['portainer']['routes']['stacks']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create route, like "/".'),
    ];

    $form['portainer']['routes']['stacks']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one route, like "/{stackId}".'),
    ];

    $form['portainer']['routes']['stacks']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all route, like "".'),
    ];

    $form['portainer']['routes']['stacks']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update route, like "/{stackId}".'),
    ];

    $form['portainer']['routes']['stacks']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['stacks']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete route, like "/{stackId}".'),
    ];

    // WissKI settings tab.
    $form['wisski'] = [
      '#type' => 'details',
      '#title' => $this->t('WissKI settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    // WissKI instance routes.
    $form['wisski']['instances'] = [
      '#type' => 'fieldset',
      '#title' => 'Instances routes for WissKI components',
    ];

    // Base route.
    $form['wisski']['instances']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['instances']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "https://{instanceId}.scs.sammlungen.io".'),
    ];

    // Misc routes.
    $form['wisski']['instances']['misc']['healthCheck'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--health-check'],
      '#title' => 'Health check route',
    ];
    $form['wisski']['instances']['misc']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('wisski')['instances']['misc']['healthCheck']['url'] ?? '',
      '#description' => $this->t('The health check route, like "/health".'),
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
      ->set('dbManagementHost', $form_state->getValue('dbManagementHost'))
      ->set('dbRootPassword', $form_state->getValue('dbRootPassword'))
      ->set('triplestore', $form_state->getValue('triplestore'))
      ->set('wisski', $form_state->getValue('wisski'))
      ->set('portainer', $form_state->getValue('portainer'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
