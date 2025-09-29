<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\StackActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the jupyter stack actions.
 */
#[Autowire(service: 'soda_scs_manager.jupyter_stack.actions')]
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
    #[Autowire(service: 'soda_scs_manager.stack.helpers')]
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
   * Create a snapshot of a jupyter stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   * @param string $snapshotMachineName
   *   The snapshot machine name.
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function createSnapshot(SodaScsStackInterface $stack, string $snapshotMachineName, int $timestamp): SodaScsResult {
    return SodaScsResult::success(
      data: [
        'jupyterComponentSnapshot' => [],
        'snapshotMachineName' => $snapshotMachineName,
        'timestamp' => $timestamp,
      ],
      message: 'Jupyter stack snapshot created.',
    );
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

  /**
   * Restore a Jupyter stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Not implemented.',
      error: 'Jupyter stack restored from snapshot failed.',
    );
  }

}
