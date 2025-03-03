<?php

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\BundleFieldDefinition;
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
    switch ($bundle) {
      case 'default':
        $definitions['testFields'] = BundleFieldDefinition::create('string')
          ->setLabel(new TranslatableMarkup('Test fields'))
          ->setDescription(new TranslatableMarkup('Test fields for the project.'))
          ->setRequired(TRUE)
          ->setReadOnly(TRUE)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE);
        break;
    }
    return $definitions;
  }

}
