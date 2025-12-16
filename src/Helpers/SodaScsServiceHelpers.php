<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;

/**
 * Helper functions for SCS components.
 *
 * @todo Use health check functions from the service actions.
 */
class SodaScsServiceHelpers {
  use StringTranslationTrait;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The settings.
   *
   * @var \Drupal\Core\Config
   */
  protected Config $settings;

  /**
   * The Docker Volumes service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $dockerVolumesServiceActions;

  /**
   * The openGDB service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $triplestoreServiceActions;

  /**
   * The Portainer service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $portainerServiceActions;

  /**
   * The SQL service actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sqlServiceActions;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Initialize database settings.
   *
   * @return array
   *   The database settings.
   */
  public function initDatabaseServiceSettings() {
    $databaseSettings = [];
    $databaseSettings['host'] = $this->settings->get('dbHost');
    $databaseSettings['port'] = $this->settings->get('dbPort');
    $databaseSettings['rootPassword'] = $this->settings->get('dbRootPassword');
    $databaseSettings['managementHost'] = $this->settings->get('dbManagementHost');

    $this->checkSettings($databaseSettings);

    return $databaseSettings;
  }

  /**
   * Initialize docker api settings.
   *
   * @return array
   *   The docker api settings.
   */
  public function initDockerApiSettings() {
    $dockerApiSettings['name'] = 'Docker API';
    $dockerApiSettings['baseUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.baseUrl');

    $this->checkSettings($dockerApiSettings);

    return $dockerApiSettings;
  }

  /**
   * Initialize docker exec service settings.
   *
   * @return array
   *   The docker exec service settings.
   */
  public function initDockerExecServiceSettings() {
    $dockerExecServiceSettings['name'] = 'Docker exec service';
    $dockerExecServiceSettings['createUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.createUrl');
    $dockerExecServiceSettings['startUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.startUrl');
    $dockerExecServiceSettings['resizeUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.resizeUrl');
    $dockerExecServiceSettings['inspectUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.inspectUrl');

    $this->checkSettings($dockerExecServiceSettings);

    return $dockerExecServiceSettings;
  }

  /**
   * Initializes the Docker run service settings.
   *
   * @return array
   *   The Docker run service settings.
   */
  public function initDockerRunServiceSettings(): array {
    $dockerRunServiceSettings['name'] = 'Docker run service';
    $dockerRunServiceSettings['baseUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.baseUrl');
    $dockerRunServiceSettings['createUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.createUrl');
    $dockerRunServiceSettings['readOneUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.readOneUrl');
    $dockerRunServiceSettings['readAllUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.readAllUrl');
    $dockerRunServiceSettings['updateUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.updateUrl');
    $dockerRunServiceSettings['startUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.startUrl');
    $dockerRunServiceSettings['stopUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.stopUrl');
    $dockerRunServiceSettings['restartUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.restartUrl');
    $dockerRunServiceSettings['deleteUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.deleteUrl');
    $dockerRunServiceSettings['removeUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crussrdr.deleteUrl');

    $this->checkSettings($dockerRunServiceSettings);

    return $dockerRunServiceSettings;
  }

  /**
   * Initialize docker volumes service settings.
   *
   * @return array
   *   The docker volumes service settings.
   */
  public function initDockerVolumesServiceSettings() {
    $dockerVolumeServiceSettings['name'] = 'Docker volumes service';
    $dockerVolumeServiceSettings['baseUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.baseUrl');
    $dockerVolumeServiceSettings['createUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.createUrl');
    $dockerVolumeServiceSettings['readOneUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.readOneUrl');
    $dockerVolumeServiceSettings['readAllUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.readAllUrl');
    $dockerVolumeServiceSettings['updateUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.updateUrl');
    $dockerVolumeServiceSettings['deleteUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.deleteUrl');

    $this->checkSettings($dockerVolumeServiceSettings);

    return $dockerVolumeServiceSettings;
  }

  /**
   * Initialize general settings.
   *
   * @return array
   *   The general settings.
   */
  public function initGeneralSettings() {
    $generalSettings['name'] = 'General';
    $generalSettings['scsHost'] = $this->settings->get('scsHost');

    $this->checkSettings($generalSettings);

    return $generalSettings;
  }

