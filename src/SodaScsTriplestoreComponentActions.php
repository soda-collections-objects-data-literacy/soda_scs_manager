<?php

namespace Drupal\soda_scs_manager;


use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\SodaScsServiceKeyActions;
use Entity;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsTriplestoreComponentActions implements SodaScsComponentActionsInterface
{

  use DependencySerializationTrait;

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
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS OpenGDB service actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActions
   */
  protected SodaScsServiceKeyActions $sodaScsServiceKeyActions;

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsServiceRequestInterface $sodaScsOpenGdbServiceActions,
    TranslationInterface $stringTranslation
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsServiceKeyActions = $sodaScsOpenGdbServiceActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create Triplestore Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function createComponent(SodaScsComponentInterface $component): array
  {
    try {
      // Create service key if it does not exist.
      $triplestoreComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey($component) ?? $this->sodaScsServiceKeyActions->createServiceKey($component);

      $component->set('serviceKey', $triplestoreComponentServiceKey);
      $requestParams = [
        'subdomain' => $component->get('subdomain')->value,
        // @todo add support for multiple projects
        'project' => 'my_project',
        'userId' => $component->getOwnerId(),
        'userName' => $component->getOwner()->getDisplayName(),
        'triplestoreServicePassword' => $triplestoreComponentServiceKey->get('password')->value,
      ];
      // Create Drupal instance.
      $openGdbCreateRequest = $this->sodaScsOpenGdbServiceActions->buildCreateRequest($requestParams);
    } catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot assemble Request: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'Cannot assemble Request.',
        'data' => [
          'openGdbResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    $requestResult = $this->sodaScsOpenGdbServiceActions->makeRequest($openGdbCreateRequest);

    if ($requestResult['success'] === FALSE) {
      return $requestResult;
    }
    // Set the external ID.
    $component->set('externalId', $requestResult['data']['openGdbResponse']['Id']);

    // Save the component.
    $component->save();

    $triplestoreComponentServiceKey->set('scsComponent', [$component->id()]);
    $triplestoreComponentServiceKey->save();

    return [
      'message' => 'Created WissKI component.',
      'data' => [
        'openGdbResponse' => $requestResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  public function getComponent(SodaScsComponentInterface $component): array
  {
    return [];
  }

  public function updateComponent(SodaScsComponentInterface $component): array
  {
    return [];
  }

  public function deleteComponent(SodaScsComponentInterface $component): array
  {
    return [];
  }
}
