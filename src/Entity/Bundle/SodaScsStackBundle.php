<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity\Bundle;

use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\BundleFieldDefinition;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;

/**
 * A bundle class for SODa SCS Stack.
 */
class SodaScsStackBundle extends SodaScsStack implements SodaScsStackInterface {

  /**
   * {@inheritdoc}
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    $definitions = [];
    switch ($bundle) {
      case 'soda_scs_wisski_stack':
        $definitions['defaultLanguage'] = BundleFieldDefinition::create('list_string')
          ->setLabel(new TranslatableMarkup('Drupal/WissKI default language'))
          ->setDescription(new TranslatableMarkup('The default language for the Drupal/WissKI interface.'))
          ->setRequired(TRUE)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'options_buttons',
            'weight' => 30,
          ])
          ->setDefaultValue('en')
          ->setCardinality(1)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'checklist',
            'weight' => 30,
          ])
          ->setSetting('allowed_values', [
            'en' => 'English',
            'de' => 'German',
          ]);


        $definitions['developmentInstance'] = BundleFieldDefinition::create('boolean')
          ->setLabel(new TranslatableMarkup('Development instance'))
          ->setDescription(new TranslatableMarkup('Whether this is a development instance. Nightly builds are used for development and testing.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'checkbox',
            'weight' => 100,
          ])
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'boolean',
            'weight' => 100,
          ]);


        $definitions['flavours'] = BundleFieldDefinition::create('list_string')
          ->setLabel(new TranslatableMarkup('Flavour'))
          ->setDescription(new TranslatableMarkup('The flavour of the SODa SCS Component.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'options_buttons',
            'weight' => 50,
          ])
          ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'checklist',
            'weight' => 50,
          ])
          /* @todo implement  'malty' => '3D', 'woody' => 'Provenance', 'herbal' => 'Conservation and Restoration'.*/
          ->setSetting('allowed_values', [
            'fruity' => '2D',
            'woody' => '3D',
            'herbal' => 'Conservation and Restoration',
          ]);
        break;
    }

    return $definitions;
  }

}
