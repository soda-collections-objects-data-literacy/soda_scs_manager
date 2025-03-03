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
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *   Thrown when a required setting is missing.
   */
  public function initDatabaseServiceSettings() {
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
   * Initialize docker exec service settings.
   *
   * @return array
   *   The docker exec service settings.
   */
  public function initDockerExecServiceSettings() {
    $dockerExecServiceSettings = [];
    $dockerExecServiceSettings['dockerApiExecCreateUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.createUrl');
    $dockerExecServiceSettings['dockerApiExecStartUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.startUrl');
    $dockerExecServiceSettings['dockerApiExecResizeUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.resizeUrl');
    $dockerExecServiceSettings['dockerApiExecInspectUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.exec.inspectUrl');

    if (empty($dockerExecServiceSettings['dockerApiExecCreateUrlRoute'])) {
      throw new MissingDataException('Docker exec create URL setting is not set.');
    }
    if (empty($dockerExecServiceSettings['dockerApiExecStartUrlRoute'])) {
      throw new MissingDataException('Docker exec start URL setting is not set.');
    }
    if (empty($dockerExecServiceSettings['dockerApiExecResizeUrlRoute'])) {
      throw new MissingDataException('Docker exec resize URL setting is not set.');
    }
    if (empty($dockerExecServiceSettings['dockerApiExecInspectUrlRoute'])) {
      throw new MissingDataException('Docker exec inspect URL setting is not set.');
    }

    foreach ($dockerExecServiceSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $dockerExecServiceSettings;
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
    $portainerServiceSettings['portainerAuthenticationToken'] = $this->settings->get('portainer.portainerOptions.authenticationToken');
    $portainerServiceSettings['portainerEndpointId'] = $this->settings->get('portainer.portainerOptions.endpointId');
    $portainerServiceSettings['portainerEndpointsBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.baseUrl');
    $portainerServiceSettings['portainerEndpointsHealthCheckUrl'] = $this->settings->get('portainer.routes.endpoints.healthCheck.url');
    $portainerServiceSettings['dockerApiBaseUrlRoute'] = $this->settings->get('portainer.routes.endpoints.dockerApi.baseUrl');

    if (empty($portainerServiceSettings['portainerHostRoute'])) {
      throw new MissingDataException('Portainer host setting is not set.');
    }
    if (empty($portainerServiceSettings['portainerAuthenticationToken'])) {
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

    if (empty($portainerServiceSettings['dockerApiBaseUrlRoute'])) {
      throw new MissingDataException('Docker API base URL setting is not set.');
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
    $portainerStacksSettings['baseUrl'] = $this->settings->get('portainer.routes.stacks.baseUrl');
    $portainerStacksSettings['createUrl'] = $this->settings->get('portainer.routes.stacks.crud.createUrl');
    $portainerStacksSettings['readOneUrl'] = $this->settings->get('portainer.routes.stacks.crud.readOneUrl');
    $portainerStacksSettings['readAllUrl'] = $this->settings->get('portainer.routes.stacks.crud.readAllUrl');
    $portainerStacksSettings['updateUrl'] = $this->settings->get('portainer.routes.stacks.crud.updateUrl');
    $portainerStacksSettings['deleteUrl'] = $this->settings->get('portainer.routes.stacks.crud.deleteUrl');

    if (empty($portainerStacksSettings['baseUrl'])) {
      throw new MissingDataException('Portainer stacks base URL setting is not set.');
    }
    if (empty($portainerStacksSettings['createUrl'])) {
      throw new MissingDataException('Portainer stacks create URL setting is not set.');
    }
    if (empty($portainerStacksSettings['readOneUrl'])) {
      throw new MissingDataException('Portainer stacks read one URL setting is not set.');
    }
    if (empty($portainerStacksSettings['readAllUrl'])) {
      throw new MissingDataException('Portainer stacks read all URL setting is not set.');
    }
    if (empty($portainerStacksSettings['updateUrl'])) {
      throw new MissingDataException('Portainer stacks update URL setting is not set.');
    }
    if (empty($portainerStacksSettings['deleteUrl'])) {
      throw new MissingDataException('Portainer stacks delete URL setting is not set.');
    }

    foreach ($portainerStacksSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $portainerStacksSettings;
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
    $triplestoreRepositoriesSettings['baseUrl'] = $this->settings->get('triplestore.routes.repositories.baseUrl');
    $triplestoreRepositoriesSettings['createUrl'] = $this->settings->get('triplestore.routes.repositories.crud.createUrl');
    $triplestoreRepositoriesSettings['readOneUrl'] = $this->settings->get('triplestore.routes.repositories.crud.readOneUrl');
    $triplestoreRepositoriesSettings['readAllUrl'] = $this->settings->get('triplestore.routes.repositories.crud.readAllUrl');
    $triplestoreRepositoriesSettings['updateUrl'] = $this->settings->get('triplestore.routes.repositories.crud.updateUrl');
    $triplestoreRepositoriesSettings['deleteUrl'] = $this->settings->get('triplestore.routes.repositories.crud.deleteUrl');
    $triplestoreRepositoriesSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.repositories.healthCheck.url');

    if (empty($triplestoreRepositoriesSettings['baseUrl'])) {
      throw new MissingDataException('Triplestore base URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['createUrl'])) {
      throw new MissingDataException('Triplestore create URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['readOneUrl'])) {
      throw new MissingDataException('Triplestore read one URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['readAllUrl'])) {
      throw new MissingDataException('Triplestore read all URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['updateUrl'])) {
      throw new MissingDataException('Triplestore update URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['deleteUrl'])) {
      throw new MissingDataException('Triplestore delete URL setting is not set.');
    }
    if (empty($triplestoreRepositoriesSettings['healthCheckUrl'])) {
      throw new MissingDataException('Triplestore health check URL setting is not set.');
    }

    foreach ($triplestoreRepositoriesSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreRepositoriesSettings;
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
    $triplestoreServiceSettings['triplestoreAdminUsername'] = $this->settings->get('triplestore.generalSettings.adminUsername');
    $triplestoreServiceSettings['triplestoreAdminPassword'] = $this->settings->get('triplestore.generalSettings.adminPassword');
    $triplestoreServiceSettings['healthCheckUrl'] = $this->settings->get('triplestore.routes.misc.healthCheck.url');
    if (empty($triplestoreServiceSettings['triplestoreHostRoute'])) {
      throw new MissingDataException('Triplestore host URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestorePort'])) {
      throw new MissingDataException('Triplestore port URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestoreAdminUsername'])) {
      throw new MissingDataException('Triplestore admin username URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['triplestoreAdminPassword'])) {
      throw new MissingDataException('Triplestore admin password URL setting is not set.');
    }
    if (empty($triplestoreServiceSettings['healthCheckUrl'])) {
      throw new MissingDataException('Triplestore health check URL setting is not set.');
    }

    foreach ($triplestoreServiceSettings as &$value) {
      $value = str_replace('{empty}', '', $value);
    }

    return $triplestoreServiceSettings;
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

}
