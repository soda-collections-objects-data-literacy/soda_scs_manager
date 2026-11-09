<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProgressHelper;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Queues WissKI updates and runs them outside the web request.
 *
 * An update (dump, redeploy, database update, maybe a rollback) can take
 * longer than php-fpm allows a request to live; a request killed mid-way
 * leaves the instance half updated. The request therefore only queues the
 * update and spawns "drush queue:run", which has no time limit. One update
 * per instance at a time.
 */
class SodaScsWisskiUpdateQueue {

  use StringTranslationTrait;

  /**
   * Name of the queue and of its queue worker plugin.
   */
  public const QUEUE_NAME = 'soda_scs_manager_wisski_update';

  /**
   * Seconds a claimed update stays leased; longer than any update.
   */
  public const LEASE_SECONDS = 7200;

  /**
   * Output of the background run, inside the manager container.
   */
  public const LOG_FILE = '/tmp/scs-wisski-update-queue.log';

  /**
   * Operation type of update operations in the progress tables.
   */
  public const OPERATION_TYPE = 'drupal_packages_update';

  /**
   * Operation status while the update waits in the queue.
   */
  public const STATUS_QUEUED = 'queued';

  /**
   * Operation status while the queue worker runs the update.
   */
  public const STATUS_RUNNING = 'running';

  /**
   * Drush inside the manager container.
   */
  private const DRUSH = '/usr/local/bin/drush';

  /**
   * After this many seconds a queued or running update no longer blocks.
   *
   * Guards against an entry left behind by a runner that was killed, for
   * example by a restart of the manager container.
   */
  private const MAX_ACTIVE_SECONDS = 7200;

  /**
   * Seconds the result of the last update stays on the version card.
   */
  private const LAST_UPDATE_DISPLAY_SECONDS = 86400;

  /**
   * Seconds one background run may claim new items.
   */
  private const RUN_TIME_LIMIT_SECONDS = 3600;

  /**
   * State key prefix of the active update per WissKI component.
   */
  private const STATE_KEY_PREFIX = 'soda_scs_manager.wisski_update.';

  /**
   * Constructs the update queue.
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.progress.helpers')]
    protected SodaScsProgressHelper $progressHelper,
    protected QueueFactory $queueFactory,
    protected StateInterface $state,
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
  ) {}

  /**
   * Returns the queued or running update of a WissKI component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   *
   * @return array{operationUuid: string, queuedAt: int, status: string, targetVersion: string}|null
   *   NULL when no update is queued or running.
   */
  public function activeUpdate(SodaScsComponentInterface $component): ?array {
    $entry = $this->state->get(self::STATE_KEY_PREFIX . $component->id());
    if (!is_array($entry) || !is_string($entry['operationUuid'] ?? NULL) || isset($entry['finishedAt'])) {
      return NULL;
    }
    $status = (string) ($this->progressHelper->readOperation($entry['operationUuid'])['status'] ?? '');
    $isActive = in_array($status, [self::STATUS_QUEUED, self::STATUS_RUNNING], TRUE)
      && $this->time->getCurrentTime() - (int) ($entry['queuedAt'] ?? 0) < self::MAX_ACTIVE_SECONDS;
    return $isActive ? $entry + ['status' => $status] : NULL;
  }

  /**
   * Queues an update of a WissKI component and starts the background run.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   * @param string $targetVersion
   *   Update target: latest, nightly, or a version label.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success data['operationUuid'] identifies the progress operation.
   */
  public function enqueue(SodaScsComponentInterface $component, string $targetVersion): SodaScsResult {
    if ($this->activeUpdate($component) !== NULL) {
      return SodaScsResult::failure(
        error: 'Update of WissKI component ' . $component->id() . ' is already queued or running.',
        message: (string) $this->t('An update of @label is already queued or running.', ['@label' => $component->label()]),
        log: FALSE,
      );
    }

    $operationUuid = $this->progressHelper->createOperation(self::OPERATION_TYPE, self::STATUS_QUEUED);
    if ($operationUuid === NULL) {
      return SodaScsResult::failure(
        error: 'Could not create the progress operation for the update of WissKI component ' . $component->id() . '.',
        message: (string) $this->t('Could not update @label.', ['@label' => $component->label()]),
      );
    }

    $this->state->set(self::STATE_KEY_PREFIX . $component->id(), [
      'operationUuid' => $operationUuid,
      'queuedAt'      => $this->time->getCurrentTime(),
      'targetVersion' => $targetVersion,
    ]);
    $this->queueFactory->get(self::QUEUE_NAME)->createItem([
      'componentId'   => (int) $component->id(),
      'operationUuid' => $operationUuid,
      'targetVersion' => $targetVersion,
    ]);
    $this->progressHelper->createStep($operationUuid, 'Update queued');
    $this->startRunner();

    return SodaScsResult::success(
      message: (string) $this->t('The update of @label has started. Its progress is shown on this page.', ['@label' => $component->label()]),
      data: ['operationUuid' => $operationUuid],
    );
  }

