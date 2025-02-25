<?php

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use GuzzleHttp\ClientInterface;

/**
 * Class for SODa SCS Component filesystem actions.
 */
class SodaScsFilesystemComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The database.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * The entity type bundle info.
   *
   * @var \Drupal\Core\Entity\EntityTypeBundleInfoInterface
   */
  protected EntityTypeBundleInfoInterface $entityTypeBundleInfo;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

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
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface
   */
  protected SodaScsHelpersInterface $sodaScsStackHelpers;

  /**
   * The SCS Docker Volumes actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsDockerVolumesServiceActions;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;

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
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsServiceRequestInterface $sodaScsDockerVolumesServiceActions,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    SodaScsServiceActionsInterface $sodaScsSqlServiceActions,
    SodaScsHelpersInterface $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.

    $this->database = $database;
    $this->entityTypeBundleInfo = $entityTypeBundleInfo;
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->sodaScsDockerVolumesServiceActions = $sodaScsDockerVolumesServiceActions;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsSqlServiceActions = $sodaScsSqlServiceActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface|\Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   Result information with the created component.
   */
  public function createComponent(SodaScsStackInterface|SodaScsComponentInterface $entity): array {
    try {
      $filesystemComponentBundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')['soda_scs_filesystem_component'];

      if (!$filesystemComponentBundleInfo) {
        throw new \Exception('Filesystem component bundle info not found');
      }
      $machineName = $entity->get('machineName')->value;

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $filesystemComponent */
      $filesystemComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create(
        [
          'bundle' => 'soda_scs_filesystem_component',
          'label' => $entity->get('label')->value . ' (Filesystem)',
          'machineName' => $machineName,
          'owner'  => $entity->getOwner(),
          'description' => $filesystemComponentBundleInfo['description'],
          'imageUrl' => $filesystemComponentBundleInfo['imageUrl'],
          'health' => 'Unknown',
        ]
      );

      // @todo implement project management.
      $requestParams = [
        'label' => $filesystemComponent->get('label')->value,
        'machineName' => $filesystemComponent->get('machineName')->value,
        'project' => 'my_project',
      ];
      // Create Docker volume.
      $dockerVolumeCreateRequest = $this->sodaScsDockerVolumesServiceActions->buildCreateRequest($requestParams);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot assemble Request: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $dockerVolumeCreateRequestResult = $this->sodaScsDockerVolumesServiceActions->makeRequest($dockerVolumeCreateRequest);

    if (!$dockerVolumeCreateRequestResult['success']) {
      return [
        'message' => 'Docker volume request failed.',
        'data' => [
          'wisskiComponent' => NULL,
          'dockerVolumeCreateRequestResult' => $dockerVolumeCreateRequestResult,
        ],
        'success' => FALSE,
        'error' => $dockerVolumeCreateRequestResult['error'],
      ];
    }

    // Save the component.
    $filesystemComponent->save();

    return [
      'message' => 'Created Filesystem component.',
      'data' => [
        'filesystemComponent' => $filesystemComponent,
        'dockerVolumeCreateRequestResult' => $dockerVolumeCreateRequestResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Get all SODa SCS Component.
   *
   * @return array
   *   Result information with all component.
   */
  public function getComponents(): array {
    return [];
  }

  /**
   * Get SODa SCS Component.
   *
   * @param array $props
   *   The properties of the component you are looking for.
   *
   * @return array
   *   Result information with component.
   */
  public function getComponent(SodaScsComponentInterface $props): array {
    return [];
  }

  /**
   * Update SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with updated component.
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  /**
   * Delete SODa SCS Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   *
   * @return array
   *   Result information with deleted component.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    $queryParams = [
      'machineName' => $component->get('machineName')->value,
    ];
    $bundleLabel = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_component')[$component->bundle()]['label'];
    try {
      $portainerHealthCheckRequest = $this->sodaScsPortainerServiceActions->buildHealthCheckRequest($queryParams);
      $portainerHealthCheckResult = $this->sodaScsPortainerServiceActions->makeRequest($portainerHealthCheckRequest);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot check portainer health: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t("Cannot check portainer health. See logs for more details.", []));
      return [
        'message' => 'Cannot check portainer health.',
        'data' => [
          'dockerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    if ($portainerHealthCheckResult['success'] === FALSE) {
      $this->messenger->addError($this->t("Portainer is not healthy. Cannot delete anything.", []));
      return [
        'message' => 'Portainer is not healthy.',
        'data' => [
          'portainerResponse' => $portainerHealthCheckResult['data'],
        ],
        'success' => FALSE,
        'error' => $portainerHealthCheckResult['error'],
      ];
    }


    try {
      $dockerVolumeDeleteRequest = $this->sodaScsDockerVolumesServiceActions->buildDeleteRequest($queryParams);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot assemble @bundle delete request: @error", [
        '@bundle' => $bundleLabel,
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->t("Cannot assemble @bundle component delete request. See logs for more details.", [
        '@bundle' => $bundleLabel,
      ]));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'dockerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    try {
      $requestResult = $this->sodaScsDockerVolumesServiceActions->makeRequest($dockerVolumeDeleteRequest);
      if (!$requestResult['success']) {
        $this->loggerFactory->get('soda_scs_manager')->error($this->t("Could not delete @bundle. error: @error", [
          '@bundle' => $component->bundle(),
          '@error' => $requestResult['error'],
        ]));
        $this->messenger->addError($this->t("Could not delete  @bundle, but will delete the component anyway. See logs for more details.", [
          '@bundle' => $bundleLabel,
        ]));

      }

      $component->delete();
      return [
        'message' => $this->t('Deleted @bundle.', [
          '@bundle' => $bundleLabel,
        ]),
        'data' => [
          'dockerResponse' => $requestResult,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error($this->t("Could not delete @bundle: @error", [
        '@bundle' => $bundleLabel,
        '@error' => $e->getMessage(),
      ]));
      $this->messenger->addError($this->t("Could not delete @bundle. See logs for more details.", [
        '@bundle' => $bundleLabel,
      ]));
      

      return [
        'message' => $this->t('Could not delete @bundle.', [
          '@bundle' => $bundleLabel,
        ]),
        'data' => [
          'dockerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
  }
  
}
