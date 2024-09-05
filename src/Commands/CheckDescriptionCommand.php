<?php

namespace Drupal\soda_scs_manager\Commands;

use Drush\Commands\DrushCommands;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;

/**
 * A Drush command file.
 */
class CheckDescriptionCommand extends DrushCommands
{

  /**
   * Check the description field of all SodaScsComponent entities.
   *
   * @command soda_scs_manager:check-description
   * @aliases check-description
   */
  public function checkDescription()
  {
    $storage = \Drupal::entityTypeManager()->getStorage('soda_scs_component');
    $entity_ids = $storage->getQuery()
      ->accessCheck(TRUE) // Explicitly set access check
      ->execute();

    // Load entities in batches
    $batch_size = 1;
    $batches = array_chunk($entity_ids, $batch_size);

    foreach ($batches as $batch) {
      $entities = $storage->loadMultiple($batch);
      foreach ($entities as $entity) {
        if (is_array($entity->get('description')->value)) {
          $this->logger()->error(dt('Entity ID @id has an array as description.', ['@id' => $entity->id()]));
        }
      }
    }

    $this->logger()->success(dt('Description check completed.'));
  }
}