  /**
   * Marks the update of a component as ended.
   *
   * @param int $componentId
   *   The WissKI component ID.
   * @param string $operationUuid
   *   The operation of the ended job; a newer entry is kept.
   */
  public function finish(int $componentId, string $operationUuid): void {
    $key = self::STATE_KEY_PREFIX . $componentId;
    $entry = $this->state->get($key);
    if (is_array($entry) && ($entry['operationUuid'] ?? NULL) === $operationUuid) {
      $this->state->set($key, $entry + ['finishedAt' => $this->time->getCurrentTime()]);
    }
  }

  /**
   * Returns the latest progress step of an update operation.
   *
   * @param string $operationUuid
   *   The operation UUID.
   *
   * @return string
   *   The step message, empty when there is none.
   */
  public function latestStep(string $operationUuid): string {
    $steps = $this->progressHelper->findLatestStepsByOperation($operationUuid, [], 'created', 'DESC', 1);
    return (string) ($steps[0]['message'] ?? '');
  }

  /**
   * Returns the ended update of a component from the last day.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component.
   *
   * @return array{finishedAt: int, operationUuid: string, status: string}|null
   *   NULL when no update ended within the last day.
   */
  public function lastUpdate(SodaScsComponentInterface $component): ?array {
    $entry = $this->state->get(self::STATE_KEY_PREFIX . $component->id());
    if (!is_array($entry) || !is_string($entry['operationUuid'] ?? NULL) || !isset($entry['finishedAt'])) {
      return NULL;
    }
    if ($this->time->getCurrentTime() - (int) $entry['finishedAt'] >= self::LAST_UPDATE_DISPLAY_SECONDS) {
      return NULL;
    }
    $status = (string) ($this->progressHelper->readOperation($entry['operationUuid'])['status'] ?? '');
    return $entry + ['status' => $status];
  }

  /**
   * Whether a background run of the update queue is in progress.
   */
  public function isRunning(): bool {
    $output = [];
    $exitCode = 1;
    exec('pgrep -f ' . escapeshellarg('[q]ueue:run ' . self::QUEUE_NAME), $output, $exitCode);
    return $exitCode === 0 && $output !== [];
  }

  /**
   * Spawns "drush queue:run" for the update queue unless one is running.
   *
   * A run that does not start is only logged: the item stays queued, and the
   * next update or the systemd timer starts a run.
   *
   * @return bool
   *   Whether a run is in progress afterwards.
   */
  public function startRunner(): bool {
    if ($this->isRunning()) {
      return TRUE;
    }

    $command = sprintf(
      'nohup %s queue:run %s --time-limit=%d --lease-time=%d --root=/opt/drupal >> %s 2>&1 < /dev/null &',
      escapeshellarg(self::DRUSH),
      escapeshellarg(self::QUEUE_NAME),
      self::RUN_TIME_LIMIT_SECONDS,
      self::LEASE_SECONDS,
      escapeshellarg(self::LOG_FILE),
    );
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    // The process needs a moment to show up in the process list.
    usleep(300000);
    if ($exitCode === 0 && $this->isRunning()) {
      return TRUE;
    }

    $this->logger->error('Could not start the WissKI update queue run (exit @code). Output is in @log.', [
      '@code' => $exitCode,
      '@log'  => self::LOG_FILE,
    ]);
    return FALSE;
  }

}
