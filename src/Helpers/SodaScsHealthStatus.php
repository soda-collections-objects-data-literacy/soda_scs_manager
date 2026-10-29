<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Canonical application health: running, starting, stopped, problem, unknown.
 *
 * Maps Docker container states, HTTP probes, and legacy status strings onto
 * those five values so PHP, Twig, and JavaScript share one vocabulary.
 */
final class SodaScsHealthStatus {

  public const PROBLEM = 'problem';

  public const RUNNING = 'running';

  public const STARTING = 'starting';

  public const STOPPED = 'stopped';

  public const UNKNOWN = 'unknown';

  /**
   * Canonical statuses in display order.
   *
   * @var list<string>
   */
  public const ALL = [
    self::RUNNING,
    self::STARTING,
    self::STOPPED,
    self::PROBLEM,
    self::UNKNOWN,
  ];

  /**
   * Combines Docker inspect and an optional HTTP probe into one status.
   *
   * Container state wins when the process is not running. A running container
   * with an unhealthy Docker healthcheck is a problem. Otherwise HTTP decides.
   */
  public static function combine(
    ?string $dockerStatus = NULL,
    ?int $exitCode = NULL,
    ?string $dockerHealth = NULL,
    ?int $httpCode = NULL,
    bool $httpAttempted = FALSE,
  ): string {
    $hasDocker = $dockerStatus !== NULL && $dockerStatus !== '';
    if ($hasDocker) {
      $containerStatus = self::fromDockerState($dockerStatus, $exitCode, $dockerHealth);
      if ($containerStatus !== self::RUNNING) {
        return $containerStatus;
      }
      if (!$httpAttempted) {
        return self::RUNNING;
      }
      if ($httpCode === NULL) {
        return self::PROBLEM;
      }
      return self::fromHttpCode($httpCode, TRUE);
    }

    if (!$httpAttempted) {
      return self::UNKNOWN;
    }
    if ($httpCode === NULL) {
      return self::UNKNOWN;
    }
    return self::fromHttpCode($httpCode, FALSE);
  }

  /**
   * Keeps a stopped application on starting while its setup job is running.
   *
   * Demo restore stops WissKI to copy volumes and the database, then starts
   * it again. Until that job finishes, stopped would tell the user the
   * application is off.
   */
  public static function duringSetup(string $status, bool $setupInProgress): string {
    $canonical = self::fromStatus($status);
    if ($setupInProgress && $canonical === self::STOPPED) {
      return self::STARTING;
    }
    return $canonical;
  }

  /**
   * Maps Docker inspect State (Status, ExitCode, Health.Status).
   *
   * @param array<string, mixed> $state
   *   Docker inspect State object.
   */
  public static function fromDockerInspect(array $state): string {
    $status = (string) ($state['Status'] ?? '');
    $exitCode = isset($state['ExitCode']) ? (int) $state['ExitCode'] : NULL;
    $health = NULL;
    if (isset($state['Health']) && is_array($state['Health'])) {
      $health = (string) ($state['Health']['Status'] ?? '');
    }
    return self::fromDockerState($status, $exitCode, $health !== '' ? $health : NULL);
  }

  /**
   * Maps Docker State.Status, optional exit code, and Docker healthcheck.
   *
   * Intentionally stopped containers (exited via docker stop, including
   * SIGTERM/SIGKILL exit codes) map to stopped, not problem.
   *
   * @param string $status
   *   Docker State.Status.
   * @param int|null $exitCode
   *   Docker State.ExitCode (kept for callers; not used to distinguish
   *   crash vs stop, because docker stop often yields 137/143).
   * @param string|null $health
   *   Docker State.Health.Status when present.
   */
  public static function fromDockerState(string $status, ?int $exitCode = NULL, ?string $health = NULL): string {
    unset($exitCode);
    $normalized = strtolower(trim($status));
    $dockerHealth = strtolower(trim((string) $health));

    return match ($normalized) {
      'running' => match ($dockerHealth) {
        'unhealthy' => self::PROBLEM,
        'starting' => self::STARTING,
        default => self::RUNNING,
      },
      'created', 'paused', 'exited', 'removing' => self::STOPPED,
      'dead' => self::PROBLEM,
      'restarting' => self::STARTING,
      '' => self::UNKNOWN,
      default => self::UNKNOWN,
    };
  }

