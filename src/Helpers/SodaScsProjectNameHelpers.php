<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks that project labels stay globally unique.
 *
 * Uniqueness is decided on the derived machine name, so "Mein Projekt" and
 * "mein-projekt" count as the same name. The project label doubles as the
 * Nextcloud Team Folder mount point, which must not collide either.
 */
class SodaScsProjectNameHelpers {

  /**
   * The label contains no letter to build a machine name from.
   */
  public const ERROR_EMPTY = 'empty';

  /**
   * The machine name is reserved for platform resources.
   */
  public const ERROR_RESERVED = 'reserved';

  /**
   * Another project already uses the machine name.
   */
  public const ERROR_TAKEN = 'taken';

  /**
   * Constructs the helpers.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.machine_name.helpers')]
    protected SodaScsMachineNameHelper $machineNameHelper,
  ) {}

  /**
   * Checks whether a label can be used for a project.
   *
   * @param string $label
   *   The project label.
   * @param int|string|null $excludeProjectId
   *   The project being renamed, so it does not collide with itself.
   *
   * @return array{machineName: string, error: string|null}
   *   The derived machine name and one of the ERROR_* codes, or NULL.
   */
  public function checkLabel(string $label, int|string|null $excludeProjectId = NULL): array {
    $machineName = $this->machineNameHelper->fromLabel($label);
    $error = NULL;
    if ($machineName === '') {
      $error = self::ERROR_EMPTY;
    }
    elseif ($this->machineNameHelper->isReservedProjectMachineName($machineName)) {
      $error = self::ERROR_RESERVED;
    }
    elseif ($this->isMachineNameTaken($machineName, $excludeProjectId)) {
      $error = self::ERROR_TAKEN;
    }
    return [
      'machineName' => $machineName,
      'error' => $error,
    ];
  }

  /**
   * User-facing message for an ERROR_* code.
   */
  public function errorMessage(string $error): TranslatableMarkup {
    return match ($error) {
      self::ERROR_EMPTY => new TranslatableMarkup('The project name must contain at least one letter.'),
      self::ERROR_RESERVED => new TranslatableMarkup('This project name is reserved. Please choose a different name.'),
      default => new TranslatableMarkup('A project with this or a very similar name already exists. Please choose a different name.'),
    };
  }

  /**
   * Whether another project already uses the machine name.
   */
  public function isMachineNameTaken(string $machineName, int|string|null $excludeProjectId = NULL): bool {
    $query = $this->entityTypeManager->getStorage('soda_scs_project')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machineName', $machineName)
      ->range(0, 1);
    if ($excludeProjectId !== NULL && $excludeProjectId !== '') {
      $query->condition('id', $excludeProjectId, '<>');
    }
    return $query->execute() !== [];
  }

}
