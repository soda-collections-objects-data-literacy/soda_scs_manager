<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Plugin\QueueWorker;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Queue\Attribute\QueueWorker;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\Service\SodaScsWisskiUpdateQueue;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Runs queued WissKI updates.
 *
 * Not run by cron: an update takes minutes. Queuing an update spawns
 * "drush queue:run soda_scs_manager_wisski_update" (see
 * SodaScsWisskiUpdateQueue). Failed updates are not retried; the update
 * has rolled back by then, and an admin starts it again.
 */
#[QueueWorker(
  id: SodaScsWisskiUpdateQueue::QUEUE_NAME,
  title: new TranslatableMarkup('SCS WissKI updates'),
)]
class SodaScsWisskiUpdateQueueWorker extends QueueWorkerBase implements ContainerFactoryPluginInterface {

  /**
   * Seconds an update may hold the lock of its instance.
   */
  protected const LOCK_TIMEOUT_SECONDS = 7200.0;

  /**
   * Seconds until an update that finds its instance locked is tried again.
   */
  protected const RETRY_DELAY_SECONDS = 60;

  /**
   * Constructs the worker.
   */
  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected LockBackendInterface $lock,
    protected LoggerInterface $logger,
    protected SodaScsDrupalHelpers $drupalHelpers,
    protected SodaScsProgressHelper $progressHelper,
    protected SodaScsWisskiUpdateQueue $updateQueue,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('cache_tags.invalidator'),
      $container->get('entity_type.manager'),
      $container->get('lock'),
      $container->get('logger.channel.soda_scs_manager'),
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('soda_scs_manager.progress.helpers'),
      $container->get('soda_scs_manager.wisski_update_queue'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function processItem($data): void {
    $componentId = (int) ($data['componentId'] ?? 0);
    $operationUuid = (string) ($data['operationUuid'] ?? '');
    $lockName = 'soda_scs_manager.wisski_update.' . $componentId;
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT_SECONDS)) {
      throw new DelayedRequeueException(self::RETRY_DELAY_SECONDS);
    }

    try {
      $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($componentId);
      if (!$component instanceof SodaScsComponentInterface) {
        $this->logger->warning('Queued update of WissKI component @id dropped: the component no longer exists.', [
          '@id' => $componentId,
        ]);
        $this->progressHelper->updateOperation($operationUuid, ['status' => 'failed']);
        return;
      }

      $this->progressHelper->updateOperation($operationUuid, ['status' => SodaScsWisskiUpdateQueue::STATUS_RUNNING]);
      $result = $this->runUpdate($component, $operationUuid, (string) ($data['targetVersion'] ?? 'latest'));
      if (!$result->success) {
        $this->logger->error('Update of WissKI component @id failed: @error', [
          '@error' => $result->error ?? '',
          '@id'    => $componentId,
        ]);
      }
      // The last step is what the version card shows once the update ends.
      if ($this->updateQueue->latestStep($operationUuid) !== $result->message) {
        $this->progressHelper->createStep($operationUuid, $result->message);
      }
      $this->progressHelper->updateOperation($operationUuid, ['status' => $result->success ? 'completed' : 'failed']);
      $this->cacheTagsInvalidator->invalidateTags([
        'soda_scs_manager:drupal_packages',
        'soda_scs_manager:drupal_packages:' . $componentId,
      ]);
    }
    finally {
      $this->updateQueue->finish($componentId, $operationUuid);
      $this->lock->release($lockName);
    }
  }

  /**
   * Runs the update; an exception counts as a failed update.
   */
  protected function runUpdate(SodaScsComponentInterface $component, string $operationUuid, string $targetVersion): SodaScsResult {
    try {
      return $this->drupalHelpers->updateDrupalPackages($component, $operationUuid, $targetVersion);
    }
    catch (\Throwable $e) {
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: (string) new TranslatableMarkup('Could not update @label.', ['@label' => $component->label()]),
        log: FALSE,
      );
    }
  }

}
