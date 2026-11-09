<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\soda_scs_manager\Service\SodaScsSystemCheck;
use Drupal\soda_scs_manager\ValueObject\SodaScsSystemCheckResult;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Drush commands for the SCS system check.
 */
final class SodaScsSystemCheckCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Status markers for the result table.
   */
  private const STATUS_MARKERS = [
    SodaScsSystemCheckResult::STATUS_ERROR   => 'ERROR',
    SodaScsSystemCheckResult::STATUS_OK      => 'ok',
    SodaScsSystemCheckResult::STATUS_WARNING => 'WARN',
  ];

  public function __construct(
    #[Autowire(service: 'soda_scs_manager.system_check')]
    protected SodaScsSystemCheck $systemCheck,
  ) {
    parent::__construct();
  }

  /**
   * Check settings, services, host timers, queues and WissKI containers.
   *
   * Exits with 1 when a check fails, so scripts can stop on it.
   */
  #[CLI\Command(name: 'soda_scs_manager:check', aliases: ['scs:check'])]
  #[CLI\Option(name: 'problems', description: 'Only list checks that did not pass.')]
  #[CLI\Usage(name: 'drush scs:check', description: 'Run all checks.')]
  #[CLI\Usage(name: 'drush scs:check --problems', description: 'Show only warnings and errors.')]
  public function check(array $options = ['problems' => FALSE]): int {
    $results = $this->systemCheck->run();
    $rows = [];
    foreach ($results as $result) {
      if ($options['problems'] && $result->isOk()) {
        continue;
      }
      $rows[] = [
        self::STATUS_MARKERS[$result->status] ?? $result->status,
        $result->group,
        $result->title,
        $result->value,
        wordwrap($result->description, 70),
      ];
    }
    if ($rows !== []) {
      $this->io()->table(['', 'Group', 'Check', 'Result', 'Details'], $rows);
    }

    $errors = count(array_filter($results, static fn (SodaScsSystemCheckResult $result): bool => $result->isError()));
    $warnings = count(array_filter($results, static fn (SodaScsSystemCheckResult $result): bool => $result->status === SodaScsSystemCheckResult::STATUS_WARNING));
    $summary = dt('@total checks: @errors errors, @warnings warnings.', [
      '@errors'   => $errors,
      '@total'    => count($results),
      '@warnings' => $warnings,
    ]);
    if ($errors > 0) {
      $this->logger()->error($summary);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($summary);
    return self::EXIT_SUCCESS;
  }

  /**
   * Record that a host timer has run; called by the systemd units.
   *
   * @param string $name
   *   Heartbeat name: demo-queue, nextcloud-mount-reconcile or
   *   wisski-update-queue.
   */
  #[CLI\Command(name: 'soda_scs_manager:heartbeat', aliases: ['scs:heartbeat'])]
  #[CLI\Argument(name: 'name', description: 'Heartbeat name of the timer.')]
  #[CLI\Usage(name: 'drush scs:heartbeat wisski-update-queue', description: 'Record a run of the WissKI update queue timer.')]
  public function heartbeat(string $name): int {
    try {
      $this->systemCheck->recordHeartbeat($name);
    }
    catch (\InvalidArgumentException $e) {
      $this->logger()->error($e->getMessage());
      return self::EXIT_FAILURE;
    }
    return self::EXIT_SUCCESS;
  }

}
