<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Psr\Log\LogLevel;

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
        <li><strong>{clientUuid}</strong> - The Keycloak client UUID</li>
        <li><strong>{containerId}</strong> - The Docker container ID</li>
        <li><strong>{endpointId}</strong> - The Portainer endpoint ID</li>
        <li><strong>{execId}</strong> - The Docker exec ID</li>
        <li><strong>{groupId}</strong> - The Keycloak group ID</li>
        <li><strong>{instanceId}</strong> - The WissKI instance ID</li>
        <li><strong>{realm}</strong> - The Keycloak realm</li>
        <li><strong>{repositoryId}</strong> - The triplestore repository ID</li>
        <li><strong>{stackId}</strong> - The Portainer stack ID</li>
        <li><strong>{userId}</strong> - The user ID (triplestore, Keycloak etc.)</li>
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

    $form['general']['fields']['administratorEmail'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Administrator email'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('administratorEmail'),
      '#description' => $this->t('The administrator email, like admin@scs.sammlungen.io.'),
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

    // @todo Replace fields with generalSettings.
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

    // Jupyter settings tab.
    // @see \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers::initJupyterHubSettings().
    $form['jupyterhub'] = [
      '#type' => 'details',
      '#title' => $this->t('JupyterHub settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['jupyterhub']['generalSettings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['jupyterhub']['generalSettings']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('jupyterhub')['generalSettings']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like https://jupyterhub.scs.sammlungen.io.'),
    ];

    // Keycloak settings tab.
    // @see \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers::initKeycloakSettings().
    $form['keycloak'] = [
      '#type' => 'details',
      '#title' => $this->t('Keycloak settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['keycloak']['generalSettings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Keycloak settings'),
    ];

    $form['keycloak']['generalSettings']['keycloakHost'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Keycloak host'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['generalSettings']['keycloakHost'] ?? '',
      '#description' => $this->t('The keycloak host, like https://auth.sammlungen.io.'),
    ];

    $form['keycloak']['generalSettings']['keycloakRealm'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Keycloak realm'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['generalSettings']['keycloakRealm'] ?? '',
      '#description' => $this->t('The keycloak realm, like wisski.'),
    ];

    $form['keycloak']['generalSettings']['adminUsername'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin username'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['generalSettings']['adminUsername'] ?? '',
      '#description' => $this->t('The keycloak admin username, like admin.'),
    ];

    $form['keycloak']['generalSettings']['adminPassword'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Admin password'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['generalSettings']['adminPassword'] ?? '',
      '#description' => $this->t('The keycloak admin password, like admin.'),
    ];

    $form['keycloak']['routes'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Keycloak routes'),
    ];

    $form['keycloak']['routes']['clients'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Clients routes'),
    ];

    $form['keycloak']['routes']['clients']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like /admin/realms/{realm}/clients.'),
    ];

    $form['keycloak']['routes']['clients']['crud'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CRUD routes'),
    ];

    $form['keycloak']['routes']['clients']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create URL, like {emtpy}.'),
    ];

    $form['keycloak']['routes']['clients']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one URL, like /{clientId}.'),
    ];

    $form['keycloak']['routes']['clients']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all URL, like {empty}.'),
    ];

    $form['keycloak']['routes']['clients']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update URL, like /{clientId}.'),
    ];

    $form['keycloak']['routes']['clients']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete URL, like /{clientId}.'),
    ];

    $form['keycloak']['routes']['clients']['healthCheck']['url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Health check URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['clients']['healthCheck']['url'] ?? '',
      '#description' => $this->t('The health check URL.'),
    ];

    $form['keycloak']['routes']['groups'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Groups routes'),
    ];

    $form['keycloak']['routes']['groups']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like /admin/realms/{realm}/groups.'),
    ];

    $form['keycloak']['routes']['groups']['crud'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CRUD routes'),
    ];

    $form['keycloak']['routes']['groups']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create URL, like {empty}.'),
    ];

    $form['keycloak']['routes']['groups']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one URL, like /{groupId}.'),
    ];

    $form['keycloak']['routes']['groups']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all URL, like {empty}.'),
    ];

    $form['keycloak']['routes']['groups']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update URL, like /{groupId}.'),
    ];

    $form['keycloak']['routes']['groups']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['groups']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete URL, like /{groupId}.'),
    ];

    $form['keycloak']['routes']['users'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Users routes'),
    ];

    $form['keycloak']['routes']['users']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like /admin/realms/{realm}/users.'),
    ];

    $form['keycloak']['routes']['users']['crud'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('CRUD routes'),
    ];

    $form['keycloak']['routes']['users']['crud']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Create URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['createUrl'] ?? '',
      '#description' => $this->t('The create URL, like {empty}.'),
    ];

    $form['keycloak']['routes']['users']['crud']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read one URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one URL, like /{userId}.'),
    ];

    $form['keycloak']['routes']['users']['crud']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Read all URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all URL, like {empty}.'),
    ];

    $form['keycloak']['routes']['users']['crud']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Update URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['updateUrl'] ?? '',
      '#description' => $this->t('The update URL, like /{userId}.'),
    ];

    $form['keycloak']['routes']['users']['crud']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete URL, like /{userId}  .'),
    ];

    $form['keycloak']['routes']['users']['crud']['getGroupsUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Get groups URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['getGroupsUrl'] ?? '',
      '#description' => $this->t('The get groups URL, like /{userId}/groups.'),
    ];

    $form['keycloak']['routes']['users']['crud']['updateGroupsUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Add user to group URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['updateGroupsUrl'] ?? '',
      '#description' => $this->t('The update user group URL, like /{userId}/groups/{groupId}.'),
    ];

    $form['keycloak']['routes']['users']['crud']['deleteGroupsUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Delete user from group URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['users']['crud']['deleteGroupsUrl'] ?? '',
      '#description' => $this->t('The delete user from group URL, like /{userId}/groups/{groupId}.'),
    ];

    $form['keycloak']['routes']['misc'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--misc'],
      '#title' => 'Miscellaneous routes',
    ];

    $form['keycloak']['routes']['misc']['tokenUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Token URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('keycloak')['routes']['misc']['tokenUrl'] ?? '',
      '#description' => $this->t('The token URL, like /realms/master/protocol/openid-connect/token.'),
    ];

    // Nextcloud settings tab.
    // @see \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers::initNextcloudSettings().
    $form['nextcloud'] = [
      '#type' => 'details',
      '#title' => $this->t('Nextcloud settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['nextcloud']['generalSettings'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('General settings'),
    ];

    $form['nextcloud']['generalSettings']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Base URL'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('nextcloud')['generalSettings']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like https://nextcloud.scs.sammlungen.io.'),
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

    // Docker container routes.
    $form['portainer']['routes']['endpoints']['dockerApi']['containers'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api-containers'],
      '#title' => $this->t('Docker Container routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container API base route'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['baseUrl'] ?? '',
      '#description' => $this->t('The base URL, like "/containers".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Docker Container CRUSSRDR routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['createUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container create route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['createUrl'] ?? '',
      '#description' => $this->t('The create route, like "/create".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['readOneUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container read one route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['readOneUrl'] ?? '',
      '#description' => $this->t('The read one route, like "/{containerId}/json".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['readAllUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container read all route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['readAllUrl'] ?? '',
      '#description' => $this->t('The read all route, like "/json".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['updateUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container update route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['updateUrl'] ?? '',
      '#description' => $this->t('The update route, like "/{containerId}/update".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['startUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container start route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['startUrl'] ?? '',
      '#description' => $this->t('The start route, like "/{containerId}/start".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['stopUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container stop route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['stopUrl'] ?? '',
      '#description' => $this->t('The stop route, like "/{containerId}/stop".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['restartUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container restart route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['restartUrl'] ?? '',
      '#description' => $this->t('The restart route, like "/{containerId}/restart".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['deleteUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container delete route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['deleteUrl'] ?? '',
      '#description' => $this->t('The delete route, like "/{containerId}".'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['containers']['crussrdr']['removeUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker Container remove route.'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('portainer')['routes']['endpoints']['dockerApi']['containers']['crussrdr']['removeUrl'] ?? '',
      '#description' => $this->t('The remove route, like "/{containerId}/prune".'),
    ];

    // Docker volumes routes.
    $form['portainer']['routes']['endpoints']['dockerApi']['volumes'] = [
      '#type' => 'fieldset',
      '#attributes' => ['id' => 'soda-scs--routes-subform--docker-api'],
      '#title' => $this->t('Docker Volume routes.'),
    ];

    $form['portainer']['routes']['endpoints']['dockerApi']['volumes']['baseUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Docker volumes API base route'),
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

    // Portainer stacks routes.
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

    // Security settings tab.
    $form['security'] = [
      '#type' => 'details',
      '#title' => $this->t('Security Settings'),
      '#group' => 'tabs',
      '#tree' => TRUE,
    ];

    $form['security']['logging'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Secure Logging'),
      '#description' => $this->t('Configure security and logging options for the SODa SCS Manager.'),
    ];

    $form['security']['logging']['sanitize_logs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Sanitize sensitive data in logs'),
      '#description' => $this->t('When enabled, passwords, API keys, and other sensitive data will be automatically sanitized from log messages. <strong>Highly recommended for production environments.</strong>'),
      '#default_value' => $this->config('soda_scs_manager.settings')->get('security')['logging']['sanitize_logs'] ?? TRUE,
    ];

    $form['security']['logging']['log_level'] = [
      '#type' => 'select',
      '#title' => $this->t('Minimum log level'),
      '#description' => $this->t('Only log messages at or above this level will be written to the logs.'),
      '#options' => [
        LogLevel::DEBUG => $this->t('Debug'),
        LogLevel::INFO => $this->t('Info'),
        LogLevel::NOTICE => $this->t('Notice'),
        LogLevel::WARNING => $this->t('Warning'),
        LogLevel::ERROR => $this->t('Error'),
        LogLevel::CRITICAL => $this->t('Critical'),
        LogLevel::ALERT => $this->t('Alert'),
        LogLevel::EMERGENCY => $this->t('Emergency'),
      ],
      '#default_value' => $this->config('soda_scs_manager.settings')->get('security')['logging']['log_level'] ?? LogLevel::INFO,
    ];

    $form['security']['logging']['warning'] = [
      '#type' => 'item',
      '#markup' => '<div class="messages messages--warning">' .
      $this->t('<strong>Security Warning:</strong> Disabling log sanitization may expose sensitive information like passwords and API keys in log files. Only disable this setting in development environments and ensure logs are properly secured.') .
      '</div>',
      '#states' => [
        'visible' => [
          ':input[name="security[logging][sanitize_logs]"]' => ['checked' => FALSE],
        ],
      ],
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
      ->set('administratorEmail', $form_state->getValue('administratorEmail'))
      ->set('dbHost', $form_state->getValue('dbHost'))
      ->set('dbPort', $form_state->getValue('dbPort'))
      ->set('dbManagementHost', $form_state->getValue('dbManagementHost'))
      ->set('dbRootPassword', $form_state->getValue('dbRootPassword'))
      ->set('jupyterhub', $form_state->getValue('jupyterhub'))
      ->set('keycloak', $form_state->getValue('keycloak'))
      ->set('nextcloud', $form_state->getValue('nextcloud'))
      ->set('triplestore', $form_state->getValue('triplestore'))
      ->set('wisski', $form_state->getValue('wisski'))
      ->set('portainer', $form_state->getValue('portainer'))
      ->set('security', $form_state->getValue('security'))
      ->save();

    // Log the configuration change with secure logging.
    $securitySettings = $form_state->getValue('security');
    $this->logger('soda_scs_manager')->info(
      'SODa SCS Manager settings updated. Log sanitization: @sanitize, Log level: @level',
      [
        '@sanitize' => $securitySettings['logging']['sanitize_logs'] ? 'enabled' : 'disabled',
        '@level' => $securitySettings['logging']['log_level'],
      ]
    );
    parent::submitForm($form, $form_state);
  }

}
