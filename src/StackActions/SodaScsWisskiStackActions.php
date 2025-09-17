<?php

namespace Drupal\soda_scs_manager\StackActions;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Template\TwigEnvironment;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsWisskiStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

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
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface
   */
  protected SodaScsServiceActionsInterface $sodaScsMysqlServiceActions;

  /**
   * The SCS Portainer actions service.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsPortainerServiceActions;


  /**
   * The SCS service key actions service.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWisskiComponentActions;

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
    EntityTypeBundleInfoInterface $bundleInfo,
    ConfigFactoryInterface $configFactory,
    Connection $database,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsStackHelpers $sodaScsStackHelpers,
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    SodaScsServiceActionsInterface $sodaScsMysqlServiceActions,
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    SodaScsServiceRequestInterface $sodaScsPortainerServiceActions,
    SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions,
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->bundleInfo = $bundleInfo;
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->database = $database;
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result of the request.
   *
   * @throws \Exception
   *
   * @todo Refactor error handling.
   */
  public function createStack(SodaScsStackInterface $stack): array {

    // Get the bundle info for the WissKI stack.
    $bundleinfo = $this->bundleInfo->getBundleInfo('soda_scs_stack')['soda_scs_wisski_stack'];

    if (!$bundleinfo) {
      throw new \Exception('WissKI stack bundle info not found');
    }

    // Set the description and imageUrl for the stack.
    $stack->set('description', $bundleinfo['description']);
    $stack->set('imageUrl', $bundleinfo['imageUrl']);

    try {
      $sqlComponentCreateResult = $this->sodaScsSqlComponentActions->createComponent($stack);

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
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $sqlComponent */
      $sqlComponent = $sqlComponentCreateResult['data']['sqlComponent'];

      // Add the SQL component to the stack.
      $stack->setValue($stack, 'includedComponents', $sqlComponent->id());

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not create database component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create database component. See logs for more details."));
      return [
        'message' => 'Could not create database component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
        ],
        'success' => FALSE,
        'error' => $sqlComponentCreateResult['error'],
      ];
    }

    try {
      $triplestoreComponentCreateResult = $this->sodaScsTriplestoreComponentActions->createComponent($stack);
      if (!$triplestoreComponentCreateResult['success']) {
        return [
          'message' => 'Could not create triplestore component.',
          'data' => [
            'sqlComponentCreateResult' => $sqlComponentCreateResult,
            'triplestoreComponentCreateResult' => NULL,
            'wisskiComponentCreateResult' => NULL,
          ],
          'success' => FALSE,
          'error' => $triplestoreComponentCreateResult['error'],
        ];
      }
      $triplestoreComponent = $triplestoreComponentCreateResult['data']['triplestoreComponent'];
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $triplestoreComponent */
      $stack->setValue($stack, 'includedComponents', $triplestoreComponent->id());

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not create triplestore component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create triplestore. See logs for more details."));
      return [
        'message' => 'Could not create triplestore component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponentCreateResult' => NULL,
          'wisskiComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $triplestoreComponentCreateResult['error'],
      ];
    }

    try {
      $wisskiComponentCreateResult = $this->sodaScsWisskiComponentActions->createComponent($stack);
      if (!$wisskiComponentCreateResult['success']) {
        return [
          'message' => 'Could not create WissKI component.',
          'data' => [
            'sqlComponentCreateResult' => $sqlComponentCreateResult,
            'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
            'wisskiComponentCreateResult' => NULL,
          ],
          'success' => FALSE,
          'error' => $wisskiComponentCreateResult['error'],
        ];
      }
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent */
      $wisskiComponent = $wisskiComponentCreateResult['data']['wisskiComponent'];
      $stack->setValue($stack, 'includedComponents', $wisskiComponent->id());

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not create WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create WissKI. See logs for more details."));
      return [
        'message' => 'Could not create WissKI component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          'wisskiComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $wisskiComponentCreateResult['error'],
      ];
    }
    catch (SodaScsRequestException $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create WissKI. See logs for more details."));
      return [
        'message' => 'Could not create WissKI component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          'wisskiComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $wisskiComponentCreateResult['error'],
      ];
    }

    try {
      // Set the connected components for the SQL component.
      $sqlComponent->set('connectedComponents', $wisskiComponent->id());
      $sqlComponent->save();

      // Set the connected components for the triplestore component.
      $triplestoreComponent->set('connectedComponents', $wisskiComponent->id());
      $triplestoreComponent->save();

      // Set the connected components for the WissKI component.
      $wisskiComponent->set('connectedComponents', [$sqlComponent->id(), $triplestoreComponent->id()]);
      $wisskiComponent->save();

      $stack->set('machineName', 'stack-' . $stack->get('machineName')->value);
      $stack->save();

      return [
        'message' => 'Successfully created WissKI stack.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          'wisskiComponentCreateResult' => $wisskiComponentCreateResult,
        ],
        'success' => TRUE,
        'error' => FALSE,
      ];
    }
    catch (MissingDataException $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Could not create WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create WissKI. See logs for more details."));
      return [
        'message' => 'Could not create WissKI component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          'wisskiComponentCreateResult' => NULL,
        ],
      ];
    }
  }

  /**
   * Create a snapshot of a WissKI stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   * @param string $snapshotMachineName
   *   The snapshot machine name.
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \Drupal\soda_scs_manager\Helpers\SodaScsResult
   *   The result of the request.
   */
  public function createSnapshot(SodaScsStackInterface $stack, string $snapshotMachineName, int $timestamp): SodaScsResult {
    $wisskiComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_wisski_component');
    $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_sql_component');
    $triplestoreComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_triplestore_component');

    $wisskiComponentSnapshot = $this->sodaScsWisskiComponentActions->createSnapshot($wisskiComponent, $wisskiComponent->getLabel(), $timestamp);
    $sqlComponentSnapshot = $this->sodaScsSqlComponentActions->createSnapshot($sqlComponent, $sqlComponent->getLabel(), $timestamp);
    $triplestoreComponentSnapshot = $this->sodaScsTriplestoreComponentActions->createSnapshot($triplestoreComponent, $triplestoreComponent->getLabel(), $timestamp);

    return SodaScsResult::success(
      data: [
        ...$wisskiComponentSnapshot->data,
        ...$sqlComponentSnapshot->data,
        ...$triplestoreComponentSnapshot->data,
      ],
      message: 'Successfully created WissKI stack snapshot.'
    );
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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
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
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Request error. See logs for more details."));
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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack entity.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *
   * @return array
   *   The result.
   */
  public function deleteStack(SodaScsStackInterface $stack): array {
    try {
      // Try to delete Drupal database.
      // Get referenced SQL component.
      $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_sql_component');
      // Delete SQL component.
      if ($sqlComponent) {
        $deleteDatabaseResult = $this->sodaScsSqlComponentActions->deleteComponent($sqlComponent);
        // Remove included component from stack.
        $this->sodaScsStackHelpers->removeIncludedComponentValue($stack, $sqlComponent);
      }
      else {
        $deleteDatabaseResult = NULL;
      }
    }
    catch (MissingDataException $e) {
      // If settings are not set, we cannot delete the database.
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot delete database: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot delete database. See logs for more details."));
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
      $this->messenger->addError($this->t("Cannot delete database. See logs for more details."));
      if ($e->getCode() == 1) {
        // If component does not exist, we cannot delete the database.
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not delete database component: @message',
          ['@message' => $e->getMessage()],
          LogLevel::ERROR
        );
        $this->sodaScsStackHelpers->cleanIncludedComponents($stack);
      }
    }

    try {
      // Try to delete Drupal database.
      // Get referenced SQL component.
      $triplestoreComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_triplestore_component');
      if ($triplestoreComponent) {
        // Delete SQL component.
        $deletetriplestoreResult = $this->sodaScsTriplestoreComponentActions->deleteComponent($triplestoreComponent);
        // Remove the includedComponent from the stack field includedComponents.
        $this->sodaScsStackHelpers->removeIncludedComponentValue($stack, $triplestoreComponent);
      }
      else {
        $deletetriplestoreResult = NULL;
      }

    }
    catch (MissingDataException $e) {
      // If settings are not set, we cannot delete the database.
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot delete triplestore: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot delete triplestore component. See logs for more details."));
      return [
        'message' => 'Cannot delete triplestore.',
        'data' => [
          'deleteDatabaseResult' => $deleteDatabaseResult,
          'deleteWisskiResult' => NULL,
          'deleteTriplestoreResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    catch (SodaScsComponentException $e) {
      $this->messenger->addError($this->t("Cannot delete triplestore component. See logs for more details."));
      if ($e->getCode() == 1) {
        // If component does not exist, we cannot delete the database.
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not delete database component: @message',
          ['@message' => $e->getMessage()],
          LogLevel::ERROR
        );
        $this->sodaScsStackHelpers->cleanIncludedComponents($stack);
      }
    }

    try {
      // Get referenced WissKI component.
      $wisskiComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'soda_scs_wisski_component');
      if ($wisskiComponent) {
        // Delete WissKI component.
        $deleteWisskiResult = $this->sodaScsWisskiComponentActions->deleteComponent($wisskiComponent);
        $this->sodaScsStackHelpers->removeIncludedComponentValue($stack, $wisskiComponent);
        // Remove the includedComponent from the stack field includedComponents.
      }
      else {
        $deleteWisskiResult = NULL;
      }
    }
    catch (MissingDataException $e) {
      // If settings are not set, we cannot delete the WissKI instance.
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Cannot delete WissKI component: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Cannot assemble request. See logs for more details."));

      // Return database and triplestore results.
      return [
        'message' => 'WissKI database was deleted, but cannot assemble request to delete WissKI instance.',
        'data' => [
          'deleteDatabaseResult' => $deleteDatabaseResult,
          'deleteTriplestoreResult' => $deletetriplestoreResult,
          'deleteWisskiResult' => NULL,

        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    catch (SodaScsRequestException $e) {
      // If request fails, we cannot delete the WissKI instance.
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Request failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Request error. See logs for more details."));
      return [
        'message' => 'WissKI database was deleted, but request failed to delete WissKI instance.',
        'data' => [
          'deleteDatabaseResult' => $deleteDatabaseResult,
          'deleteWisskiResult' => $deletetriplestoreResult,
          'deleteTriplestoreResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    catch (SodaScsComponentException $e) {
      $this->messenger->addError($this->t("Cannot delete WissKI component. See logs for more details."));
      if ($e->getCode() == 1) {
        // If component does not exist, we cannot delete the WissKI instance.
        Error::logException(
          $this->loggerFactory->get('soda_scs_manager'),
          $e,
          'Could not delete database component: @message',
          ['@message' => $e->getMessage()],
          LogLevel::ERROR
        );
        $this->sodaScsStackHelpers->cleanIncludedComponents($stack);
      }
    }
    $stack->delete();
    // Everything went fine.
    return [
      'message' => 'WissKI stack deleted',
      'data' => [
        'deleteDatabaseResult' => $deleteDatabaseResult,
        'deleteWisskiResult' => $deleteWisskiResult,
        'deleteTriplestoreResult' => $deletetriplestoreResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

}
