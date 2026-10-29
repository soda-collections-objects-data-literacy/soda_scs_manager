<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Queue\QueueFactory;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Starts the demo queue from a web request.
 *
 * Jobs take minutes, so the request only spawns "drush queue:run" and
 * returns. One runner at a time; a second click finds it already going.
 */
class SodaScsDemoQueueRunner {

  use StringTranslationTrait;

  /**
   * Drush inside the manager container.
   */
  protected const DRUSH = '/usr/local/bin/drush';

  /**
   * How long one queue:run may work before the loop checks for delayed items.
   *
   * A delayed item is not claimable, and queue:run exits at once. The loop
   * stays up so a WissKI that is still installing is retried.
   */
  protected const CLAIM_WINDOW_SECONDS = 900;

  /**
   * How long the background loop keeps retrying delayed jobs.
   */
  protected const RUN_BUDGET_SECONDS = 7200;

  /**
   * Pause between runs while a job is still waiting out its delay.
   */
  protected const RETRY_PAUSE_SECONDS = 20;

  /**
   * Output of the background run, inside the manager container.
   */
  public const LOG_FILE = '/tmp/scs-demo-queue.log';

  /**
   * Constructs the runner.
   */
  public function __construct(
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    protected QueueFactory $queueFactory,
  ) {}

  /**
   * Whether a queue run is already in progress.
   */
  public function isRunning(): bool {
    $pattern = '[q]ueue:run ' . SodaScsDemoManager::QUEUE_NAME;
    $output = [];
    $exitCode = 1;
    exec('pgrep -f ' . escapeshellarg($pattern), $output, $exitCode);
    return $exitCode === 0 && $output !== [];
  }

  /**
   * Jobs waiting in the demo queue.
   */
  public function pendingCount(): int {
    return (int) $this->queueFactory->get(SodaScsDemoManager::QUEUE_NAME)->numberOfItems();
  }

  /**
   * Spawns the queue run when jobs are waiting and none is running.
   */
  public function start(): SodaScsResult {
    if ($this->pendingCount() < 1) {
      return SodaScsResult::failure(
        error: 'No demo jobs.',
        message: (string) $this->t('There are no demo jobs waiting.'),
        log: FALSE,
      );
    }
    if ($this->isRunning()) {
      return SodaScsResult::success(
        data: ['alreadyRunning' => TRUE],
        message: (string) $this->t('The demo jobs are already running.'),
      );
    }

    $drush = escapeshellarg(self::DRUSH);
    $queue = escapeshellarg(SodaScsDemoManager::QUEUE_NAME);
    $countItems = escapeshellarg("SELECT COUNT(*) FROM queue WHERE name='" . SodaScsDemoManager::QUEUE_NAME . "'");
    // The script text contains "queue:run <name>" so isRunning() still
    // matches this process while it sleeps between delayed items.
    $script = sprintf(
      'end=$((SECONDS+%d)); while [ "$SECONDS" -lt "$end" ]; do %s queue:run %s --time-limit=%d --lease-time=%d --root=/opt/drupal || true; left=$(%s sql:query %s --root=/opt/drupal | tr -cd "0-9"); if [ "$left" = "0" ]; then break; fi; sleep %d; done',
      self::RUN_BUDGET_SECONDS,
      $drush,
      $queue,
      self::CLAIM_WINDOW_SECONDS,
      self::RUN_BUDGET_SECONDS,
      $drush,
      $countItems,
      self::RETRY_PAUSE_SECONDS,
    );
    $command = sprintf(
      'nohup bash -c %s >> %s 2>&1 < /dev/null &',
      escapeshellarg($script),
      escapeshellarg(self::LOG_FILE),
    );
    exec($command, $output, $exitCode);
    // The process needs a moment to show up in the process list.
    usleep(300000);
    if ($exitCode !== 0 || !$this->isRunning()) {
      $this->logger->error('Could not start the demo queue run (exit @code). Output is in @log.', [
        '@code' => $exitCode,
        '@log' => self::LOG_FILE,
      ]);
      return SodaScsResult::failure(
        error: 'Could not start the demo queue run.',
        message: (string) $this->t('Could not start the demo jobs. See the log @log.', ['@log' => self::LOG_FILE]),
        log: FALSE,
      );
    }

    return SodaScsResult::success(
      data: ['alreadyRunning' => FALSE],
      message: (string) $this->t('The demo jobs are running.'),
    );
  }

}
