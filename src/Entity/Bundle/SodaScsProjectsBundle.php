<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProject;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;

/**
 * A bundle class for SODa SCS Project.
 */
class SodaScsProjectsBundle extends SodaScsProject implements SodaScsProjectInterface {

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    $definitions = [];
    // Add additional fields to the project bundle here.
    return $definitions;
  }

}