  /**
   * Initialize JupyterHub settings.
   *
   * @return array
   *   The JupyterHub settings.
   */
  public function initJupyterHubSettings() {
    $jupyterHubSettings['name'] = 'JupyterHub';
    $jupyterHubSettings['baseUrl'] = $this->settings->get('jupyterhub.generalSettings.baseUrl');

    $this->checkSettings($jupyterHubSettings);

    return $jupyterHubSettings;
  }

  /**
   * Initialize keycloak settings.
   *
   * @return array
   *   The keycloak settings.
   */
  public function initKeycloakGeneralSettings() {
    $keycloakSettings['name'] = 'Keycloak general';
    $keycloakSettings['host'] = $this->settings->get('keycloak.keycloakTabs.generalSettings.fields.keycloakHost');
    $keycloakSettings['realm'] = $this->settings->get('keycloak.keycloakTabs.generalSettings.fields.keycloakRealm');
    $keycloakSettings['adminUsername'] = $this->settings->get('keycloak.keycloakTabs.generalSettings.fields.adminUsername');
    $keycloakSettings['adminPassword'] = $this->settings->get('keycloak.keycloakTabs.generalSettings.fields.adminPassword');
    $keycloakSettings['openIdConnectClientMachineName'] = $this->settings->get('keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName');

    // Miscellaneous routes.
    $keycloakSettings['tokenUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.misc.fields.tokenUrl');

    $this->checkSettings($keycloakSettings);

    return $keycloakSettings;
  }

  /**
   * Initialize keycloak settings.
   *
   * @return array
   *   The keycloak settings.
   */
  public function initKeycloakClientsSettings() {
    $keycloakSettings['name'] = 'Keycloak clients';
    $keycloakSettings['baseUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.baseUrl');
    $keycloakSettings['createUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.crud.fields.createUrl');
    $keycloakSettings['readOneUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.crud.fields.readOneUrl');
    $keycloakSettings['readAllUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.crud.fields.readAllUrl');
    $keycloakSettings['updateUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.crud.fields.updateUrl');
    $keycloakSettings['deleteUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.crud.fields.deleteUrl');

    // Health check URL.
    $keycloakSettings['healthCheckUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.clients.fields.healthCheck.fields.url');

    $this->checkSettings($keycloakSettings);

    return $keycloakSettings;
  }

  /**
   * Initialize keycloak groups settings.
   *
   * @return array
   *   The keycloak groups settings.
   */
  public function initKeycloakGroupsSettings() {
    $keycloakSettings['name'] = 'Keycloak groups';
    $keycloakSettings['baseUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.baseUrl');
    $keycloakSettings['createUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.createUrl');
    $keycloakSettings['readOneUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.readOneUrl');
    $keycloakSettings['readAllUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.readAllUrl');
    $keycloakSettings['updateUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.updateUrl');
    $keycloakSettings['deleteUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.deleteUrl');
    $keycloakSettings['getMembersUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.groups.fields.crud.fields.getMembersUrl');

    $this->checkSettings($keycloakSettings);

    return $keycloakSettings;
  }

  /**
   * Initialize keycloak users settings.
   *
   * @return array
   *   The keycloak users settings.
   */
  public function initKeycloakUsersSettings() {
    $keycloakSettings['name'] = 'Keycloak users';
    $keycloakSettings['baseUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.baseUrl');
    $keycloakSettings['createUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.createUrl');
    $keycloakSettings['readOneUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.readOneUrl');
    $keycloakSettings['readAllUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.readAllUrl');
    $keycloakSettings['updateUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.updateUrl');
    $keycloakSettings['deleteUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.deleteUrl');
    $keycloakSettings['getGroupsOfUserUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.getGroupsOfUserUrl');
    $keycloakSettings['addUserToGroupUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.addUserToGroupUrl');
    $keycloakSettings['removeUserFromGroupUrl'] = $this->settings->get('keycloak.keycloakTabs.routes.fields.users.fields.crud.fields.removeUserFromGroupUrl');

    $this->checkSettings($keycloakSettings);

    return $keycloakSettings;
  }

