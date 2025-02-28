<?php

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\BundleFieldDefinition;
use Drupal\soda_scs_manager\Entity\SodaScsComponent;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

/**
 * A bundle class for SODa SCS Component.
 */
class SodaScsComponentBundle extends SodaScsComponent implements SodaScsComponentInterface {

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    $definitions = [];
    switch ($bundle) {
      case 'soda_scs_wisski_component':
        $definitions['flavours'] = BundleFieldDefinition::create('list_string')
          ->setLabel(new TranslatableMarkup('Flavour'))
          ->setDescription(new TranslatableMarkup('The flavour of the SODa SCS Component.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'options_buttons',
            'weight' => 4,
          ])
          ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'checklist',
            'weight' => 4,
          ])
          /* @todo implement  'malty' => '3D', 'woody' => 'Provenance', 'herbal' => 'Conservation and Restoration'.*/
          ->setSetting('allowed_values', [
            'sweet' => 'default data model',
            'fruity' => '2D',
          ]);

        break;

      case 'soda_scs_filesystem_component':
        $definitions['connectedComponents'] = BundleFieldDefinition::create('entity_reference')
          ->setLabel(new TranslatableMarkup('Connected components'))
          ->setDescription(new TranslatableMarkup('The connected components of the SODa SCS Component.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'options_buttons',
            'weight' => 60,
          ])
          ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'checklist',
            'weight' => 60,
          ])
          ->setSetting('target_type', 'soda_scs_component')
          ->setSetting('handler', 'default')
          ->setRequired(FALSE)
          ->setReadOnly(TRUE)
          ->setTranslatable(FALSE);

        break;
    }

    return $definitions;
  }

}
