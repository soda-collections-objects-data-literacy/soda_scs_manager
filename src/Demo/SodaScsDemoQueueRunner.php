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
   * Output of a run started by another user than the web server.
   */
  public const USER_LOG_FILE_PATTERN = '/tmp/scs-demo-queue-%s.log';

  /**
   * Shell pattern that matches LOG_FILE and every user's file.
   */
  public const LOG_FILE_GLOB = '/tmp/scs-demo-queue*.log';

  /**
   * The user that owns LOG_FILE: PHP-FPM and the queue timer run as it.
   */
  protected const WEB_USER = 'www-data';

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
   * The log file of a run started by the current process user.
   *
   * /tmp is sticky, and fs.protected_regular forbids even root to open a
   * file of another user there with O_CREAT: a run started by drush as root
   * could not open the web server's log, and the shell gave up before the
   * loop started. Every other user gets a file of its own.
   */
  public function logFile(): string {
    $user = $this->processUserName();
    if ($user === '' || $user === self::WEB_USER) {
      return self::LOG_FILE;
    }
    return sprintf(self::USER_LOG_FILE_PATTERN, preg_replace('/[^a-z0-9_-]/i', '_', $user));
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
    $pendingBefore = $this->pendingCount();
    if ($pendingBefore < 1) {
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
    $logFile = $this->logFile();
    $command = sprintf(
      'nohup bash -c %s >> %s 2>&1 < /dev/null &',
      escapeshellarg($script),
      escapeshellarg($logFile),
    );
    $exitCode = $this->spawn($command);
    // A job that is refused at once can be done before the process check:
    // the loop has then already exited, and fewer jobs are waiting.
    $hasRun = $this->isRunning() || $this->pendingCount() < $pendingBefore;
    if ($exitCode !== 0 || !$hasRun) {
      $this->logger->error('Could not start the demo queue run (exit @code). Output is in @log.', [
        '@code' => $exitCode,
        '@log' => $logFile,
      ]);
      return SodaScsResult::failure(
        error: 'Could not start the demo queue run.',
        message: (string) $this->t('Could not start the demo jobs. See the log @log.', ['@log' => $logFile]),
        log: FALSE,
      );
    }

    return SodaScsResult::success(
      data: ['alreadyRunning' => FALSE],
      message: (string) $this->t('The demo jobs are running.'),
    );
  }

  /**
   * Name of the user this PHP process runs as.
   *
   * A uid without a passwd entry (docker exec -u 1234) gives the number, so
   * that run gets a file of its own too. '' only without the posix extension.
   */
  protected function processUserName(): string {
    if (!function_exists('posix_geteuid')) {
      return '';
    }
    $uid = posix_geteuid();
    $account = posix_getpwuid($uid);
    return is_array($account) && ($account['name'] ?? '') !== '' ? (string) $account['name'] : (string) $uid;
  }

  /**
   * Runs the background command and waits until it shows up.
   *
   * @param string $command
   *   The shell command that puts the loop in the background.
   *
   * @return int
   *   The exit code of the shell.
   */
  protected function spawn(string $command): int {
    $output = [];
    $exitCode = 1;
    exec($command, $output, $exitCode);
    // The process needs a moment to show up in the process list.
    usleep(300000);
    return $exitCode;
  }

}