  /**
   * Initialize nextcloud settings.
   *
   * @return array
   *   The nextcloud settings.
   */
  public function initNextcloudSettings() {
    $nextcloudSettings['name'] = 'Nextcloud';
    $nextcloudSettings['baseUrl'] = $this->settings->get('nextcloud.generalSettings.baseUrl');

    $this->checkSettings($nextcloudSettings);

    return $nextcloudSettings;
  }

  /**
   * Initialize portainer service settings.
   *
   * @return array
   *   The portainer service settings.
   */
  public function initPortainerServiceSettings() {
    $portainerServiceSettings['name'] = 'Portainer';
    $portainerServiceSettings['host'] = $this->settings->get('portainer.portainerOptions.host');
    $portainerServiceSettings['authenticationToken'] = $this->settings->get('portainer.portainerOptions.authenticationToken');
    $portainerServiceSettings['endpointId'] = $this->settings->get('portainer.portainerOptions.endpointId');
    $portainerServiceSettings['baseUrl'] = $this->settings->get('portainer.routes.endpoints.baseUrl');
    $portainerServiceSettings['healthCheckUrl'] = $this->settings->get('portainer.routes.endpoints.healthCheck.url');

    $this->checkSettings($portainerServiceSettings);

    return $portainerServiceSettings;
  }

  /**
   * Initialize Portainer stacks settings.
   *
   * @return array
   *   The Portainer stacks settings.
   */
  public function initPortainerStacksSettings() {
    $portainerStacksSettings['name'] = 'Portainer stacks';
    $portainerStacksSettings['baseUrl'] = $this->settings->get('portainer.routes.stacks.baseUrl');
    $portainerStacksSettings['createUrl'] = $this->settings->get('portainer.routes.stacks.crud.createUrl');
    $portainerStacksSettings['readOneUrl'] = $this->settings->get('portainer.routes.stacks.crud.readOneUrl');
    $portainerStacksSettings['readAllUrl'] = $this->settings->get('portainer.routes.stacks.crud.readAllUrl');
    $portainerStacksSettings['updateUrl'] = $this->settings->get('portainer.routes.stacks.crud.updateUrl');
    $portainerStacksSettings['deleteUrl'] = $this->settings->get('portainer.routes.stacks.crud.deleteUrl');

    $this->checkSettings($portainerStacksSettings);

    return $portainerStacksSettings;
  }

  /**
   * Initialize Triplestore misc settings (triplestore misc).
   *
   * @return array
   *   The Triplestore misc settings.
   */
  public function initTriplestoreMiscSettings() {
    $triplestoreMiscSettings['name'] = 'Triplestore misc';
    $triplestoreMiscSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.misc.healthCheck.url');
    $triplestoreMiscSettings['tokenUrl'] = $this->settings->get('triplestore.routes.misc.token.tokenUrl');

    $this->checkSettings($triplestoreMiscSettings);

    return $triplestoreMiscSettings;
  }

  /**
   * Initialize docker volumes service settings.
   *
   * @return array
   *   The docker volumes service settings.
   */
  public function initTriplestoreRepositoriesSettings() {
    $triplestoreRepositoriesSettings['name'] = 'Triplestore repositories';
    $triplestoreRepositoriesSettings['baseUrl'] = $this->settings->get('triplestore.routes.repositories.baseUrl');
    $triplestoreRepositoriesSettings['createUrl'] = $this->settings->get('triplestore.routes.repositories.crud.createUrl');
    $triplestoreRepositoriesSettings['readOneUrl'] = $this->settings->get('triplestore.routes.repositories.crud.readOneUrl');
    $triplestoreRepositoriesSettings['readAllUrl'] = $this->settings->get('triplestore.routes.repositories.crud.readAllUrl');
    $triplestoreRepositoriesSettings['updateUrl'] = $this->settings->get('triplestore.routes.repositories.crud.updateUrl');
    $triplestoreRepositoriesSettings['deleteUrl'] = $this->settings->get('triplestore.routes.repositories.crud.deleteUrl');
    $triplestoreRepositoriesSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.repositories.healthCheck.url');

    $this->checkSettings($triplestoreRepositoriesSettings);

    return $triplestoreRepositoriesSettings;
  }

