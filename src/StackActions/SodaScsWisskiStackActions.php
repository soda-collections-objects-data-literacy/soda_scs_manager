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
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;
use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ServiceActions\SodaScsServiceActionsInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;
use Drupal\file\Entity\File;
use Drupal\Core\File\FileSystemInterface;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected FileSystemInterface $fileSystem;

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
   * The SCS snapshot helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

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
    FileSystemInterface $fileSystem,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsStackHelpers $sodaScsStackHelpers,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
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
    $this->fileSystem = $fileSystem;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->settings = $settings;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
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

    // We need to parse the stack into components by creating a dummy entity
    // with basic properties (label, machineName, owner, partOfProjects)
    // and then calling the createComponent method for each component.
    // We start with the SQL component.
    $basicComponentProperties = [
      'label' => $stack->get('label')->value,
      'machineName' => $stack->get('machineName')->value,
      'owner' => $stack->getOwnerId(),
      'partOfProjects' => $stack->get('partOfProjects'),
      'health' => 'Unknown',
    ];
    try {
      // Create the SQL component.
      $sqlComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        'bundle' => 'soda_scs_sql_component',
        ...$basicComponentProperties,
      ]);
      $sqlComponentCreateResult = $this->sodaScsSqlComponentActions->createComponent($sqlComponent);

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
      // Create the triplestore component.
      $triplestoreComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        'bundle' => 'soda_scs_triplestore_component',
        ...$basicComponentProperties,
      ]);
      $triplestoreComponentCreateResult = $this->sodaScsTriplestoreComponentActions->createComponent($triplestoreComponent);
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
      // Create the WissKI component.
      $wisskiComponent = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        ...$basicComponentProperties,
        'bundle' => 'soda_scs_wisski_component',
        'connectedComponents' => [$sqlComponent->id(), $triplestoreComponent->id()],
        'flavours' => $stack->get('flavours')->value,
      ]);
      $wisskiComponentCreateResult = $this->sodaScsWisskiComponentActions->createComponent($wisskiComponent);
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

    $wisskiComponentSnapshot = $this->sodaScsWisskiComponentActions->createSnapshot($wisskiComponent, $snapshotMachineName, $timestamp);
    $sqlComponentSnapshot = $this->sodaScsSqlComponentActions->createSnapshot($sqlComponent, $snapshotMachineName, $timestamp);
    $triplestoreComponentSnapshot = $this->sodaScsTriplestoreComponentActions->createSnapshot($triplestoreComponent, $snapshotMachineName, $timestamp);

    if (!$wisskiComponentSnapshot->success || !$sqlComponentSnapshot->success || !$triplestoreComponentSnapshot->success) {
      return SodaScsResult::failure(
        error: 'Failed to create WissKI stack snapshot.',
        message: 'Failed to create WissKI stack snapshot.',
      );
    }

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

  /**
   * Restore a WissKI stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    $snapshotFile = $snapshot->getFile();
    if (!$snapshotFile) {
      return SodaScsResult::failure(
        error: 'Snapshot file not found.',
        message: 'Snapshot file not found.',
      );
    }

    // Get the file URI and convert to system path.
    $fileUri = $snapshotFile->getFileUri();
    $filePath = $this->fileSystem->realpath($fileUri);

    if (!$filePath || !file_exists($filePath)) {
      return SodaScsResult::failure(
        error: 'Snapshot file does not exist on the filesystem.',
        message: 'Snapshot file does not exist on the filesystem.',
      );
    }

    // Step 1: Verify checksum of the snapshot tar file.
    $checksumValidation = $this->sodaScsSnapshotHelpers->validateSnapshotChecksum($snapshot, $filePath);
    if (!$checksumValidation['success']) {
      return SodaScsResult::failure(
        error: $checksumValidation['error'],
        message: 'Checksum validation failed.',
      );
    }

    // Step 2: Create safe temporary directory and unpack the snapshot.
    $tempDir = $this->sodaScsSnapshotHelpers->createTemporaryDirectory();
    if (!$tempDir) {
      return SodaScsResult::failure(
        error: 'Failed to create temporary directory.',
        message: 'Failed to create temporary directory.',
      );
    }

    $unpackResult = $this->sodaScsSnapshotHelpers->unpackSnapshotToTempDirectory($filePath, $tempDir);
    if (!$unpackResult['success']) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $unpackResult['error'],
        message: 'Failed to unpack snapshot.',
      );
    }

    // Step 3: Parse and validate manifest.json.
    $manifestPath = $tempDir . '/manifest.json';
    $manifestData = $this->sodaScsSnapshotHelpers->parseAndValidateManifest($manifestPath);
    if (!$manifestData['success']) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $manifestData['error'],
        message: 'Failed to parse or validate manifest.',
      );
    }

    // Step 4: Delegate restoration to components.
    $restoreResult = $this->restoreComponentsFromManifest($manifestData['data'], $tempDir);
    if (!$restoreResult['success']) {
      $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
      return SodaScsResult::failure(
        error: $restoreResult['error'],
        message: 'Failed to restore components from manifest.',
      );
    }

    // Step 5: Handle bag files if present.
    if (isset($manifestData['data']['files']['bagFiles'])) {
      $bagResult = $this->sodaScsSnapshotHelpers->handleBagFilesRestoration($manifestData['data']['files']['bagFiles'], $tempDir);
      if (!$bagResult['success']) {
        $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);
        return SodaScsResult::failure(
          error: $bagResult['error'],
          message: 'Failed to handle bag files restoration.',
        );
      }
    }

    // Step 6: Cleanup temporary directory.
    $this->sodaScsSnapshotHelpers->cleanupTemporaryDirectory($tempDir);

    return SodaScsResult::success(
      message: 'WissKI stack restored from snapshot successfully.',
      data: $restoreResult['data'],
    );
  }

  /**
   * Restore components based on the manifest mapping.
   *
   * @param array $manifestData
   *   The parsed manifest data.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return array
   *   Array with success status and restoration data.
   */
  private function restoreComponentsFromManifest(array $manifestData, string $tempDir): array {
    $restorationResults = [];
    $errors = [];

    // Loop through the component mappings from the manifest.
    foreach ($manifestData['mapping'] as $componentMapping) {
      $bundle = $componentMapping['bundle'];
      $eid = $componentMapping['eid'];
      $machineName = $componentMapping['machineName'];
      $dumpFile = $componentMapping['dumpFile'];
      $checksumFile = $componentMapping['checksumFile'];

      // @todo Remove this once we have a generic restore from snapshot for all components.
      // @todo Implement SQL and Triplestore component restoration.
      if ($bundle !== 'soda_scs_wisski_component') {
        $this->messenger->addError($this->t("Cannot restore components from manifest. Only WissKI components are supported."));
        continue;
      }

      // Validate checksum for component dump file.
      if (isset($checksumFile) && file_exists($checksumFile)) {
        $expectedChecksum = trim(file_get_contents($checksumFile));
        $actualChecksum = hash_file('sha256', $dumpFile);

        if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
          $errors[] = "Checksum validation failed for component {$machineName} ({$bundle}).";
          continue;
        }
      }

      // Load the component entity.
      try {
        $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($eid);
        if (!$component) {
          $errors[] = "Component entity {$eid} not found.";
          continue;
        }

        // Get the appropriate component action service.
        // @todo This is how we should do it in the future
        // for all component and stack actions.
        $componentActions = $this->getComponentActionsForBundle($bundle);
        if (!$componentActions) {
          $errors[] = "No component actions found for bundle {$bundle}.";
          continue;
        }

        // Create a pseudo snapshot entity for the component restoration.
        $pseudoSnapshot = $this->createPseudoSnapshotForComponent($component, $dumpFile, $tempDir);

        // Restore the component.
        $restoreResult = $componentActions->restoreFromSnapshot($pseudoSnapshot, $tempDir);

        if ($restoreResult->success) {
          $restorationResults[$machineName] = $restoreResult;
        }
        else {
          $errors[] = "Failed to restore component {$machineName}: " . $restoreResult->message;
        }
      }
      catch (\Exception $e) {
        $errors[] = "Exception while restoring component {$machineName}: " . $e->getMessage();
      }
    }

    if (!empty($errors)) {
      return [
        'success' => FALSE,
        'error' => 'Component restoration failed: ' . implode('; ', $errors),
        'data' => $restorationResults,
      ];
    }

    return [
      'success' => TRUE,
      'data' => $restorationResults,
    ];
  }

  /**
   * Get the appropriate component actions service for a bundle.
   *
   * @param string $bundle
   *   The component bundle.
   *
   * @return \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|null
   *   The component actions service or NULL if not found.
   */
  private function getComponentActionsForBundle(string $bundle): ?SodaScsComponentActionsInterface {
    switch ($bundle) {
      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions;

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions;

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions;

      default:
        return NULL;
    }
  }

  /**
   * Create a pseudo snapshot entity for component restoration.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component entity.
   * @param string $dumpFile
   *   The dump file path.
   * @param string $tempDir
   *   The temporary directory path.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface
   *   A pseudo snapshot entity.
   */
  private function createPseudoSnapshotForComponent($component, string $dumpFile, string $tempDir): SodaScsSnapshotInterface {
    // Create a temporary file entity for the dump file.
    $fileUri = 'temporary://' . basename($dumpFile);
    copy($dumpFile, $this->fileSystem->realpath($fileUri));

    $file = File::create([
      'uri' => $fileUri,
      'filename' => basename($dumpFile),
      'status' => 1,
    ]);
    $file->save();

    // Create a pseudo snapshot entity.
    $snapshot = $this->entityTypeManager->getStorage('soda_scs_snapshot')->create([
      'label' => 'Pseudo snapshot for restoration',
      'file' => $file->id(),
      'snapshotOfComponent' => $component->id(),
    ]);

    return $snapshot;
  }

}
