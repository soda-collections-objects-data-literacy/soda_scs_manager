<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;


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
   * Initialize docker volumes service settings.
   *
   * @return array
   *   The docker volumes service settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initDockerVolumesServiceSettings() {
    $dockerVolumeServiceSettings = [];
    $dockerVolumeServiceSettings['dockerApiBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.baseUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.baseUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesCreateUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.createUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesReadOneUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.readOneUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesReadAllUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.readAllUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesUpdateUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.updateUrl');
    $dockerVolumeServiceSettings['dockerApiVolumesDeleteUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.volumes.crud.deleteUrl');

    if (empty($dockerVolumeServiceSettings['dockerApiBaseUrlRoute'])) {
      throw new MissingDataException('Docker API URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesBaseUrlRoute'])) {
      throw new MissingDataException('Docker API volumes URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesCreateUrlRoute'])) {
      throw new MissingDataException('Docker API volumes create URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesReadOneUrlRoute'])) {
      throw new MissingDataException('Docker API volumes read one URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesReadAllUrlRoute'])) {
      throw new MissingDataException('Docker API volumes read all URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesUpdateUrlRoute'])) {
      throw new MissingDataException('Docker API volumes update URL setting is not set.');
    }
    if (empty($dockerVolumeServiceSettings['dockerApiVolumesDeleteUrlRoute'])) {
      throw new MissingDataException('Docker API volumes delete URL setting is not set.');
    }

    foreach ($dockerVolumeServiceSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $dockerVolumeServiceSettings;
  }

  /**
   * Initialize general settings.
   *
   * @return array
   *   The general settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initGeneralSettings() {
    $generalSettings = [];
    $generalSettings['scsHost'] = $this->settings->get('scsHost');

    if (empty($generalSettings['scsHost'])) {
      throw new MissingDataException('SCS host setting is not set.');
    }

    return $generalSettings;
  }

  /**
   * Initialize database settings.
   *
   * @return array
   *   The database settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initDatabaseSettings() {
    $databaseSettings = [];
    $databaseSettings['dbHost'] = $this->settings->get('dbHost');
    $databaseSettings['dbPort'] = $this->settings->get('dbPort');
    $databaseSettings['dbRootPassword'] = $this->settings->get('dbRootPassword');
    $databaseSettings['dbManagementHost'] = $this->settings->get('dbManagementHost');

    if (empty($databaseSettings['dbHost'])) {
      throw new MissingDataException('Database host setting is not set.');
    }
    if (empty($databaseSettings['dbPort'])) {
      throw new MissingDataException('Database port setting is not set.');
    }
    if (empty($databaseSettings['dbRootPassword'])) {
      throw new MissingDataException('Database root password setting is not set.');
    }
    if (empty($databaseSettings['dbManagementHost'])) {
      throw new MissingDataException('Database management host setting is not set.');
    }

    foreach ($databaseSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $databaseSettings;
  }

  /**
   * Initialize Triplestore user settings (triplestore users).
   *
   * @return array
   *   The Triplestore user settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initTriplestoreUserSettings() {
    $triplestoreUserSettings = [];
    $triplestoreUserSettings['baseUrl'] = $this->settings->get('triplestore.routes.users.baseUrl');
    $triplestoreUserSettings['createUrl'] = $this->settings->get('triplestore.routes.users.crud.createUrl');
    $triplestoreUserSettings['readOneUrl'] = $this->settings->get('triplestore.routes.users.crud.readOneUrl');
    $triplestoreUserSettings['readAllUrl'] = $this->settings->get('triplestore.routes.users.crud.readAllUrl');
    $triplestoreUserSettings['updateUrl'] = $this->settings->get('triplestore.routes.users.crud.updateUrl');
    $triplestoreUserSettings['deleteUrl'] = $this->settings->get('triplestore.routes.users.crud.deleteUrl');

    if (empty($triplestoreUserSettings['baseUrl'])) {
      throw new MissingDataException('Triplestore users base URL setting is not set.');
    }
    if (empty($triplestoreUserSettings['createUrl'])) {
      throw new MissingDataException('Triplestore users create URL setting is not set.');
    }
    if (empty($triplestoreUserSettings['readOneUrl'])) {
      throw new MissingDataException('Triplestore users read one URL setting is not set.');
    }
    if (empty($triplestoreUserSettings['readAllUrl'])) {
      throw new MissingDataException('Triplestore users read all URL setting is not set.');
    }
    if (empty($triplestoreUserSettings['updateUrl'])) {
      throw new MissingDataException('Triplestore users update URL setting is not set.');
    }
    if (empty($triplestoreUserSettings['deleteUrl'])) {
      throw new MissingDataException('Triplestore users delete URL setting is not set.');
    }

    foreach ($triplestoreUserSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreUserSettings;
  }

  /**
   * Initialize Triplestore misc settings (triplestore misc).
   *
   * @return array
   *   The Triplestore misc settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initTriplestoreMiscSettings() {
    $triplestoreMiscSettings = [];
    $triplestoreMiscSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.misc.healthCheck.url');
    $triplestoreMiscSettings['tokenUrl'] = $this->settings->get('triplestore.routes.misc.token.tokenUrl');

    if (empty($triplestoreMiscSettings['healthCheckUrl'])) {
      throw new MissingDataException('Triplestore health check URL setting is not set.');
    }
    if (empty($triplestoreMiscSettings['tokenUrl'])) {
      throw new MissingDataException('Triplestore token URL setting is not set.');
    }

    foreach ($triplestoreMiscSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreMiscSettings;
  }

  /**
   * Initialize Docker container settings.
   *
   * @return array
   *   The Docker container settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initDockerContainerSettings() {
    $dockerContainerSettings = [];
    $dockerContainerSettings['baseUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.baseUrl');
    $dockerContainerSettings['createUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crud.createUrl');
    $dockerContainerSettings['readOneUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crud.readOneUrl');
    $dockerContainerSettings['readAllUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crud.readAllUrl');
    $dockerContainerSettings['updateUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crud.updateUrl');
    $dockerContainerSettings['deleteUrl'] = $this->settings->get('portainer.routes.endpoints.dockerApi.containers.crud.deleteUrl');

    if (empty($dockerContainerSettings['baseUrl'])) {
      throw new MissingDataException('Docker containers base URL setting is not set.');
    }
    if (empty($dockerContainerSettings['createUrl'])) {
      throw new MissingDataException('Docker containers create URL setting is not set.');
    }
    if (empty($dockerContainerSettings['readOneUrl'])) {
      throw new MissingDataException('Docker containers read one URL setting is not set.');
    }
    if (empty($dockerContainerSettings['readAllUrl'])) {
      throw new MissingDataException('Docker containers read all URL setting is not set.');
    }
    if (empty($dockerContainerSettings['updateUrl'])) {
      throw new MissingDataException('Docker containers update URL setting is not set.');
    }
    if (empty($dockerContainerSettings['deleteUrl'])) {
      throw new MissingDataException('Docker containers delete URL setting is not set.');
    }

    foreach ($dockerContainerSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $dockerContainerSettings;
  }

  /**
   * Initialize openGDB service settings.
   *
   * @return array
   *   The openGDB service settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initTriplestoreServiceSettings() {
    $triplestoreServiceSettings = [];
    $triplestoreServiceSettings['triplestoreHostRoute'] = $this->settings->get('triplestore.generalSettings.host');
    $triplestoreServiceSettings['triplestorePort'] = $this->settings->get('triplestore.generalSettings.port');
    $triplestoreServiceSettings['triplestoreadminUsername'] = $this->settings->get('triplestore.generalSettings.adminUsername');
    $triplestoreServiceSettings['triplestoreadminPassword'] = $this->settings->get('triplestore.generalSettings.adminPassword');

    if (empty($triplestoreServiceSettings['triplestoreHostRoute'])) {
      throw new MissingDataException('Triplestore host URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestorePort'])) {
      throw new MissingDataException('Triplestore port URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestoreadminUsername'])) {
      throw new MissingDataException('Triplestore admin username URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestoreadminPassword'])) {
      throw new MissingDataException('Triplestore admin password URL setting is not set.');
    }

    foreach ($triplestoreServiceSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreServiceSettings;
  }

  /**
   * Initialize docker volumes service settings.
   *
   * @return array
   *   The docker volumes service settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initTriplestoreRepositoriesSettings() {
    $triplestoreRepositoriesSettings = [];
    $triplestoreRepositoriesSettings['triplestoreRepositoriesBaseUrlRoute'] = $this->settings->get('triplestore.routes.repositories.baseUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesCreateUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.createUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesReadOneUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.readOneUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesReadAllUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.readAllUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesUpdateUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.updateUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesDeleteUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.deleteUrl');
    $triplestoreRepositoriesSettings['triplestoreRepositoriesHealthCheckUrlRoute'] = $this->settings->get('triplestore.routes.repositories.crud.healthCheck.url');

    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesBaseUrlRoute'])) {
      throw new MissingDataException('Triplestore base URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesBaseUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesCreateUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes create URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesReadOneUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes read one URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesReadAllUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes read all URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesUpdateUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes update URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesVolumesDeleteUrlRoute'])) {
      throw new MissingDataException('Triplestore volumes delete URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['triplestoreRepositoriesHealthCheckUrlRoute'])) {
      throw new MissingDataException('Triplestore health check URL setting is not set.');
    }

    foreach ($triplestoreRepositoriesSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreRepositoriesSettings;
  }

  /**
   * Initialize portainer service settings.
   *
   * @return array
   *   The portainer service settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initPortainerServiceSettings() {
    $portainerServiceSettings = [];
    $portainerServiceSettings['portainerHostRoute'] = $this->settings->get('portainer.portainerOptions.host');
    $portainerServiceSettings['portainerAuthenticationTokenRoute'] = $this->settings->get('portainer.portainerOptions.authenticationToken');
    $portainerServiceSettings['portainerEndpointId'] = $this->settings->get('portainer.portainerOptions.endpointId');
    $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.baseUrl');
    $portainerServiceSettings['portainerEndpointsHealthCheckUrl'] = $this->settings->get('portainer.routes.endpoints.healthCheck.url');

    if (empty($portainerServiceSettings['portainerHostRoute'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }
    if (empty($portainerServiceSettings['portainerAuthenticationTokenRoute'])) {
      throw new MissingDataException('Portainer authentication token setting is not set.');
    }
    if (empty($portainerServiceSettings['portainerEndpointId'])) {
      throw new MissingDataException('Portainer endpoint setting is not set.');
    }
    if (empty($portainerServiceSettings['portainerEndpointsBaseUrlRoute'])) {
      throw new MissingDataException('Portainer endpoints base URL setting is not set.');
    }

    if (empty($portainerServiceSettings['portainerEndpointsHealthCheckUrl'])) {
      throw new MissingDataException('Portainer endpoint health check endpoint setting is not set.');
    }

    return $portainerServiceSettings;
  }

  /**
   * Initialize Portainer stacks settings.
   *
   * @return array
   *   The Portainer stacks settings.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initPortainerStacksSettings() {
    $portainerStacksSettings = [];
    $portainerStacksSettings['portainerStacksBaseUrl'] = $this->settings->get('portainer.routes.stacks.baseUrl');
    $portainerStacksSettings['portainerStacksCreateUrl'] = $this->settings->get('portainer.routes.stacks.crud.createUrl');
    $portainerStacksSettings['portainerStacksReadOneUrl'] = $this->settings->get('portainer.routes.stacks.crud.readOneUrl');
    $portainerStacksSettings['portainerStacksReadAllUrl'] = $this->settings->get('portainer.routes.stacks.crud.readAllUrl');
    $portainerStacksSettings['portainerStacksUpdateUrl'] = $this->settings->get('portainer.routes.stacks.crud.updateUrl');
    $portainerStacksSettings['portainerStacksDeleteUrl'] = $this->settings->get('portainer.routes.stacks.crud.deleteUrl');

    if (empty($portainerStacksSettings['portainerStacksBaseUrl'])) {
      throw new MissingDataException('Portainer stacks base URL setting is not set.');
    }
    if (empty($portainerStacksSettings['portainerStacksCreateUrl'])) {
      throw new MissingDataException('Portainer stacks create URL setting is not set.');
    }
    if (empty($portainerStacksSettings['portainerStacksReadOneUrl'])) {
      throw new MissingDataException('Portainer stacks read one URL setting is not set.');
    }
    if (empty($portainerStacksSettings['portainerStacksReadAllUrl'])) {
      throw new MissingDataException('Portainer stacks read all URL setting is not set.');
    }
    if (empty($portainerStacksSettings['portainerStacksUpdateUrl'])) {
      throw new MissingDataException('Portainer stacks update URL setting is not set.');
    }
    if (empty($portainerStacksSettings['portainerStacksDeleteUrl'])) {
      throw new MissingDataException('Portainer stacks delete URL setting is not set.');
    }

    foreach ($portainerStacksSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $portainerStacksSettings;
  }

}
