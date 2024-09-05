<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsWisskiStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;

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
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected MailManagerInterface $mailManager;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected RequestStack $requestStack;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS component helpers service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsMysqlServiceActions;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;


  /**
   * The SCS service key actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWisskiComponentActions;

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The Twig renderer.
   *
   * @var \Drupal\Core\Template\TwigEnvironment
   */
  protected TwigEnvironment $twig;

  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    SodaScsServiceActionsInterface $sodaScsMysqlServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions,
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsMysqlServiceActions = $sodaScsMysqlServiceActions;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsPortainerServiceActions = $sodaScsPortainerServiceActions;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a WissKI stack.
   *
   * A WissKI stack consists of a WissKI, SQL and Triplestore component.
   * We try to create one after another.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array {
    try {
      $sqlComponentCreateResult = $this->sodaScsSqlComponentActions->createComponent($component);

      if (!$sqlComponentCreateResult['success']) {
        return [
          'message' => 'Could not create database component.',
          'data' => [
            'sqlComponentCreateResult' => $sqlComponentCreateResult,
          ],
          'success' => FALSE,
          'error' => $sqlComponentCreateResult['error'],
        ];
      }
      $sqlComponent = $sqlComponentCreateResult['data']['sqlComponent'];

      $component->set('referencedComponents', $sqlComponent->id());
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Database component exists with error: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create database component. See logs for more details."));
    }

    try {
      // $triplestoreComponentCreateResult = $this->sodaScsTriplestoreComponentActions->createComponent($component);
      // $triplestoreComponent = $triplestoreComponentCreateResult['data']['triplestoreComponent'];
      $triplestoreComponentCreateResult = ['success' => TRUE];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Triplestore creation exists with error: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create triplestore. See logs for more details."));
    }

    try {
      $wisskiComponentCreateResult = $this->sodaScsWisskiComponentActions->createComponent($component);
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("WissKI creation exists with error: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create WissKI. See logs for more details."));
    }
    catch (SodaScsRequestException $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("WissKI creation exists with error: @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create WissKI. See logs for more details."));
    }

    try {
      $sqlComponent->set('referencedComponents', $component->id());
      $sqlComponent->save();

      // $triplestoreComponent->set('referencedComponents', $component->id());
      // $triplestoreComponent->save();
      return [
        'message' => 'Successfully created WissKI stack.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponent' => $triplestoreComponentCreateResult,
          'wisskiComponentCreateResult' => $wisskiComponentCreateResult,
        ],
        'success' => TRUE,
        'error' => FALSE,
      ];
    }
    catch (MissingDataException $e) {
    }
  }

  /**
   * Read all WissKI stacks.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result array.
   */
  public function getStacks($bundle, $options): array {
    return [];
  }

  /**
   * Read a WissKI stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function getStack($component): array {
    try {
      // Build request.
      // @todo set request params
      $requestParams = [];
      $portainerGetStacksRequest = $this->sodaScsPortainerServiceActions->buildGetRequest($requestParams);
      $portainerResponse = $this->sodaScsPortainerServiceActions->makeRequest($portainerGetStacksRequest);
    }
    catch (SodaScsRequestException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Request response with error: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Request error. See logs for more details."));
      return [
        'message' => 'Request failed with exception: ' . $e->getMessage(),
        'data' => [
          'portainerResponse' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    return [
      'message' => 'Got all stacks',
      'data' => [
        'portainerResponse' => $portainerResponse,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

  /**
   * Update a WissKI stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function updateStack($component): array {
    return [];
  }

  /**
   * Delete a WissKI stack.
   *
   * WissKI Stack contains WissKI instance, SQL database and triplestore.
   * We try to delete all of one after another.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *
   * @return array
   *   The result.
   */
  public function deleteStack(SodaScsComponentInterface $component): array {
    try {
      // Try to delete Drupal database.
      // Get referenced SQL component.
      $sqlComponent = $this->sodaScsComponentHelpers->retrieveReferencedComponent($component, 'sql');
      // Delete SQL component.
      $deleteDatabaseResult = $this->sodaScsSqlComponentActions->deleteComponent($sqlComponent);
    }
    catch (MissingDataException $e) {
      // If settings are not set, we cannot delete the database.
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot delete database. @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot delete database. See logs for more details."));
      return [
        'message' => 'Cannot delete database.',
        'data' => [
          'deleteDatabaseResult' => NULL,
          'deleteWisskiResult' => NULL,
          'deleteTriplestoreResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    catch (SodaScsComponentException $e) {
      // If component does not exist, we cannot delete the database.
      $this->loggerFactory->get('soda_scs_manager')->error("Cannot delete database. @error", [
        '@error' => $e->getMessage(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot delete database. See logs for more details."));
    }
    try {
      // Try to delete WissKI instance.
      $deleteWisskiResult = $this->sodaScsWisskiComponentActions->deleteComponent($component);
    }
    catch (MissingDataException $e) {
      // If settings are not set, we cannot delete the WissKI instance.
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot assemble Request: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot assemble request. See logs for more details."));
      return [
        'message' => 'WissKI database was deleted, but cannot assemble request to delete WissKI instance.',
        'data' => [
          'deleteDatabaseResult' => $deleteDatabaseResult,
          'deleteWisskiResult' => NULL,
          'deleteTriplestoreResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    catch (SodaScsRequestException $e) {
      // If request fails, we cannot delete the WissKI instance.
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Request response with error: @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Request error. See logs for more details."));
      return [
        'message' => 'WissKI database was deleted, but request failed to delete WissKI instance.',
        'data' => [
          'deleteDatabaseResult' => $deleteDatabaseResult,
          'deleteWisskiResult' => NULL,
          'deleteTriplestoreResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }

    // Everything went fine.
    return [
      'message' => 'WissKI stack deleted',
      'data' => [
        'deleteDatabaseResult' => $deleteDatabaseResult,
        'deleteWisskiResult' => $deleteWisskiResult,
        'deleteTriplestoreResult' => NULL,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

}