  /**
   * Initialize openGDB service settings.
   *
   * @return array
   *   The openGDB service settings.
   */
  public function initTriplestoreServiceSettings() {
    $triplestoreServiceSettings['name'] = 'Triplestore';
    $triplestoreServiceSettings['host'] = $this->settings->get('triplestore.generalSettings.host');
    $triplestoreServiceSettings['port'] = $this->settings->get('triplestore.generalSettings.port');
    $triplestoreServiceSettings['adminUsername'] = $this->settings->get('triplestore.generalSettings.adminUsername');
    $triplestoreServiceSettings['adminPassword'] = $this->settings->get('triplestore.generalSettings.adminPassword');
    $triplestoreServiceSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.misc.healthCheck.url');

    $this->checkSettings($triplestoreServiceSettings);

    return $triplestoreServiceSettings;
  }

  /**
   * Initialize Triplestore user settings (triplestore users).
   *
   * @return array
   *   The Triplestore user settings.
   */
  public function initTriplestoreUserSettings() {
    $triplestoreUserSettings['name'] = 'Triplestore users';
    $triplestoreUserSettings['baseUrl'] = $this->settings->get('triplestore.routes.users.baseUrl');
    $triplestoreUserSettings['createUrl'] = $this->settings->get('triplestore.routes.users.crud.createUrl');
    $triplestoreUserSettings['readOneUrl'] = $this->settings->get('triplestore.routes.users.crud.readOneUrl');
    $triplestoreUserSettings['readAllUrl'] = $this->settings->get('triplestore.routes.users.crud.readAllUrl');
    $triplestoreUserSettings['updateUrl'] = $this->settings->get('triplestore.routes.users.crud.updateUrl');
    $triplestoreUserSettings['deleteUrl'] = $this->settings->get('triplestore.routes.users.crud.deleteUrl');

    $this->checkSettings($triplestoreUserSettings);

    return $triplestoreUserSettings;
  }

  /**
   * Initialize Webprotege instance settings.
   *
   * @return array
   *   The Webprotege instance settings.
   */
  public function initWebprotegeInstanceSettings() {
    $webprotegeInstanceSettings['name'] = 'Webprotege instance';
    $webprotegeInstanceSettings['host'] = $this->settings->get('webprotege.generalSettings.host');

    $this->checkSettings($webprotegeInstanceSettings);

    return $webprotegeInstanceSettings;
  }

  /**
   * Initialize WissKI instance settings.
   *
   * @return array
   *   The WissKI instance settings.
   */
  public function initWisskiInstanceSettings() {
    $wisskiInstanceSettings['name'] = 'WissKI instance';
    $wisskiInstanceSettings['baseUrl'] = $this->settings->get('wisski.instances.baseUrl');
    $wisskiInstanceSettings['healthCheckUrl'] = $this->settings->get('wisski.instances.misc.healthCheck.url');
    $wisskiInstanceSettings['productionVersion'] = $this->settings->get('wisski.instances.versions.production');
    // Production versions are hardcoded as defaults in docker-compose.yml in
    // stack repository.
    $wisskiInstanceSettings['stackDevelopmentVersion'] = $this->settings->get('wisski.instances.versions.development.composeStack');
    $wisskiInstanceSettings['imageDevelopmentVersion'] = $this->settings->get('wisski.instances.versions.development.image');
    $wisskiInstanceSettings['starterRecipeDevelopmentVersion'] = $this->settings->get('wisski.instances.versions.development.starterRecipe');
    $wisskiInstanceSettings['defaultDataModelRecipeDevelopmentVersion'] = $this->settings->get('wisski.instances.versions.development.defaultDataModelRecipe');
    $wisskiInstanceSettings['varnishImageDevelopmentVersion'] = $this->settings->get('wisski.instances.versions.development.varnishImage');
    $this->checkSettings($wisskiInstanceSettings);

    return $wisskiInstanceSettings;
  }

  /**
   * Check if the settings are valid, replace {empty} with empty string.
   *
   * @param array $settings
   *   The settings to check.
   */
  private function checkSettings(array &$settings) {
    foreach ($settings as $key => &$value) {
      if (empty($value)) {
        throw new MissingDataException($settings['name'] . ' ' . $key . ' setting is not set.');
      }
      else {
        $value = str_replace('{empty}', '', $value);
      }
    }
  }

}