  /**
   * Maps an HTTP health-probe status code.
   *
   * @param int $code
   *   HTTP status, or 0 when no response was received.
   * @param bool $containerIsRunning
   *   TRUE when Docker already confirmed the process is running.
   */
  public static function fromHttpCode(int $code, bool $containerIsRunning = FALSE): string {
    if ($code >= 200 && $code < 400) {
      return self::RUNNING;
    }
    if ($code === 401 || $code === 403 || $code === 429) {
      return self::RUNNING;
    }
    // A running container whose reverse proxy has no upstream yet is booting.
    if ($containerIsRunning && ($code === 502 || $code === 503)) {
      return self::STARTING;
    }
    if ($code === 404 || $code === 502 || $code === 503) {
      return self::UNKNOWN;
    }
    if ($code === 0) {
      return $containerIsRunning ? self::PROBLEM : self::UNKNOWN;
    }
    return self::PROBLEM;
  }

  /**
   * Maps any status string (API, entity field, legacy aliases) to canonical.
   */
  public static function fromStatus(?string $status): string {
    $normalized = strtolower(trim((string) $status));
    if (self::isCanonical($normalized)) {
      return $normalized;
    }

    return match ($normalized) {
      'healthy', 'available', 'online', 'success', 'ok' => self::RUNNING,
      'paused', 'exited', 'created', 'removing' => self::STOPPED,
      'unhealthy', 'unavailable', 'error', 'failure', 'dead', 'offline', 'tls' => self::PROBLEM,
      'restarting' => self::STARTING,
      'checking', 'not_found', 'not found', 'pending' => self::UNKNOWN,
      default => self::UNKNOWN,
    };
  }

  /**
   * Whether the value is one of the five canonical statuses.
   */
  public static function isCanonical(string $status): bool {
    return in_array($status, self::ALL, TRUE);
  }

  /**
   * Whether the canonical status means the application is serving.
   */
  public static function isRunning(string $status): bool {
    return self::fromStatus($status) === self::RUNNING;
  }

  /**
   * Rewrites a health-check array onto the canonical payload shape.
   *
   * @param mixed $health
   *   Raw helper/controller health array.
   *
   * @return array{status: string, message: string, success: bool, error: string, code: int}
   *   Canonical payload.
   */
  public static function normalizePayload(mixed $health): array {
    if (!is_array($health)) {
      return self::payload(self::UNKNOWN);
    }
    $status = self::fromStatus(isset($health['status']) ? (string) $health['status'] : '');
    $originalMessage = trim((string) ($health['message'] ?? ''));
    $error = trim((string) ($health['error'] ?? ''));
    $label = (string) self::translatableLabel($status);
    if ($error === '' && $originalMessage !== '' && $originalMessage !== $label) {
      $error = $originalMessage;
    }

    return self::payload(
      $status,
      $label,
      $error,
      (int) ($health['code'] ?? 200),
    );
  }

  /**
   * Builds the JSON health payload used by health-check routes.
   *
   * @return array{status: string, message: string, success: bool, error: string, code: int}
   *   Canonical payload.
   */
  public static function payload(
    string $status,
    ?string $message = NULL,
    string $error = '',
    int $code = 200,
  ): array {
    $canonical = self::fromStatus($status);
    return [
      'code' => $code,
      'error' => $error,
      'message' => $message ?? (string) self::translatableLabel($canonical),
      'status' => $canonical,
      'success' => $canonical === self::RUNNING,
    ];
  }

  /**
   * User-facing label for a canonical status.
   */
  public static function translatableLabel(string $status): TranslatableMarkup {
    return match (self::fromStatus($status)) {
      self::RUNNING => new TranslatableMarkup('Running'),
      self::STARTING => new TranslatableMarkup('Starting'),
      self::STOPPED => new TranslatableMarkup('Stopped'),
      self::PROBLEM => new TranslatableMarkup('Problem'),
      default => new TranslatableMarkup('Unknown'),
    };
  }

}
