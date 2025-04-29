<?php

namespace Drupal\soda_scs_manager\StackActions;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the jupyter stack actions.
 */
class SodaScsJupyterStackActions implements SodaScsStackActionsInterface {

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
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS jupyter actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsJupyterComponentActions;

  /**
   * Class constructor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a jupyter stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsStackInterface $stack): array {
    try {
      $stack->save();
      return [
        'message' => $this->t('Created jupyter stack: %message', ['%message' => $stack->label()]),
        'data' => [
          'stack' => $stack,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Jupyter stack creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create jupyter stack. See logs for more details."));
      return [
        'message' => $this->t('Could not create stack: %message', ['%message' => $e->getMessage()]),
        'data' => [
          'stack' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

  /**
   * Read all Jupyter stacks.
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
   * Read a jupyter stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function getStack($component): array {
    return [];
  }

  /**
   * Update a jupyter stack.
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
   * Delete a jupyter stack.
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
      $stack->delete();
      return [
        'message' => 'Deleted jupyter stack.',
        'data' => [
          'stack' => $stack,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Jupyter component deletion failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not delete jupyter component. See logs for more details."));
      return [
        'message' => 'Could not delete jupyter component.',
        'data' => [
          'jupyterComponentDeleteResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

}
