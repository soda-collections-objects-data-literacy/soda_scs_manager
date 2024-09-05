<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\SodaScsStackActionsInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsSqlStackActions implements SodaScsStackActionsInterface
{

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
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a SQL stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component
   *  
   * @return array
   * 
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array
  {
    try {
      // Create the SQL component.
      $sqlComponentCreateResult = $this->sodaScsSqlComponentActions->createComponent($component);

      if (!$sqlComponentCreateResult['success']) {
        return [
          'message' => 'Could not create database component.',
          'data' => [
            'sqlComponentCreateResult' => $sqlComponentCreateResult,
          ],
          'success' => FALSE,
          'error' =>  $sqlComponentCreateResult['error'],
        ];
      }
      return [
        'message' => 'Could not create database component.',
        'data' => [
          'sqlComponentCreateResult' => $sqlComponentCreateResult,
        ],
        'success' => FALSE,
        'error' =>  $sqlComponentCreateResult['error'],
      ];

    } catch (\Exception $e) {
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
        'error' =>  $e,
      ];
    }
  }

  /**
   * Read all SQL stacks.
   * 
   * @param $bundle
   * @param $options
   * 
   * @return array
   */
  public function getStacks($bundle, $options): array
  {
    return [];
  }

  /**
   * Read a SQL stack.
   * 
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function getStack($component): array
  {
    return [];
  }

  /**
   * Update stack.
   * 
   * @param $component
   */
  public function updateStack($component): array
  {
    return [];
  }

  /**
   * Delete a SQL stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * 
   * @return array
   */
  public function deleteStack(SodaScsComponentInterface $component): array
  {
    try {
      // Delete Drupal database.
      $deleteDbResult = $this->sodaScsSqlComponentActions->deleteComponent($component);
    } catch (MissingDataException $e) {
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
