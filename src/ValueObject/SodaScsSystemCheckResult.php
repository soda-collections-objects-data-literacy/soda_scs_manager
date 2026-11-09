<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ValueObject;

/**
 * Outcome of one SCS system check.
 */
final class SodaScsSystemCheckResult {

  /**
   * The check passed.
   */
  public const STATUS_OK = 'ok';

  /**
   * The check found something that needs attention but works for now.
   */
  public const STATUS_WARNING = 'warning';

  /**
   * The check failed; a feature of the SCS does not work.
   */
  public const STATUS_ERROR = 'error';

  /**
   * Constructs a check result.
   *
   * @param string $id
   *   Machine name, unique among all checks.
   * @param string $group
   *   Group label, for example "Settings".
   * @param string $title
   *   What was checked.
   * @param string $status
   *   One of the STATUS_* constants.
   * @param string $value
   *   Short result, for example "reachable" or "3 minutes ago".
   * @param string $description
   *   What is wrong and how to fix it; empty when the check passed.
   */
  public function __construct(
    public readonly string $id,
    public readonly string $group,
    public readonly string $title,
    public readonly string $status,
    public readonly string $value,
    public readonly string $description = '',
  ) {}

  /**
   * Whether the check failed.
   */
  public function isError(): bool {
    return $this->status === self::STATUS_ERROR;
  }

  /**
   * Whether the check passed.
   */
  public function isOk(): bool {
    return $this->status === self::STATUS_OK;
  }

}
