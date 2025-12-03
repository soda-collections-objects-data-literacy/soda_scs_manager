<?php

declare(strict_types=1);

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
      case 'soda_scs_filesystem_component':
        $definitions['sharedWith'] = BundleFieldDefinition::create('entity_reference')
          ->setLabel(new TranslatableMarkup('Shared With'))
          ->setDescription(new TranslatableMarkup('The components that are shared with this component.'))
          ->setSetting('target_type', 'soda_scs_component')
          ->setSetting('handler', 'default')
          ->setSetting('handler_settings', [
            'target_bundles' => [
              'soda_scs_wisski_component' => 'soda_scs_wisski_component',
              'soda_scs_jupyter_component' => 'soda_scs_jupyter_component',
            ],
            'auto_create' => FALSE,
            'filter' => [
              'type' => 'soda_scs_component_access',
            ],
          ])
          ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'options_buttons',
            'weight' => 50,
          ])
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'entity_reference_label',
            'weight' => 50,
          ]);
        break;

      case 'soda_scs_sql_component':
        $definitions['partOfStack'] = BundleFieldDefinition::create('entity_reference')
          ->setLabel(new TranslatableMarkup('Part of Stack'))
          ->setDescription(new TranslatableMarkup('The stack that this component is part of.'))
          ->setSetting('target_type', 'soda_scs_stack')
          ->setSetting('handler', 'default')
          ->setCardinality(1)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'entity_reference_autocomplete',
            'weight' => 50,
          ])
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'entity_reference_label',
            'weight' => 50,
          ]);
        break;

      case 'soda_scs_triplestore_component':
        $definitions['partOfStack'] = BundleFieldDefinition::create('entity_reference')
          ->setLabel(new TranslatableMarkup('Part of Stack'))
          ->setDescription(new TranslatableMarkup('The stack that this component is part of.'))
          ->setSetting('target_type', 'soda_scs_stack')
          ->setSetting('handler', 'default')
          ->setCardinality(1)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'entity_reference_autocomplete',
            'weight' => 50,
          ]);
        break;

      case 'soda_scs_wisski_component':
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
          ->setLabel(new TranslatableMarkup('Development Instance'))
          ->setDescription(new TranslatableMarkup('Whether this is a development instance. Nightly builds are used for development and testing.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'checkbox',
            'weight' => 100,
          ]);

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
            'weight' => 40,
          ])
          /* @todo implement  'malty' => '3D', 'woody' => 'Provenance', 'herbal' => 'Conservation and Restoration'.*/
          ->setSetting('allowed_values', [
            'fruity' => '2D',
            'woody' => '3D',
            'herbal' => 'Conservation and Restoration',
          ]);


        $definitions['containerId'] = BundleFieldDefinition::create('string')
          ->setLabel(new TranslatableMarkup('Container ID'))
          ->setDescription(new TranslatableMarkup('The ID of the container.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setReadOnly(TRUE)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'string',
            'weight' => 50,
          ]);

        $definitions['containerName'] = BundleFieldDefinition::create('string')
          ->setLabel(new TranslatableMarkup('Container Name'))
          ->setDescription(new TranslatableMarkup('The name of the container.'))
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayConfigurable('view', FALSE)
          ->setReadOnly(TRUE)
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'string',
            'weight' => 50,
          ]);

        $definitions['partOfStack'] = BundleFieldDefinition::create('entity_reference')
          ->setLabel(new TranslatableMarkup('Part of Stack'))
          ->setDescription(new TranslatableMarkup('The stack that this component is part of.'))
          ->setSetting('target_type', 'soda_scs_stack')
          ->setSetting('handler', 'default')
          ->setCardinality(1)
          ->setDisplayConfigurable('form', FALSE)
          ->setDisplayOptions('form', [
            'type' => 'entity_reference_autocomplete',
            'weight' => 50,
          ])
          ->setDisplayOptions('view', [
            'label' => 'above',
            'type' => 'entity_reference_label',
            'weight' => 50,
          ]);

        break;
    }

    return $definitions;
  }

}
