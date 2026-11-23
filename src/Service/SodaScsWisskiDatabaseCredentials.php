<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Replaces the database account in the settings.php of a running WissKI.
 *
 * The WissKI entrypoint writes the database credentials into settings.php
 * only on the first install; later changes of DB_USER and DB_PASSWORD in the
 * stack environment do not reach an installed site.
 */
class SodaScsWisskiDatabaseCredentials {

  /**
   * Marks the script result in drush output, which may contain notices.
   */
  protected const OUTPUT_MARKER = 'SCS_DB_CREDENTIALS:';

  /**
   * Constructs the service.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $containerHelpers
   *   The container helpers.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
  ) {}

  /**
   * Replaces username and password of the default database connection.
   *
   * Each old value must occur exactly once, so a settings.php that was
   * changed by hand is left alone. A file that already holds the new values
   * counts as done.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The running WissKI component.
   * @param string $fromPassword
   *   The current database password.
   * @param string $fromUser
   *   The current database user.
   * @param string $toPassword
   *   The new database password.
   * @param string $toUser
   *   The new database user.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['state'] is "updated" or "unchanged".
   */
  public function replace(SodaScsComponentInterface $wisskiComponent, string $fromPassword, string $fromUser, string $toPassword, string $toUser): SodaScsResult {
    // The credentials travel in the environment of the exec, not in the
    // command, so they stay out of the process list and the logs.
    $script = '$file = DRUPAL_ROOT . "/" . \Drupal::getContainer()->getParameter("site.path") . "/settings.php";'
      . '$code = (string) file_get_contents($file);'
      . '$pairs = [["username", getenv("SCS_DB_FROM_USER"), getenv("SCS_DB_TO_USER")], ["password", getenv("SCS_DB_FROM_PASSWORD"), getenv("SCS_DB_TO_PASSWORD")]];'
      . '$changed = FALSE;'
      . 'foreach ($pairs as [$key, $from, $to]) {'
      . '  $old = "\'" . $key . "\' => " . var_export((string) $from, TRUE) . ",";'
      . '  $new = "\'" . $key . "\' => " . var_export((string) $to, TRUE) . ",";'
      . '  if ($old === $new) { continue; }'
      . '  $oldCount = substr_count($code, $old);'
      . '  if ($oldCount === 0 && substr_count($code, $new) === 1) { continue; }'
      . '  if ($oldCount !== 1) { echo "' . self::OUTPUT_MARKER . 'mismatch " . $key; return; }'
      . '  $code = str_replace($old, $new, $code);'
      . '  $changed = TRUE;'
      . '}'
      . 'if (!$changed) { echo "' . self::OUTPUT_MARKER . 'unchanged"; return; }'
      . '$perms = fileperms($file) & 0777;'
      . 'chmod($file, $perms | 0200);'
      . '$written = file_put_contents($file, $code);'
      . 'chmod($file, $perms);'
      . 'echo "' . self::OUTPUT_MARKER . '" . ($written === FALSE ? "write failed" : "updated");';
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['drush', 'php:eval', $script],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'env' => [
        'SCS_DB_FROM_PASSWORD=' . $fromPassword,
        'SCS_DB_FROM_USER=' . $fromUser,
        'SCS_DB_TO_PASSWORD=' . $toPassword,
        'SCS_DB_TO_USER=' . $toUser,
      ],
      'timeout' => 300,
      'user' => 'www-data',
    ]);
    if (!$result->success) {
      return SodaScsResult::failure(
        message: 'Could not update the database account of the WissKI.',
        error: (string) ($result->error ?? 'drush failed'),
      );
    }
    $output = (string) ($result->data['output'] ?? '');
    if (!preg_match('/' . preg_quote(self::OUTPUT_MARKER, '/') . '(updated|unchanged|mismatch \w+|write failed)/', $output, $matches)) {
      return SodaScsResult::failure(
        message: 'Could not update the database account of the WissKI.',
        error: 'Missing result in drush output.',
      );
    }
    if (!in_array($matches[1], ['updated', 'unchanged'], TRUE)) {
      return SodaScsResult::failure(
        message: 'Could not update the database account of the WissKI.',
        error: sprintf('settings.php of WissKI %s: %s.', $wisskiComponent->id(), $matches[1]),
      );
    }
    return SodaScsResult::success(message: 'Database account of the WissKI checked.', data: ['state' => $matches[1]]);
  }

}
