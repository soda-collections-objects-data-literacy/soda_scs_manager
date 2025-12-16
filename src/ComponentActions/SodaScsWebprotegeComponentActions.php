<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * WebProtégé component actions with CRUD-only behavior.
 */
final class SodaScsWebprotegeComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Class constructor.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, LoggerChannelFactoryInterface $loggerFactory, MessengerInterface $messenger, TranslationInterface $stringTranslation) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create WebProtégé Component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   The created component.
   */
  public function createComponent(SodaScsComponentInterface $entity): array {
    if (!$entity instanceof SodaScsComponentInterface) {
      return [
        'message' => $this->t('Creating a WebProtégé component from a stack is not supported.'),
        'data' => [],
        'success' => FALSE,
        'error' => 'Unsupported operation.',
        'statusCode' => 400,
      ];
    }

    try {
      $entity->save();
      return [
        'message' => $this->t('Created WebProtégé component.'),
        'data' => [
          'component' => $entity,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 201,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to create WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Snapshots are not supported for WebProtégé components.',
      error: 'Not supported.',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    try {
      $component->delete();
      return [
        'message' => $this->t('Deleted WebProtégé component.'),
        'data' => [
          'componentId' => $component->id(),
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to delete WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to delete WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(SodaScsComponentInterface $props): array {
    return [
      'message' => $this->t('Retrieved WebProtégé component.'),
      'data' => [
        'component' => $props,
      ],
      'success' => TRUE,
      'error' => NULL,
      'statusCode' => 200,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponents(): array {
    try {
      $storage = $this->entityTypeManager->getStorage('soda_scs_component');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'soda_scs_webprotege_component')
        ->execute();
      $components = $ids ? $storage->loadMultiple($ids) : [];
      return [
        'message' => $this->t('Retrieved WebProtégé components.'),
        'data' => [
          'components' => $components,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to list WebProtégé components: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to retrieve WebProtégé components.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDir): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Restore from snapshot is not supported for WebProtégé components.',
      error: 'Not supported.',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    try {
      $component->save();
      return [
        'message' => $this->t('Updated WebProtégé component.'),
        'data' => [
          'component' => $component,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to update WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

}
