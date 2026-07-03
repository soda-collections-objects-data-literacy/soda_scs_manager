<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\Core\Url;
use Drupal\Core\Utility\Error;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\ValueObject\SodaScsSnapshotData;
use GuzzleHttp\ClientInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsWisskiComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * WissKI 3.x snapshot tar layout: top-level sites/ and private-files/ dirs.
   */
  private const WISSKI_SNAPSHOT_LAYOUT_3X = '3.x-two-volume';

  /**
   * WissKI 2.x snapshot tar layout: full /opt/drupal tree (drupal-root volume).
   */
  private const WISSKI_SNAPSHOT_LAYOUT_2X = '2.x-drupal-root';

  /**
   * The bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $bundleInfo;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

  /**
   * The SCS Docker exec service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsExecRequestInterface
   */
  protected SodaScsExecRequestInterface $sodaScsDockerExecServiceActions;

  /**
   * The SCS Docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface
   */
  protected SodaScsRunRequestInterface $sodaScsDockerRunServiceActions;

  /**
   * The config config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $config;

  /**
   * The SCS component helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The SCS container helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

  /**
   * The SCS helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpers
   */
  protected SodaScsHelpers $sodaScsHelpers;

  /**
   * The SCS snapshot helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS Keycloak helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers
   */
  protected SodaScsKeycloakHelpers $sodaScsKeycloakHelpers;

  /**
   * The SCS Nextcloud helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers
   */
  protected SodaScsNextcloudHelpers $sodaScsNextcloudHelpers;

  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions;


  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions;

  /**
   * The SCS Keycloak actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions;

  /**
   * The SCS Portainer helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsPortainerHelpers
   */
  protected SodaScsPortainerHelpers $sodaScsPortainerHelpers;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsPortainerServiceActions
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

  /**
   * The SCS Project helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The SCS Service helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsSqlServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * Class constructor.
   */
  public function __construct(
    EntityTypeBundleInfoInterface $bundleInfo,
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    FileSystemInterface $fileSystem,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.helpers')]
    SodaScsHelpers $sodaScsHelpers,
    #[Autowire(service: 'soda_scs_manager.docker_exec_service.actions')]
    SodaScsExecRequestInterface $sodaScsDockerExecServiceActions,
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    SodaScsKeycloakHelpers $sodaScsKeycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    SodaScsNextcloudHelpers $sodaScsNextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.client.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.group.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    #[Autowire(service: 'soda_scs_manager.portainer.helpers')]
    SodaScsPortainerHelpers $sodaScsPortainerHelpers,
    #[Autowire(service: 'soda_scs_manager.portainer_service.actions')]
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    #[Autowire(service: 'soda_scs_manager.sql_service.actions')]
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    #[Autowire(service: 'soda_scs_manager.stack.helpers')]
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->bundleInfo = $bundleInfo;
    $this->config = $configFactory->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->fileSystem = $fileSystem;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsHelpers = $sodaScsHelpers;
    $this->sodaScsDockerExecServiceActions = $sodaScsDockerExecServiceActions;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsKeycloakHelpers = $sodaScsKeycloakHelpers;
    $this->sodaScsNextcloudHelpers = $sodaScsNextcloudHelpers;
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsPortainerHelpers = $sodaScsPortainerHelpers;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Docker volume for WissKI site state (/opt/drupal/web/sites).
   *
   * @param string $machineName
   *   Component machine name (same as Portainer SERVICE_NAME / stack name).
   *
   * @return string
   *   Named volume (e.g. wisski-foo--drupal-sites).
   */
  private function wisskiDrupalSitesVolumeName(string $machineName): string {
    return $machineName . '--drupal-sites';
  }

  /**
   * Docker volume for WissKI private files (/opt/drupal/private-files).
   *
   * @param string $machineName
   *   Component machine name (same as Portainer SERVICE_NAME / stack name).
   *
   * @return string
   *   Named volume (e.g. wisski-foo--drupal-private-files).
   */
  private function wisskiDrupalPrivateFilesVolumeName(string $machineName): string {
    return $machineName . '--drupal-private-files';
  }

  /**
   * Persistent WissKI Drupal volumes snapshotted in 3.x (sites + private files).
   *
   * @param string $machineName
   *   Component machine name.
   *
   * @return array{sites: string, privateFiles: string}
   *   Volume names keyed by role.
   */
  private function wisskiDrupalPersistentVolumeNames(string $machineName): array {
    return [
      'sites' => $this->wisskiDrupalSitesVolumeName($machineName),
      'privateFiles' => $this->wisskiDrupalPrivateFilesVolumeName($machineName),
    ];
  }

  /**
   * Shell command to archive both WissKI persistent volumes into one tar.
   *
   * Tar members are top-level sites/ and private-files/ (3.x layout).
   */
  private function buildWisskiSnapshotArchiveCommand(string $tarFileName, string $sha256FileName): string {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID . ':' . SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_GID;
    return 'mkdir -p /staging/sites /staging/private-files && ' .
      'cp -a /source-sites/. /staging/sites/ && ' .
      'cp -a /source-private/. /staging/private-files/ && ' .
      'tar czf /backup/' . $tarFileName . ' -C /staging sites private-files && ' .
      'rm -rf /staging && ' .
      'cd /backup && sha256sum ' . $tarFileName . ' > ' . $sha256FileName . ' && ' .
      'chown ' . $owner . ' /backup/' . $tarFileName . ' /backup/' . $sha256FileName;
  }

  /**
   * Shell command to purge both volumes and restore from a snapshot tar.
   *
   * Supports 3.x (sites/, private-files/) and 2.x (web/sites/, private-files/).
   */
  private function buildWisskiRestoreFromTarCommand(string $tarFileName): string {
    $owner = SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_UID . ':' . SodaScsSnapshotHelpers::SNAPSHOT_FILE_OWNER_GID;
    return 'STAGING=/staging && ' .
      'mkdir -p "$STAGING" && ' .
      'rm -rf /volume-sites/* /volume-sites/.[!.]* /volume-sites/..?* && ' .
      'rm -rf /volume-private/* /volume-private/.[!.]* /volume-private/..?* && ' .
      'tar -xzf /restore/' . $tarFileName . ' -C "$STAGING" && ' .
      'if [ -d "$STAGING/sites" ]; then ' .
      'cp -a "$STAGING/sites/." /volume-sites/ && cp -a "$STAGING/private-files/." /volume-private/; ' .
      'elif [ -d "$STAGING/web/sites" ]; then ' .
      'cp -a "$STAGING/web/sites/." /volume-sites/ && ' .
      '[ -d "$STAGING/private-files" ] && cp -a "$STAGING/private-files/." /volume-private/ || true; ' .
      'else echo "Unrecognized WissKI snapshot layout" >&2; exit 1; fi && ' .
      'rm -rf "$STAGING" && chown -R ' . $owner . ' /volume-sites /volume-private';
  }

  /**
   * Shell command to archive both volumes for rollback (same layout as snapshot).
   */
  private function buildWisskiRollbackArchiveCommand(string $rollbackTarFileName): string {
    return 'mkdir -p /staging/sites /staging/private-files && ' .
      'cp -a /source-sites/. /staging/sites/ && ' .
      'cp -a /source-private/. /staging/private-files/ && ' .
      'tar czf /backup/' . $rollbackTarFileName . ' -C /staging sites private-files && ' .
      'rm -rf /staging';
  }

  /**
   * Resolves Portainer/env version pins for a WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   * @param string|null $targetVersion
   *   Optional update target: latest, nightly, or a version label.
   *
   * @return array{
   *   mode: string,
   *   varnishImageVersion: string,
   *   wisskiComposeStackVersion: string,
   *   wisskiDefaultDataModelRecipeVersion: string,
   *   wisskiBaseImageVersion: string,
   *   wisskiStarterRecipeVersion: string,
   *   wisskiVersion: string,
   * }
   *   Version settings passed to Portainer on stack create.
   */
  private function resolveWisskiVersionSettings(SodaScsComponentInterface $component, ?string $targetVersion = NULL): array {
    $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();

    if ($targetVersion === 'nightly') {
      return [
        'mode' => 'development',
        'varnishImageVersion' => $wisskiInstanceSettings['varnishImageDevelopmentVersion'],
        'wisskiComposeStackVersion' => $wisskiInstanceSettings['stackDevelopmentVersion'],
        'wisskiDefaultDataModelRecipeVersion' => $wisskiInstanceSettings['defaultDataModelRecipeDevelopmentVersion'],
        'wisskiBaseImageVersion' => $wisskiInstanceSettings['imageDevelopmentVersion'],
        'wisskiStarterRecipeVersion' => $wisskiInstanceSettings['starterRecipeDevelopmentVersion'],
        'wisskiVersion' => '',
      ];
    }

    if ($targetVersion === NULL && $component->get('developmentInstance')->value) {
      return [
        'mode' => 'development',
        'varnishImageVersion' => $wisskiInstanceSettings['varnishImageDevelopmentVersion'],
        'wisskiComposeStackVersion' => $wisskiInstanceSettings['stackDevelopmentVersion'],
        'wisskiDefaultDataModelRecipeVersion' => $wisskiInstanceSettings['defaultDataModelRecipeDevelopmentVersion'],
        'wisskiBaseImageVersion' => $wisskiInstanceSettings['imageDevelopmentVersion'],
        'wisskiStarterRecipeVersion' => $wisskiInstanceSettings['starterRecipeDevelopmentVersion'],
        'wisskiVersion' => '',
      ];
    }

    $lookupVersion = $targetVersion;
    if ($lookupVersion === NULL || $lookupVersion === 'latest') {
      $lookupVersion = $wisskiInstanceSettings['defaultVersion'] ?? '';
    }

    if ($lookupVersion !== '') {
      $versionStorage = $this->entityTypeManager->getStorage('soda_scs_wisski_component_ver');
      $versionEntities = $versionStorage->loadMultiple();
      foreach ($versionEntities as $versionEntity) {
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsWisskiComponentVersionInterface $versionEntity */
        if ($versionEntity->getVersion() === $lookupVersion) {
          return [
            'mode' => '',
            'varnishImageVersion' => '',
            'wisskiComposeStackVersion' => $versionEntity->getWisskiStack(),
            'wisskiDefaultDataModelRecipeVersion' => $versionEntity->getWisskiDefaultDataModelRecipe(),
            'wisskiBaseImageVersion' => $versionEntity->getWisskiImage(),
            'wisskiStarterRecipeVersion' => $versionEntity->getWisskiStarterRecipe(),
            'wisskiVersion' => $versionEntity->getPackageEnvironment(),
          ];
        }
      }
    }

    $componentVersion = $lookupVersion !== ''
      ? $lookupVersion
      : ($component->get('version')->value ?? '');
    $packageEnv = $wisskiInstanceSettings['packageEnvironmentProductionVersion'] ?? '';
    return [
      'mode' => '',
      'varnishImageVersion' => '',
      'wisskiComposeStackVersion' => $wisskiInstanceSettings['wisskiStackProductionVersion'] ?? '',
      'wisskiDefaultDataModelRecipeVersion' => $wisskiInstanceSettings['defaultDataModelRecipeProductionVersion'] ?? '',
      'wisskiBaseImageVersion' => $wisskiInstanceSettings['wisskiBaseImageProductionVersion'] ?? '',
      'wisskiStarterRecipeVersion' => $wisskiInstanceSettings['starterRecipeProductionVersion'] ?? '',
      'wisskiVersion' => $componentVersion !== '' && $componentVersion !== NULL
        ? (string) $componentVersion
        : (string) $packageEnv,
    ];
  }

  /**
   * Maps version settings to Portainer stack environment variables.
   *
   * @param array $versionSettings
   *   Output of resolveWisskiVersionSettings().
   *
   * @return array<string, string>
   *   Env name/value pairs to merge into the stack env.
   */
  private function mapVersionSettingsToEnvVars(array $versionSettings): array {
    $mapped = [
      'MODE' => (string) ($versionSettings['mode'] ?? ''),
      'VARNISH_IMAGE_VERSION' => (string) ($versionSettings['varnishImageVersion'] ?? ''),
      'WISSKI_BASE_IMAGE_VERSION' => (string) ($versionSettings['wisskiBaseImageVersion'] ?? ''),
      'WISSKI_DEFAULT_DATA_MODEL_VERSION' => (string) ($versionSettings['wisskiDefaultDataModelRecipeVersion'] ?? ''),
      'WISSKI_STARTER_VERSION' => (string) ($versionSettings['wisskiStarterRecipeVersion'] ?? ''),
    ];

    return array_filter($mapped, static fn(string $value): bool => $value !== '');
  }

  /**
   * Merges updated env values into an existing Portainer stack env array.
   *
   * @param array $currentEnv
   *   Existing stack env from Portainer.
   * @param array<string, string> $updates
   *   Env name/value pairs to apply.
   *
   * @return array<int, array{name: string, value: string}>
   *   Updated env array for Portainer requests.
   */
  private function mergeStackEnvVars(array $currentEnv, array $updates): array {
    $envByName = [];
    foreach ($currentEnv as $entry) {
      if (!is_array($entry)) {
        continue;
      }
      $name = (string) ($entry['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $envByName[$name] = (string) ($entry['value'] ?? '');
    }

    foreach ($updates as $name => $value) {
      $envByName[$name] = $value;
    }

    $merged = [];
    foreach ($envByName as $name => $value) {
      $merged[] = [
        'name' => $name,
        'value' => $value,
      ];
    }

    return $merged;
  }

  /**
   * Refreshes the Drupal container ID after a stack redeploy.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result with refreshed container metadata.
   */
  private function refreshComponentContainerId(SodaScsComponentInterface $component): SodaScsResult {
    return $this->sodaScsContainerHelpers->syncComponentContainerId($component);
  }

  /**
   * Redeploys a WissKI stack to pick up baked-in Drupal/WissKI packages.
   *
   * Packages are now shipped in the wisski-base-image, so updates pull the
   * configured image tag and recreate the stack containers via Portainer.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   * @param string|null $targetVersion
   *   Update target: latest, nightly, or a version label.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result of the redeploy operation.
   */
  public function redeployStackWithVersion(SodaScsComponentInterface $component, ?string $targetVersion = 'latest'): SodaScsResult {
    $stackId = (string) ($component->get('externalId')->value ?? '');
    if ($stackId === '') {
      return SodaScsResult::failure(
        error: 'Portainer stack ID not found on component.',
        message: (string) $this->t('Cannot update packages: Portainer stack ID is missing.'),
      );
    }

    try {
      $portainerGetRequest = $this->sodaScsPortainerServiceActions->buildGetRequest([
        'routeParams' => [
          'stackId' => $stackId,
        ],
      ]);
      $portainerGetResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerGetRequest);
      if (!$portainerGetResponse['success']) {
        return SodaScsResult::failure(
          error: $portainerGetResponse['error'] ?? 'Failed to load Portainer stack.',
          message: (string) $this->t('Failed to load WissKI stack from Portainer.'),
        );
      }

      $stackPayload = json_decode(
        $portainerGetResponse['data']['portainerResponse']->getBody()->getContents(),
        TRUE,
      );
      if (!is_array($stackPayload)) {
        return SodaScsResult::failure(
          error: 'Invalid Portainer stack response.',
          message: (string) $this->t('Failed to load WissKI stack from Portainer.'),
        );
      }

      $versionSettings = $this->resolveWisskiVersionSettings($component, $targetVersion);
      $updatedEnv = $this->mergeStackEnvVars(
        $stackPayload['Env'] ?? [],
        $this->mapVersionSettingsToEnvVars($versionSettings),
      );

      $portainerRedeployRequest = $this->sodaScsPortainerServiceActions->buildGitRedeployRequest([
        'env' => $updatedEnv,
        'repullImageAndRedeploy' => TRUE,
        'routeParams' => [
          'stackId' => $stackId,
        ],
        'wisskiComposeStackVersion' => $versionSettings['wisskiComposeStackVersion'] ?? '',
      ]);
      $portainerRedeployResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerRedeployRequest);
      if (!$portainerRedeployResponse['success']) {
        return SodaScsResult::failure(
          error: $portainerRedeployResponse['error'] ?? 'Portainer redeploy failed.',
          message: (string) $this->t('Failed to redeploy WissKI stack.'),
        );
      }

      $containerName = (string) ($component->get('containerName')->value ?? '');
      if ($containerName === '') {
        $containerName = (string) $component->get('machineName')->value . '--drupal';
      }

      $waitForRunningResponse = $this->waitForContainerByName($containerName);
      if (!$waitForRunningResponse->success) {
        return $waitForRunningResponse;
      }

      $refreshContainerResponse = $this->refreshComponentContainerId($component);
      if (!$refreshContainerResponse->success) {
        return $refreshContainerResponse;
      }

      return SodaScsResult::success(
        message: (string) $this->t('WissKI stack redeployed successfully.'),
        data: [
          'portainerRedeploy' => $portainerRedeployResponse['data'] ?? [],
          'versionSettings' => $versionSettings,
          'container' => $refreshContainerResponse->data,
        ],
      );
    }
    catch (\Throwable $e) {
      return SodaScsResult::failure(
        error: 'Failed to redeploy WissKI stack: ' . $e->getMessage(),
        message: (string) $this->t('Failed to redeploy WissKI stack.'),
      );
    }
  }

  /**
   * WissKI release metadata stored on snapshots and copied into manifest.json.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   * @param array $versionSettings
   *   Output of resolveWisskiVersionSettings().
   *
   * @return array<string, string>
   *   Non-empty version fields for snapshot metadata / manifest mapping.
   */
  private function buildWisskiSnapshotVersionMetadata(SodaScsComponentInterface $component, array $versionSettings): array {
    $metadata = [
      'wisskiVolumeLayout' => self::WISSKI_SNAPSHOT_LAYOUT_3X,
      'wisskiBaseImageVersion' => (string) ($versionSettings['wisskiBaseImageVersion'] ?? ''),
      'wisskiComposeStackVersion' => (string) ($versionSettings['wisskiComposeStackVersion'] ?? ''),
      'wisskiComponentVersion' => (string) ($component->get('version')->value ?? ''),
      'wisskiVersion' => (string) ($versionSettings['wisskiVersion'] ?? ''),
    ];

    if ($component->get('developmentInstance')->value) {
      $metadata['wisskiDevelopmentInstance'] = '1';
    }

    return array_filter($metadata, static fn(string $value): bool => $value !== '');
  }

  /**
   * Sets the component version label when missing (development instances).
   *
   * Production stacks/components set version at entity creation; development
   * instances use wisski.instances.versions.development.componentVersion.
   */
  private function applyDefaultWisskiComponentVersion(SodaScsComponentInterface $component): void {
    if (!($component->get('version')->value ?? '')) {
      if (!$component->get('developmentInstance')->value) {
        return;
      }
      $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();
      $componentVersion = trim((string) ($wisskiInstanceSettings['componentDevelopmentVersion'] ?? ''));
      if ($componentVersion === '') {
        $componentVersion = trim((string) ($wisskiInstanceSettings['stackDevelopmentVersion'] ?? ''));
      }
      if ($componentVersion !== '') {
        $component->set('version', $componentVersion);
      }
    }
  }

  /**
   * Create WissKI component.
   *
   * This function handles the creation of a WissKI component by:
   * - Retrieving information about the connected SQL and
   *   triplestore components.
   * - Creating/retrieving a service key for authentication
   *   in dependend services.
   * - Create new openid connect client in keycloak and
   *   admin and user groups for the instance.
   * - Add the user to the wisski instance admin group.
   * - Creating a new WissKI instance at portainer.
   * - Create the Wisski component entity.
   * - Linking the component to its parent stack if necessary.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS component entity.
   *
   * @return array
   *   The created WissKI component configuration.
   */
  public function createComponent(SodaScsComponentInterface $component): array {
    try {

      // Get some basic information about the WissKI component.
      // Get the bundle info for the WissKI component.
      $bundleInfos = $this->bundleInfo->getBundleInfo('soda_scs_component');
      $wisskiComponentBundleInfo = $bundleInfos['soda_scs_wisski_component'] ?? NULL;
      if (!$wisskiComponentBundleInfo) {
        throw new \Exception('WissKI component bundle info not found');
      }
      // Set imageUrl and description for the WissKI component.
      $component->set('imageUrl', $wisskiComponentBundleInfo['imageUrl']);
      $component->set('description', $wisskiComponentBundleInfo['description']);

      // Get and set the machine name for the WissKI component.
      $machineName = 'wisski-' . $component->get('machineName')->value;
      $component->set('machineName', $machineName);

      $this->applyDefaultWisskiComponentVersion($component);

      // Collect the project colleques and user groups.
      // The owner of the component and all members of linked project
      // should have access to the WissKI instance. So we collect all
      // the project colleques for role assignment and the userGroups
      // for filesystem permissions groups.
      // Get the owner of the component.
      $owner = $component->getOwner();
      $projectColleques[] = $owner;
      $allProjectMembers = [];
      $userGroups = [];

      // Get the members of the linked projects.
      /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectsItemList */
      $linkedProjectsItemList = $component->get('partOfProjects');
      $linkedProjects = $linkedProjectsItemList->referencedEntities();
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $linkedProject */
      foreach ($linkedProjects as $linkedProject) {
        /** @var \Drupal\Core\Field\EntityReferenceFieldItemList $linkedProjectMembersItemList */
        $linkedProjectMembersItemList = $linkedProject->get('members');
        $linkedProjectMembers = $linkedProjectMembersItemList->referencedEntities();
        $projectColleques = array_merge($projectColleques, $linkedProjectMembers);
        $allProjectMembers = array_merge($allProjectMembers, $linkedProjectMembers);
        $userGroups[] = $linkedProject->get('groupId')->value;
      }

      // Service key.
      // Create service key for WissKI component if it does not exist.
      $keyProps = [
        'bundle'  => 'soda_scs_wisski_component',
        'bundleLabel' => $wisskiComponentBundleInfo['label'],
        'type'  => 'password',
        'userId'  => $component->getOwnerId(),
        'username' => $component->getOwner()->getDisplayName(),
      ];
      $wisskiComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($keyProps) ?? $this->sodaScsServiceKeyActions->createServiceKey($keyProps);
      $wisskiComponentServiceKeyPassword = $wisskiComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('WissKI service key password not found.');

      // Add the service key to the WissKI component entity.
      $component->serviceKey[] = $wisskiComponentServiceKeyEntity;

      // Get the connected components if any.
      // Get information about the connected SQL and triplestore components.
      $resolvedComponents = $this->sodaScsComponentHelpers->resolveConnectedComponents($component);
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $sqlComponent */
      $sqlComponent = $resolvedComponents['sql'];

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $triplestoreComponent */
      $triplestoreComponent = $resolvedComponents['triplestore'];

      // SQL connection if any.
      if ($sqlComponent) {
        // Set the database name.
        $dbName = $sqlComponent->get('machineName')->value;

        // Get the service key for the SQL component.
        $sqlKeyProps = [
          'bundle'  => 'soda_scs_sql_component',
          'type'  => 'password',
          'userId'  => $sqlComponent->getOwnerId(),
        ];

        // Get the service key for the SQL component.
        $sqlComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($sqlKeyProps) ?? throw new \Exception('SQL service key not found.');
        $sqlComponentServiceKeyPassword = $sqlComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('SQL service key password not found.');

      }

      // Triplestore connection if any.
      if ($triplestoreComponent) {

        $triplestoreComponentMachineName = $triplestoreComponent->get('machineName')->value;

        // Get the password for the triplestore component.
        $triplestoreKeyProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'password',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceKeyEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreKeyProps) ?? throw new \Exception('Triplestore service key not found.');
        $triplestoreComponentServiceKeyPassword = $triplestoreComponentServiceKeyEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service key password not found.');

        // Get the service token for the triplestore component.
        $triplestoreTokenProps = [
          'bundle'  => 'soda_scs_triplestore_component',
          'type'  => 'token',
          'userId'  => $triplestoreComponent->getOwnerId(),
        ];

        $triplestoreComponentServiceTokenEntity = $this->sodaScsServiceKeyActions->getServiceKey($triplestoreTokenProps) ?? throw new \Exception('Triplestore service token not found.');
        $triplestoreComponentServiceTokenString = $triplestoreComponentServiceTokenEntity->get('servicePassword')->value ?? throw new \Exception('Triplestore service token not found.');

      }

      // Get the flavours for the WissKI component.
      // @todo Implement the flavour logic.
      $flavoursList = $component->get('flavours')->value;

      // Initialize the flavours string.
      $flavours = '';

      // Process flavours array into a space-separated string.
      if (is_array($flavoursList)) {
        // Extract values from each flavour entry.
        foreach ($flavoursList as $flavour) {
          if (isset($flavour['value'])) {
            $flavoursArray[] = $flavour['value'];
          }
        }

        // Join flavours with spaces if any were found.
        if (!empty($flavours)) {
          $flavours = implode(' ', $flavoursArray);
        }
      }

      // Create random openid connect client secret.
      $openidConnectClientSecret = $this->sodaScsComponentHelpers->createSecret();

      // Create openid connect client.
      $wisskiInstanceSettings = $this->sodaScsServiceHelpers->initWisskiInstanceSettings();
      $wisskiInstanceUrl = str_replace('{instanceId}', $machineName, $wisskiInstanceSettings['baseUrl']);
      $keycloakBuildCreateClientRequest = $this->sodaScsKeycloakServiceClientActions->buildCreateRequest([
        'clientId' => $machineName,
        'name' => $component->get('label')->value,
        'description' => 'Change me',
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        'rootUrl' => $wisskiInstanceUrl,
        'adminUrl' => $wisskiInstanceUrl,
        'logoutUrl' => $wisskiInstanceUrl . '/logout',
        // @todo Use secret from service key.
        'secret' => $openidConnectClientSecret,
      ]);

      $keycloakMakeCreateClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildCreateClientRequest);
      if (!$keycloakMakeCreateClientResponse['success']) {
        throw new \Exception('Keycloak create client request failed: ' . $keycloakMakeCreateClientResponse['error']);
      }

      // Create keycloak group for admin.
      $keycloakWisskiInstanceAdminGroupName = $machineName . '-admin';

      $keycloakBuildCreateAdminGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $keycloakWisskiInstanceAdminGroupName,
          'path' => '/' . $keycloakWisskiInstanceAdminGroupName,
        ],
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);

      $keycloakMakeCreateAdminGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildCreateAdminGroupRequest);
      if (!$keycloakMakeCreateAdminGroupResponse['success']) {
        throw new \Exception('Keycloak create admin group request failed: ' . $keycloakMakeCreateAdminGroupResponse['error']);
      }

      // Create keycloak group for users.
      $keycloakWisskiInstanceUserGroupName = $machineName . '-user';

      $keycloakBuildCreateUserGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildCreateRequest([
        'body' => [
          'name' => $keycloakWisskiInstanceUserGroupName,
          'path' => '/' . $keycloakWisskiInstanceUserGroupName,
        ],
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);

      $keycloakMakeCreateUserGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildCreateUserGroupRequest);
      if (!$keycloakMakeCreateUserGroupResponse['success']) {
        throw new \Exception('Keycloak create user group request failed: ' . $keycloakMakeCreateUserGroupResponse['error']);
      }

      // Fetch all Keycloak groups to resolve admin and user group IDs.
      $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
      ]);
      $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

      if (!$keycloakMakeGetAllGroupsResponse['success']) {
        throw new \Exception('Keycloak get all groups request failed: ' . $keycloakMakeGetAllGroupsResponse['error']);
      }

      $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);

      $filteredAdminGroups = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceAdminGroupName) {
        return $group['name'] === $keycloakWisskiInstanceAdminGroupName;
      });
      $keycloakWisskiInstanceAdminGroup = reset($filteredAdminGroups);

      $filteredUserGroups = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceUserGroupName) {
        return $group['name'] === $keycloakWisskiInstanceUserGroupName;
      });
      $keycloakWisskiInstanceUserGroup = reset($filteredUserGroups);

      // Owner goes into the -admin group only.
      $ownerSsoUuid = $this->sodaScsProjectHelpers->getUserSsoUuid($component->getOwner());
      if ($ownerSsoUuid) {
        $addOwnerToAdminGroupReq = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
          'type' => 'addUserToGroup',
          'routeParams' => [
            'userId' => $ownerSsoUuid,
            'groupId' => $keycloakWisskiInstanceAdminGroup['id'],
          ],
          'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        ]);
        $addOwnerToAdminGroupRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($addOwnerToAdminGroupReq);
        if (!$addOwnerToAdminGroupRes['success']) {
          throw new \Exception('Keycloak add owner to admin group request failed: ' . $addOwnerToAdminGroupRes['error']);
        }
      }

      // Project members (non-owner) go into the -user group only.
      foreach ($allProjectMembers as $member) {
        $memberSsoUuid = $this->sodaScsProjectHelpers->getUserSsoUuid($member);
        if (!$memberSsoUuid) {
          continue;
        }
        $addMemberToUserGroupReq = $this->sodaScsKeycloakServiceUserActions->buildUpdateRequest([
          'type' => 'addUserToGroup',
          'routeParams' => [
            'userId' => $memberSsoUuid,
            'groupId' => $keycloakWisskiInstanceUserGroup['id'],
          ],
          'token' => $this->sodaScsKeycloakHelpers->getKeycloakToken(),
        ]);
        $addMemberToUserGroupRes = $this->sodaScsKeycloakServiceUserActions->makeRequest($addMemberToUserGroupReq);
        if (!$addMemberToUserGroupRes['success']) {
          throw new \Exception('Keycloak add member to user group request failed: ' . $addMemberToUserGroupRes['error']);
        }
      }

      // Ensure Nextcloud credentials. Bearer: create app password; else use
      // stored credentials from Login Flow v2 (browser popup).
      $owner = $component->getOwner();
      $nextcloudCredentials = NULL;
      if ($owner) {
        if ($this->sodaScsNextcloudHelpers->isBearerEnabled()) {
          try {
            $nextcloudCredentials = $this->sodaScsNextcloudHelpers
              ->createAppPassword($machineName, $owner);
          }
          catch (\Exception $e) {
            $nextcloudCredentials = $this->sodaScsNextcloudHelpers
              ->ensureCredentials($owner, $machineName);
          }
        }
        else {
          $nextcloudCredentials = $this->sodaScsNextcloudHelpers
            ->ensureCredentials($owner, $machineName);
        }
        if (!$nextcloudCredentials) {
          $connectUrl = Url::fromRoute('openid_connect.accounts_controller_index', [
            'user' => $owner->id(),
          ])->toString();
          throw new \Exception(sprintf(
            'Nextcloud account is not connected for this user. Visit %s to connect before creating a WissKI stack.',
            $connectUrl
          ));
        }
      }

      $versionSettings = $this->resolveWisskiVersionSettings($component);

      //
      // Create the WissKI instance at portainer.
      // @todo Replace "wisski" at one point of the domain name.
      //
      $requestParams = [
        'dbName' => $dbName ?? '',
        'defaultLanguage' => $component->get('defaultLanguage')->value,
        'flavours' => $flavours,
        'keycloakAdminGroup' => $keycloakWisskiInstanceAdminGroupName,
        'keycloakUserGroup' => $keycloakWisskiInstanceUserGroupName,
        'machineName' => $machineName,
        'nextcloudAppPassword' => $nextcloudCredentials['appPassword'] ?? '',
        'nextcloudLoginName' => $nextcloudCredentials['username'] ?? '',
        'openidConnectClientSecret' => $openidConnectClientSecret,
        'sqlServicePassword' => $sqlComponentServiceKeyPassword ?? '',
        'triplestoreServicePassword' => $triplestoreComponentServiceKeyPassword ?? '',
        'triplestoreServiceToken' => $triplestoreComponentServiceTokenString ?? '',
        'tsRepository' => $triplestoreComponentMachineName ?? '',
        'userGroups' => $userGroups ? implode(' ', $userGroups) : '',
        'userId' => $component->getOwnerId(),
        'username' => $component->getOwner()->getDisplayName(),
        'wisskiServicePassword' => $wisskiComponentServiceKeyPassword ?? '',
        'wisskiType' => ($sqlComponent && $triplestoreComponent) ? 'bundled' : 'single',
        'proxyAddresses' => $wisskiInstanceSettings['proxyAddresses'] ?? 'auto',
        ...$versionSettings,
      ];
      // Create the WissKI instance at portainer.
      $portainerCreateRequest = $this->sodaScsPortainerServiceActions->buildCreateRequest($requestParams);
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Request failed. See logs for more details."));
      return [
        'message' => 'Request failed.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    try {
      $portainerCreateRequestResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerCreateRequest);
    }
    catch (\Exception $e) {
      $this->logger->error("Portainer request failed: @error", [
        '@error' => $e->getMessage(),
      ]);
      return [
        'message' => 'Portainer request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'portainerCreateRequestResult' => $portainerCreateRequestResult,
        ],
        'statusCode' => $portainerCreateRequestResult['statusCode'],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    if (!$portainerCreateRequestResult['success']) {
      return [
        'message' => 'Portainer request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'portainerCreateRequestResult' => $portainerCreateRequestResult,
        ],
        'statusCode' => $portainerCreateRequestResult['statusCode'],
        'success' => FALSE,
        'error' => $portainerCreateRequestResult['error'],
      ];
    }
    $portainerResponsePayload = json_decode($portainerCreateRequestResult['data']['portainerResponse']->getBody()->getContents(), TRUE);

    // Get the container name and id of the WissKI component container.
    // Construct the request parameters.
    $containerName = $machineName . '--drupal';
    $dockerGetAllContainersRequestParams = [
      'queryParams' => [
        'all' => TRUE,
        'filters' => json_encode(['name' => [$containerName]]),
      ],
    ];

    // Build and make the get all containers request.
    $dockerGetAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest($dockerGetAllContainersRequestParams);
    $dockerGetAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($dockerGetAllContainersRequest);
    if (!$dockerGetAllContainersResponse['success']) {
      return [
        'message' => 'Docker request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'dockerGetAllContainersResponse' => $dockerGetAllContainersResponse,
        ],
      ];
    }

    // Get the response payload.
    $dockerGetAllContainersResponsePayload = json_decode($dockerGetAllContainersResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
    $containerId = $dockerGetAllContainersResponsePayload[0]['Id'];

    // Set the external ID and container name and id.
    $component->set('externalId', $portainerResponsePayload['Id']);
    $component->set('containerId', $containerId);
    $component->set('containerName', $containerName);
    $component->save();
    // Save the component.
    $component->save();

    $wisskiComponentServiceKeyEntity->scsComponent[] = $component->id();
    $wisskiComponentServiceKeyEntity->save();

    return [
      'message' => 'Created WissKI component.',
      'data' => [
        'wisskiComponent' => $component,
        'portainerCreateRequestResult' => $portainerCreateRequestResult,

      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Create SODa SCS Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param int $timestamp
   *   The timestamp of the snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with the created snapshot.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    try {

      //
      // Inspect if the WissKI component
      //
      // Construct the inspect request parameters.
      $inspectWisskicontainerRequestParams = [
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ];
      // Build and send the inspect request.
      $inspectWisskicontainerRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($inspectWisskicontainerRequestParams);
      $inspectWisskicontainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectWisskicontainerRequest);
      if (!$inspectWisskicontainerResponse['success']) {
        return SodaScsResult::failure(
          error: $inspectWisskicontainerResponse['error'],
          message: 'Snapshot creation failed: Could not inspect container.',
        );
      }
      $inspectWisskicontainerResponsePayload = json_decode($inspectWisskicontainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $wisskiContainerState = $inspectWisskicontainerResponsePayload['State'];

      if ($wisskiContainerState['Status'] !== 'running') {

        //
        // Stop the WissKI component container.
        //
        // Construct the request parameters.
        $stopWisskiContainerRequestParams = [
          'routeParams' => [
            'containerId' => $component->get('containerId')->value,
          ],
        ];

        // Build and make the stop container request.
        $stopWisskiContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest($stopWisskiContainerRequestParams);
        $stopWisskiContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopWisskiContainerRequest);
        if (!$stopWisskiContainerResponse['success']) {
          return SodaScsResult::failure(
            error: $stopWisskiContainerResponse['error'],
            message: 'Snapshot creation failed: Could not stop container.',
          );
        }

        // Wait for the container to be stopped.
        $waitForContainerToStopResponse = $this->sodaScsContainerHelpers->waitForContainerState($component->get('containerId')->value, 'exited');
        if (!$waitForContainerToStopResponse->success) {
          return SodaScsResult::failure(
            error: $waitForContainerToStopResponse->error,
            message: 'Snapshot creation failed: Could not wait for container to stop.',
          );
        }
      }

      //
      // Create the snapshot container.
      //
      // Get the snapshot paths.
      $snapshotPaths = $this->sodaScsSnapshotHelpers->constructSnapshotPaths($component, $snapshotMachineName, (string) $timestamp);

      // Create the backup directory.
      $dirCreateResult = $this->sodaScsSnapshotHelpers->createDir($snapshotPaths['backupPathWithType']);
      if (!$dirCreateResult['success']) {
        return SodaScsResult::failure(
          error: $dirCreateResult['error'],
          message: 'Snapshot creation failed: Could not create backup directory.',
        );
      }

      // We need a random int to avoid conflicts with other snapshots.
      $randomInt = $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $snapshotContainerName = 'snapshot--' . $randomInt . '--' . $snapshotMachineName . '--drupal';

      // Convert container path to host path for Portainer bind mounts.
      $hostBackupPath = $this->sodaScsSnapshotHelpers
        ->convertContainerPathToHostPath($snapshotPaths['backupPathWithType']);

      $wisskiVolumes = $this->wisskiDrupalPersistentVolumeNames($component->get('machineName')->value);

      // Construct the snapshot container create request parameters.
      $createSnapshotContainerRunCommandRequestParams = [
        'name' => $snapshotContainerName,
        'volumes' => NULL,
        'image' => 'alpine:latest',
        'user' => SodaScsSnapshotHelpers::SNAPSHOT_VOLUME_ARCHIVE_DOCKER_USER,
        'cmd' => [
          'sh',
          '-c',
          $this->buildWisskiSnapshotArchiveCommand(
            $snapshotPaths['tarFileName'],
            $snapshotPaths['sha256FileName'],
          ),
        ],
        'hostConfig' => [
          'Binds' => [
            $wisskiVolumes['sites'] . ':/source-sites:ro',
            $wisskiVolumes['privateFiles'] . ':/source-private:ro',
            $hostBackupPath . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Build the create request for docker run command and send it.
      $createSnapshotContainerRunCommandRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($createSnapshotContainerRunCommandRequestParams);
      $createSnapshotContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($createSnapshotContainerRunCommandRequest);

      if (!$createSnapshotContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $createSnapshotContainerResponse['error'],
          message: 'Snapshot creation failed: Could not create snapshot container.',
        );
      }

      // Get container ID from response.
      $snapshotContainerId = json_decode($createSnapshotContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      // Build the start request for docker run command and send it.
      $startSnapshotContainerRunCommandRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => [
          'containerId' => $snapshotContainerId,
        ],
      ]);
      $startSnapshotContainerRunCommandResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startSnapshotContainerRunCommandRequest);

      if (!$startSnapshotContainerRunCommandResponse['success']) {
        return SodaScsResult::failure(
          error: $startSnapshotContainerRunCommandResponse['error'],
          message: 'Snapshot creation failed: Could not start snapshot container.',
        );
      }

      $wisskiVersionSettings = $this->resolveWisskiVersionSettings($component);
      $wisskiVersionMetadata = $this->buildWisskiSnapshotVersionMetadata($component, $wisskiVersionSettings);

      // Construct component data for the snapshot.
      $componentData = [
        'componentBundle' => $component->bundle(),
        'componentId' => $component->id(),
        'componentMachineName' => $component->get('machineName')->value,
        'snapshotContainerId' => $snapshotContainerId,
        'snapshotContainerName' => $snapshotContainerName,
        'snapshotContainerStatus' => NULL,
        'snapshotContainerRemoved' => NULL,
        'createSnapshotContainerResponse' => $createSnapshotContainerResponse,
        'metadata' => array_merge([
          'backupPath' => $snapshotPaths['backupPath'],
          'relativeUrlBackupPath' => $snapshotPaths['relativeUrlBackupPath'],
          'contentFilePaths' => [
            'tarFilePath' => $snapshotPaths['absoluteTarFilePath'],
            'sha256FilePath' => $snapshotPaths['absoluteSha256FilePath'],
          ],
          'contentFileNames' => [
            'tarFileName' => $snapshotPaths['tarFileName'],
            'sha256FileName' => $snapshotPaths['sha256FileName'],
          ],
          'snapshotMachineName' => $snapshotMachineName,
          'snapshotDirectory' => $snapshotPaths['snapshotDirectory'],
          'snapshotFilesystemRoot' => $snapshotPaths['snapshotFilesystemRoot'],
          'timestamp' => $timestamp,
        ], $wisskiVersionMetadata),
        'startSnapshotContainerResponse' => $startSnapshotContainerRunCommandResponse,
      ];

      $snapshotContainerData = SodaScsSnapshotData::fromArray($componentData);

      // Since we can snapshot whole stack with multiple components,
      // we need to construct an array with the component bundle as key
      // and the snapshot data.
      $containers = [
        $component->bundle() => $snapshotContainerData,
      ];

      //
      // Wait for the snapshot container to be finished.
      //
      $waitForSnapshotContainerToFinishResponse = $this->sodaScsContainerHelpers->waitContainersToFinish(
        $containers,
        FALSE,
        'snapshot creation');
      if (!$waitForSnapshotContainerToFinishResponse->success) {
        return SodaScsResult::failure(
          error: $waitForSnapshotContainerToFinishResponse->error,
          message: 'Snapshot creation failed: Could not wait for container to finish.',
        );
      }

      //
      // Start the component container again.
      //
      // Construct the request parameters.
      $startWisskiContainerRequestParams = [
        'routeParams' => [
          'containerId' => $component->get('containerId')->value,
        ],
      ];

      // Build the start request for docker run command and send it.
      $startWisskiContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest($startWisskiContainerRequestParams);
      $startWisskiContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startWisskiContainerRequest);

      if (!$startWisskiContainerResponse['success']) {
        return SodaScsResult::failure(
          error: $startWisskiContainerResponse['error'],
          message: 'Snapshot creation failed: Could not start container.',
        );
      }

      // Set the start WissKI container response.
      $snapshotContainerData->startWisskiContainerResponse = $startWisskiContainerResponse;

      // Return the success result.
      return SodaScsResult::success(
        data: [
          $component->bundle() => $snapshotContainerData,
        ],
        message: 'Created and started snapshot container successfully.',
      );
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Snapshot creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: 'Snapshot creation failed.',
      );
    }
  }

  /**
   * Get all WissKI Components.
   *
   * @return array
   *   The result array with the WissKI components.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Retrieves a SODa SCS WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to retrieve.
   *
   * @return array
   *   The result array of the created component.
   */
  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Waits until a container with the given name is running.
   *
   * @param string $containerName
   *   The Docker container name.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success when a running container is found.
   */
  private function waitForContainerByName(string $containerName): SodaScsResult {
    $maxAttempts = $this->sodaScsHelpers->adjustMaxAttempts(5);
    if ($maxAttempts === FALSE) {
      return SodaScsResult::failure(
        error: 'PHP request timeout error',
        message: (string) $this->t('Timed out waiting for WissKI container to start.'),
      );
    }

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
      $matched = $this->sodaScsContainerHelpers->findLiveContainerByName($containerName);
      if ($matched->success && (string) ($matched->data['state'] ?? '') === 'running') {
        return SodaScsResult::success(
          message: (string) $this->t('WissKI container is running.'),
          data: [
            'containerId' => (string) ($matched->data['containerId'] ?? ''),
            'containerName' => $containerName,
          ],
        );
      }

      sleep(5);
    }

    return SodaScsResult::failure(
      error: 'Container did not become running in time.',
      message: (string) $this->t('WissKI stack redeployed, but the Drupal container did not become healthy in time.'),
    );
  }

  /**
   * Updates a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to update.
   *
   * @return array
   *   The result array of the created component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    $redeployResult = $this->redeployStackWithVersion($component, 'latest');
    return [
      'message' => $redeployResult->message,
      'data' => [
        'redeployResult' => $redeployResult->data,
      ],
      'success' => $redeployResult->success,
      'error' => $redeployResult->error,
      'statusCode' => $redeployResult->success ? 200 : 500,
    ];
  }

  /**
   * Deletes a SODa SCS Component component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component component to delete.
   *
   * @return array
   *   The result array of the created component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    // Construct the request parameters.
    // @todo We should use the correct wording of the params (path, query, body, etc.).
    $requestParams = [
      'routeParams' => [
        'stackId' => $component->get('externalId')->value,
      ],
    ];
    try {
      // Build the get request for the portainer service.
      // to get the stack informations and send it.
      //
      $portainerGetRequest = $this->sodaScsPortainerServiceActions->buildGetRequest($requestParams);
      $portainerGetResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerGetRequest);
      if (!$portainerGetResponse['success']) {
        return [
          'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
          'data' => [
            'portainerResponse' => $portainerGetResponse,
            'keycloakClientResponse' => NULL,
            'keycloakAdminGroupResponse' => NULL,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => $portainerGetResponse['error'],
          'statusCode' => $portainerGetResponse['statusCode'],
        ];
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot get WissKI component at portainer: @message',
        ['@component' => $component->getLabel(), '@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot get WissKI component @component at portainer. See logs for more details.", ['@component' => $component->getLabel()]));
      return [
        'message' => $this->t('Cannot get WissKI component @component at portainer.', ['@component' => $component->getLabel()]),
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      // Build the delete request with the informations from the portainer
      // service.
      $portainerDeleteRequest = $this->sodaScsPortainerServiceActions->buildDeleteRequest($requestParams);
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot assemble WissKI delete request: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot assemble WissKI component delete request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
    try {
      // Send the delete request to the portainer service.
      /** @var array $portainerResponse */
      $portainerDeleteResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerDeleteRequest);
      if (!$portainerDeleteResponse['success']) {
        $errorMessage = $portainerDeleteResponse['error'] ?? 'Unknown error occurred';
        Error::logException(
          $this->logger,
          new \Exception($errorMessage),
          'Could not delete WissKI stack at portainer: @message',
          ['@message' => $errorMessage],
          LogLevel::ERROR
        );
        $this->messenger->addError($this->t("Could not delete WissKI stack at portainer, but will delete the component anyway. See logs for more details."));
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot get WissKI component at portainer: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
    }

    // Delete connected docker volumes of the WissKI component.
    try {
      $removeVolumesOfComposeStackResponse = $this->sodaScsPortainerHelpers->removeVolumesOfComposeStack($component->get('machineName')->value);
      if (!$removeVolumesOfComposeStackResponse->success) {
        Error::logException(
          $this->logger,
          new \Exception($removeVolumesOfComposeStackResponse->error),
          'Cannot delete WissKI component at keycloak: @message',
          ['@message' => $removeVolumesOfComposeStackResponse->error],
          LogLevel::ERROR
        );
      }
    }
    catch (\Exception $e) {
      Error::logException(
      $this->logger,
      $e,
      'Cannot delete WissKI component at keycloak: @message',
      ['@message' => $e->getMessage()],
      LogLevel::ERROR
      );
    }

    try {
      // Delete the client in keycloak.
      // @todo export this to own helper function.
      //
      // Request keycloak token.
      $keycloakBuildTokenRequest = $this->sodaScsKeycloakServiceClientActions->buildTokenRequest([]);
      $keycloakMakeTokenRequest = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildTokenRequest);
      if (!$keycloakMakeTokenRequest['success']) {
        throw new \Exception('Keycloak token request failed.');
      }
      $keycloakTokenResponseContents = json_decode($keycloakMakeTokenRequest['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $keycloakToken = $keycloakTokenResponseContents['access_token'];

      // Get client uuid.
      $getAllClientsRequestParams = [
        'queryParams' => [
          'clientId' => $component->get('machineName')->value,
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildGetAllClientRequest = $this->sodaScsKeycloakServiceClientActions->buildGetAllRequest($getAllClientsRequestParams);
      $keycloakMakeGetAllClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildGetAllClientRequest);
      if (!$keycloakMakeGetAllClientResponse['success']) {
        return [
          'message' => 'Cannot get WissKI component client uuid at keycloak.',
          'data' => [
            'portainerResponse' => $portainerDeleteResponse,
            'keycloakClientResponse' => $keycloakMakeGetAllClientResponse,
          ],
        ];
      }
      $keycloakGetAllClientResponseContents = json_decode($keycloakMakeGetAllClientResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
      $clientUuid = $keycloakGetAllClientResponseContents[0]['id'];

      // Delete the client in keycloak.
      $deleteRequestParams = [
        'routeParams' => [
          'clientUuid' => $clientUuid,
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteClientRequest = $this->sodaScsKeycloakServiceClientActions->buildDeleteRequest($deleteRequestParams);
      $keycloakMakeDeleteClientResponse = $this->sodaScsKeycloakServiceClientActions->makeRequest($keycloakBuildDeleteClientRequest);

      if (!$keycloakMakeDeleteClientResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component at keycloak.',
          'data' => [
            'portainerResponse' => $portainerDeleteResponse,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => NULL,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteClientResponse['statusCode'],
        ];
      }

      $keycloakWisskiInstanceAdminGroupName = $component->get('machineName')->value . '-admin';
      $keycloakWisskiInstanceUserGroupName = $component->get('machineName')->value . '-user';
      // Get all groups from Keycloak for group ids.
      $keycloakBuildGetAllGroupsRequest = $this->sodaScsKeycloakServiceGroupActions->buildGetAllRequest([
        'token' => $keycloakToken,
      ]);
      $keycloakMakeGetAllGroupsResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildGetAllGroupsRequest);

      if ($keycloakMakeGetAllGroupsResponse['success']) {
        $keycloakGroups = json_decode($keycloakMakeGetAllGroupsResponse['data']['keycloakResponse']->getBody()->getContents(), TRUE);
        // Get the admin group id of the WissKI instance.
        // Get admin group details.
        $keycloakWisskiInstanceAdminGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceAdminGroupName) {
          return $group['name'] === $keycloakWisskiInstanceAdminGroupName;
        });
        $keycloakWisskiInstanceAdminGroup = reset($keycloakWisskiInstanceAdminGroup);

        // Get user group details.
        $keycloakWisskiInstanceUserGroup = array_filter($keycloakGroups, function ($group) use ($keycloakWisskiInstanceUserGroupName) {
          return $group['name'] === $keycloakWisskiInstanceUserGroupName;
        });
        $keycloakWisskiInstanceUserGroup = reset($keycloakWisskiInstanceUserGroup);
      }

      // Delete the admin group in keycloak.
      $deleteAdminGroupRequestParams = [
        'routeParams' => [
          'groupId' => $keycloakWisskiInstanceAdminGroup['id'],
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteAdminGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest($deleteAdminGroupRequestParams);
      $keycloakMakeDeleteAdminGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildDeleteAdminGroupRequest);

      if (!$keycloakMakeDeleteAdminGroupResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component admin group at keycloak.',
          'data' => [
            'portainerResponse' => $portainerDeleteResponse,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
            'keycloakUserGroupResponse' => NULL,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteAdminGroupResponse['statusCode'],
        ];
      }

      // Delete the user group in keycloak.
      $deleteUserGroupRequestParams = [
        'routeParams' => [
          'groupId' => $keycloakWisskiInstanceUserGroup['id'],
        ],
        'token' => $keycloakToken,
      ];
      $keycloakBuildDeleteUserGroupRequest = $this->sodaScsKeycloakServiceGroupActions->buildDeleteRequest($deleteUserGroupRequestParams);
      $keycloakMakeDeleteUserGroupResponse = $this->sodaScsKeycloakServiceGroupActions->makeRequest($keycloakBuildDeleteUserGroupRequest);

      if (!$keycloakMakeDeleteUserGroupResponse['success']) {
        return [
          'message' => 'Cannot delete WissKI component user group at keycloak.',
          'data' => [
            'portainerResponse' => $portainerDeleteResponse,
            'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
            'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
            'keycloakUserGroupResponse' => $keycloakMakeDeleteUserGroupResponse,
          ],
          'success' => FALSE,
          'error' => NULL,
          'statusCode' => $keycloakMakeDeleteUserGroupResponse['statusCode'],
        ];
      }

      // Delete the component.
      $component->delete();

      return [
        'message' => 'Deleted WissKI component.',
        'data' => [
          'portainerResponse' => $portainerDeleteResponse,
          'keycloakClientResponse' => $keycloakMakeDeleteClientResponse,
          'keycloakAdminGroupResponse' => $keycloakMakeDeleteAdminGroupResponse,
          'keycloakUserGroupResponse' => $keycloakMakeDeleteUserGroupResponse,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => $portainerDeleteResponse['statusCode'],
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->logger,
        $e,
        'Cannot delete WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot delete WissKI component. See logs for more details."));
      return [
        'message' => 'Cannot delete WissKI component.',
        'data' => [
          'portainerResponse' => NULL,
          'keycloakClientResponse' => NULL,
          'keycloakAdminGroupResponse' => NULL,
          'keycloakUserGroupResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => $e->getCode(),
      ];
    }
  }

  /**
   * Restore Component from Snapshot.
   *
   * We get the container id of the WissKI component container,
   * because routes do not work with container names.
   * We stop the container gracefully. Wait for 30 seconds.
   * We back up current volume to rollback tar.
   * We restore into fresh state: purge volume and extract snapshot tar.
   * We start the original container again.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   * @param string|null $tempDirPath
   *   The path to the temporary directory.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   *
   * @todo Are rollback really working?
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDirPath): SodaScsResult {
    try {
      //
      // Collect information about the snapshot's WissKI component.
      //
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $component */
      $component = $snapshot->get('snapshotOfComponent')->entity ?? NULL;
      if (!$component) {
        return SodaScsResult::failure(
          message: 'Snapshot is not linked to a component.',
          error: 'Missing component on snapshot.',
        );
      }

      $machineName = $component->get('machineName')->value;
      $containerName = $machineName . '--drupal';

      // Get the container id of the WissKI component container,
      // because routes do not work with container names.
      $getAllContainersRequestParams = [
        'queryParams' => [
          'all' => TRUE,
          'filters' => json_encode(['name' => [$containerName]]),
        ],
      ];
      $getAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest($getAllContainersRequestParams);
      $getAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($getAllContainersRequest);
      if (!$getAllContainersResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to get container id.',
          error: (string) $getAllContainersResponse['error'],
        );
      }
      $getAllContainersResponseContents = json_decode($getAllContainersResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $containerId = $getAllContainersResponseContents[0]['Id'];

      //
      // Check if the container is alreay stopped.
      // @todo Add a check if the container is already stopped.
      $inspectContainerRequestParams = [
        'routeParams' => [
          'containerId' => $containerId,
        ],
      ];
      $inspectContainerRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($inspectContainerRequestParams);
      $inspectContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($inspectContainerRequest);
      if (!$inspectContainerResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to inspect container.',
          error: (string) $inspectContainerResponse['error'],
        );
      }
      $inspectContainerResponseContents = json_decode($inspectContainerResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
      $containerState = $inspectContainerResponseContents['State'];
      if ($containerState['Running'] == TRUE) {
        //
        // Stop the WissKI component container gracefully.
        //
        // Wait for 20 seconds before forcing stop container.
        $stopContainerRequestParams = [
          'routeParams' => [
            'containerId' => $containerId,
          ],
          'timeout' => 20,
        ];
        // Build and make the stop container request.
        $stopContainerRequest = $this->sodaScsDockerRunServiceActions->buildStopRequest($stopContainerRequestParams);
        $stopContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($stopContainerRequest);

        if (!$stopContainerResponse['success']) {
          return SodaScsResult::failure(
            message: 'Failed to stop container.',
            error: (string) $stopContainerResponse['error'],
          );
        }

        $waitForContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($containerId, 'exited');
        if (!$waitForContainerStateResponse->success) {
          return SodaScsResult::failure(
            message: 'Failed to wait for container to stop.',
            error: (string) $waitForContainerStateResponse->error,
          );
        }
      }

      //
      // Back up current volumes to rollback tar,
      // then restore from snapshot into both volumes.
      //
      $wisskiVolumes = $this->wisskiDrupalPersistentVolumeNames($machineName);
      /** @var \Drupal\file\Entity\File|null $snapshotFile */
      $snapshotFile = $snapshot->getFile();
      if (!$snapshotFile) {
        return SodaScsResult::failure(
          message: 'Snapshot file not found on entity.',
          error: 'Missing snapshot file.',
        );
      }
      $snapshotUri = $snapshotFile->getFileUri();
      $snapshotPath = $this->fileSystem->realpath($snapshotUri);
      if (!$snapshotPath || !file_exists($snapshotPath)) {
        return SodaScsResult::failure(
          message: 'Snapshot file does not exist on filesystem.',
          error: 'Snapshot file missing at path.',
        );
      }
      $snapshotDir = dirname($snapshotPath);
      $snapshotTarFileName = basename($snapshotPath);
      $rollbackTarName = 'rollback--' . $machineName . '--drupal-volumes--' . date('Ymd-His') . '.tar.gz';
      $rollbackTarPath = $snapshotDir . '/' . $rollbackTarName;

      $rollbackContainerName = 'rollback--' . $machineName . '--drupal__' . $this->sodaScsSnapshotHelpers->generateRandomSuffix();

      // Create a short-lived container to back up the current volumes.
      $rollbackContainerCreateRequestParams = [
        'name' => $rollbackContainerName,
        'image' => 'alpine:latest',
        'user' => SodaScsSnapshotHelpers::SNAPSHOT_VOLUME_ARCHIVE_DOCKER_USER,
        'cmd' => ['sh', '-c', $this->buildWisskiRollbackArchiveCommand(basename($rollbackTarPath))],
        'hostConfig' => [
          'Binds' => [
            $wisskiVolumes['sites'] . ':/source-sites:ro',
            $wisskiVolumes['privateFiles'] . ':/source-private:ro',
            $snapshotDir . ':/backup',
          ],
          'AutoRemove' => FALSE,
        ],
      ];
      // Build and make the create container request.
      $rollbackContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($rollbackContainerCreateRequestParams);
      $rollbackContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerCreateRequest);

      // Check if the create container request was successful.
      if (!$rollbackContainerCreateResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to create rollback backup container.',
          error: (string) $rollbackContainerCreateResponse['error'],
        );
      }

      // Build and make the start container request.
      $rollbackContainerId = json_decode($rollbackContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];
      $rollbackContainerStartRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $rollbackContainerId],
      ]);
      $rollbackContainerStartResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerStartRequest);
      if (!$rollbackContainerStartResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to start rollback backup container.',
          error: (string) $rollbackContainerStartResponse['error'],
        );
      }

      // Wait for the rollback container to finish.
      $waitForRollbackContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($rollbackContainerId, 'exited');
      if (!$waitForRollbackContainerStateResponse->success) {
        return SodaScsResult::failure(
          message: 'Failed to wait for rollback backup container to finish.',
          error: (string) $waitForRollbackContainerStateResponse->error,
        );
      }

      // Directory containing the snapshot tar (pseudo-snapshot copy or bag unpack).
      $restoreMountPath = dirname($snapshotPath);

      //
      // Restore into fresh state: purge both volumes and extract snapshot tar.
      //
      $restoreCmd = [
        'sh',
        '-c',
        $this->buildWisskiRestoreFromTarCommand($snapshotTarFileName),
      ];

      $restoreContainerName = 'restore--' . $machineName . '--drupal__' . $this->sodaScsSnapshotHelpers->generateRandomSuffix();
      $restoreContainerCreateRequestParams = [
        'name' => $restoreContainerName,
        'image' => 'alpine:latest',
        'user' => SodaScsSnapshotHelpers::SNAPSHOT_VOLUME_ARCHIVE_DOCKER_USER,
        'cmd' => $restoreCmd,
        'hostConfig' => [
          'Binds' => [
            $wisskiVolumes['sites'] . ':/volume-sites',
            $wisskiVolumes['privateFiles'] . ':/volume-private',
            $restoreMountPath . ':/restore:ro',
          ],
          'AutoRemove' => FALSE,
        ],
      ];

      // Build and make the create restore container request.
      $restoreContainerCreateRequest = $this->sodaScsDockerRunServiceActions->buildCreateRequest($restoreContainerCreateRequestParams);
      $restoreContainerCreateResponse = $this->sodaScsDockerRunServiceActions->makeRequest($restoreContainerCreateRequest);
      if (!$restoreContainerCreateResponse['success']) {
        // Delete the rollback container if the
        // restore container creation failed.
        $rollbackContainerDeleteRequestParams = [
          'routeParams' => ['containerId' => $rollbackContainerId],
        ];
        $rollbackContainerDeleteRequest = $this->sodaScsDockerRunServiceActions->buildRemoveRequest($rollbackContainerDeleteRequestParams);

        $rollbackContainerDeleteResponse = $this->sodaScsDockerRunServiceActions->makeRequest($rollbackContainerDeleteRequest);
        if (!$rollbackContainerDeleteResponse['success']) {
          return SodaScsResult::failure(
            message: 'Failed to delete rollback container.',
            error: (string) $rollbackContainerDeleteResponse['error'],
          );
        }
        return SodaScsResult::failure(
          message: 'Failed to create restore container.',
          error: (string) $restoreContainerCreateResponse['error'],
        );
      }
      // Build and make the start container request.
      $restoreContainerId = json_decode($restoreContainerCreateResponse['data']['portainerResponse']->getBody()->getContents(), TRUE)['Id'];

      $restoreContainerStartRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $restoreContainerId],
      ]);
      $restoreContainerStartResponse = $this->sodaScsDockerRunServiceActions->makeRequest($restoreContainerStartRequest);
      if (!$restoreContainerStartResponse['success']) {
        return SodaScsResult::failure(
          message: 'Failed to start restore container.',
          error: (string) $restoreContainerStartResponse['error'],
        );
      }

      // Wait for the restore container to finish.
      $waitForRestoreContainerStateResponse = $this->sodaScsContainerHelpers->waitForContainerState($restoreContainerId, 'exited');
      if (!$waitForRestoreContainerStateResponse->success) {
        return SodaScsResult::failure(
          message: 'Failed to wait for restore container to finish.',
          error: (string) $waitForRestoreContainerStateResponse->error,
        );
      }

      //
      // Start the original container again.
      //
      // Build and make the start container request.
      $startContainerRequest = $this->sodaScsDockerRunServiceActions->buildStartRequest([
        'routeParams' => ['containerId' => $containerId],
      ]);
      $startContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($startContainerRequest);
      if (!$startContainerResponse['success']) {
        return SodaScsResult::failure(
          message: 'Restore completed, but failed to start the container.',
          error: (string) $startContainerResponse['error'],
        );
      }

      // Log the restore success.
      // @todo Fix this.
      $this->logger->info('WissKI component @name (@componentMachineName) restored from snapshot @snapshotName successfully.', [
        'name' => $component->label(),
        'componentMachineName' => $component->get('machineName')->value,
       // 'snapshotMachineName' => $snapshot->get('machineName')->value,
        'snapshotName' => $snapshot->label(),
      ]);

      return SodaScsResult::success(
        message: 'WissKI component restored from snapshot successfully.',
        data: [
          'containerId' => $containerId,
          'volumeNames' => $wisskiVolumes,
          'rollbackTarPath' => $rollbackTarPath,
          'snapshotPath' => $snapshotPath,
        ],
      );

    }
    catch (\Throwable $e) {
      Error::logException(
        $this->logger,
        $e,
        'WissKI restore failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      return SodaScsResult::failure(
        message: 'Failed to restore component from snapshot.',
        error: $e->getMessage(),
      );
    }
  }

}
