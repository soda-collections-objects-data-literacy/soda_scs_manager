<?php

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKey;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;

/**
 * A bundle class for SODa SCS Project.
 */
class SodaScsServiceKeyBundle extends SodaScsServiceKey implements SodaScsServiceKeyInterface {

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    return [];
  }

}
