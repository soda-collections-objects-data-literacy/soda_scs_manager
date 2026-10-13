<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Reports on how applications are attached to projects.
 */
final class SodaScsProjectApplicationCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Application entity types that must belong to a project.
   */
  private const APPLICATION_ENTITY_TYPES = ['soda_scs_stack', 'soda_scs_component'];

  /**
   * Constructs the commands.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {
    parent::__construct();
  }

  /**
   * Lists applications that do not belong to any project.
   *
   * Projects are mandatory for new applications. The listed entries predate
   * that rule; assign them to a project or delete them manually. Components
   * inside a stack are skipped because they follow their stack.
   */
  #[CLI\Command(name: 'soda_scs_manager:applications-without-project', aliases: ['scs:applications-without-project'])]
  #[CLI\Usage(name: 'drush soda_scs_manager:applications-without-project', description: 'List stacks and standalone components without a project.')]
  public function applicationsWithoutProject(): int {
    $rows = [];
    foreach (self::APPLICATION_ENTITY_TYPES as $entityTypeId) {
      $storage = $this->entityTypeManager->getStorage($entityTypeId);
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->notExists('partOfProjects')
        ->sort('id')
        ->execute();
      foreach ($storage->loadMultiple($ids) as $entity) {
        if ($entity->hasField('partOfStack') && !$entity->get('partOfStack')->isEmpty()) {
          continue;
        }
        $owner = method_exists($entity, 'getOwner') ? $entity->getOwner() : NULL;
        $rows[] = [
          $entityTypeId,
          $entity->id(),
          $entity->bundle(),
          (string) $entity->label(),
          $owner ? $owner->getAccountName() : '-',
        ];
      }
    }

    if ($rows === []) {
      $this->logger()->success('Every application belongs to a project.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->table(['Entity type', 'ID', 'Bundle', 'Label', 'Owner'], $rows);
    $this->logger()->warning(sprintf('%d application(s) without a project.', count($rows)));
    return self::EXIT_SUCCESS;
  }

}
