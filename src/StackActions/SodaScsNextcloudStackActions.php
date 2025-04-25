<?php

namespace Drupal\soda_scs_manager\StackActions;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;
use Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsNextcloudStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

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
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsHelpersInterface
   */
  protected SodaScsHelpersInterface $sodaScsStackHelpers;

  /**
   * Class constructor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsHelpersInterface $sodaScsStackHelpers,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
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
      $stack->save();
      return [
        'message' => 'Could not create database component.',
        'data' => [
          'sqlComponentCreateResult' => NULL,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Database component creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create database component. See logs for more details."));
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
      $stack->delete();
      return [
        'message' => 'Component deleted',
        'data' => [
          'deleteStackResult' => NULL,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Database component deletion failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not delete database component. See logs for more details."));
      return [
        'message' => 'Could not delete database component.',
        'data' => [
          'deleteStackResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

}
