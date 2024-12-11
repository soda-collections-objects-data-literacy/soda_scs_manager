<?php

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\entity\BundleFieldDefinition;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * A bundle class for WissKI Stack.
 */
class SodaScsWisskiStack extends SodaScsStack implements SodaScsStackInterface {

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    $definitions = [];
    if ($bundle == 'wisski_stack') {
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
        ->setSetting('allowed_values', [
          'sweet' => 'Add default data model',
          'fruity' => '2D',
          'malty' => '3D',
          'woody' => 'Provenance',
          'herbal' => 'Conservation and Restoration',
        ]);
    }

    return $definitions;
  }

}
