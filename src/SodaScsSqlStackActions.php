<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsSqlStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;

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
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS database actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

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
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a SQL stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result array.
   *   'message' => string
   *   'data' => [
   *     'sqlComponentCreateResult' => array
   *   ]
   *   'success' => TRUE|FALSE
   *   'error' => \Exception
   *
   * @throws \Exception
   */
  public function createStack(SodaScsStackInterface $stack): array {
    try {
      // Create the SQL component.
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
      $stack->save();
      return [
        'message' => 'Could not create database component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
        ],
        'success' => FALSE,
        'error' => $sqlComponentCreateResult['error'],
      ];

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Database component creation exists with error: @error trace: @trace", [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create database component. See logs for more details."));
      return [
        'message' => 'Could not create database component.',
        'data' => [
          'sqlComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

  /**
   * Read all SQL stacks.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result array.
   *   'message' => string
   *   'data' => array
   *   'success' => TRUE|FALSE
   *   'error' => \Exception
   */
  public function getStacks($bundle, $options): array {
    return [];
  }

  /**
   * Read a SQL stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result array.
   *   'message' => string
   *   'data' => array
   *   'success' => TRUE|FALSE
   *   'error' => \Exception
   */
  public function getStack($stack): array {
    return [];
  }

  /**
   * Update stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result array.
   *   'message' => string
   *   'data' => array
   *   'success' => TRUE|FALSE
   *   'error' => \Exception
   */
  public function updateStack($stack): array {
    return [];
  }

  /**
   * Delete a SQL stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result array.
   *   'message' => string
   *   'data' => array
   *   'success' => TRUE|FALSE
   *   'error' => \Exception
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function deleteStack(SodaScsStackInterface $stack): array {
    try {
      // Delete Drupal database.
      $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($stack, 'sql');
      $deleteDbResult = $this->sodaScsSqlComponentActions->deleteComponent($sqlComponent);
    }
    catch (MissingDataException $e) {
      $this->loggerFactory->get('soda_scs_manager')
        ->error("Cannot delete database. @error", [
          '@error' => $e->getMessage(),
        ]);
      $this->messenger->addError($this->stringTranslation->translate("Cannot delete database. See logs for more details."));
      return [
        'message' => 'Cannot delete database.',
        'data' => [
          'deleteDbResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e->getMessage(),
      ];
    }
    $stack->delete();
    return [
      'message' => 'Component deleted',
      'data' => [
        'deleteDbResult' => $deleteDbResult,
      ],
      'success' => TRUE,
      'error' => NULL,
    ];
  }

}
