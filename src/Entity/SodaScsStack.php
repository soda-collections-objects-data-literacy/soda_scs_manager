<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Soda SCS Stack entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_stack",
 *   label = @Translation("Soda SCS Stack"),
 *   label_collection = @Translation("Soda SCS Stacks"),
 *   label_singular = @Translation("Soda SCS Stack"),
 *   label_plural = @Translation("Soda SCS Stacks"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Soda SCS Stacks",
 *     plural = "@count Soda SCS Stacks",
 *   ),
 *   bundle_label = @Translation("Soda SCS Stack type"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\soda_scs_manager\ViewBuilder\SodaScsStackViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsStackListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsStackAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsStackCreateForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsStackCreateForm",
 *       "edit" = "Drupal\soda_scs_manager\Form\SodaScsStackEditForm",
 *       "delete" = "\Drupal\soda_scs_manager\Form\SodaScsStackDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/stack/{soda_scs_stack}",
 *     "add-form" = "/soda-scs-manager/stack/add/{bundle}",
 *     "edit-form" = "/soda-scs-manager/stack/{soda_scs_stack}/edit",
 *     "delete-form" = "/soda-scs-manager/stack/{soda_scs_stack}/delete",
 *     "collection" = "/soda-scs-manager/stacks",
 *   },
 *   base_table = "soda_scs_stack",
 *   data_table = "soda_scs_stack_field_data",
 *   field_ui_base_route = "entity.soda_scs_stack.edit_form",
 *   fieldable = TRUE,
 *   translatable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "bundle" = "bundle",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "uid" = "owner",
 *     "langcode" = "langcode",
 *     "owner" = "owner",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "bundle",
 *   }
 * )
 */
class SodaScsStack extends ContentEntityBase implements SodaScsStackInterface {

  use EntityOwnerTrait;
  /**
   * The entity relation to the Soda SCS Component.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $component;

  /**
   * Get the included Soda SCS Components.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   Stack.
   * @param string $fieldName
   *   Field name.
   *
   * @return array
   *   The referenced entities.
   */
  public function getValue(SodaScsStackInterface $stack, string $fieldName) {
    if($stack->get($fieldName)->isEmpty()) {
      return [];
    }

    $field_type = $stack->get($fieldName)->getFieldDefinition()->getType();
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $field */
    $field = $stack->get($fieldName);
    if ($field_type == 'entity_reference') {
      return $field->referencedEntities();
    } else {
      return $field->getValue();
    }

  }

  /**
   * Smart multi value field setter.
   *
   * Example calls:
   *
   *  setValue($stack, 'includedComponents', $componentId, 0);
   *
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   Stack.
   * @param string $fieldName
   *   Field name.
   * @param string $value
   *   Value to be put in $stack->field[$index]->value.
   * @param ?int $index
   *   The delta i.e. $stack->field[$index]
   * @param string $defaultValue
   *   The default values that will be written into the previous indexes.
   * @param bool $overwriteOldValues
   *   TRUE to ignore previous index values and overwrite them with $default_value.
   */
  public static function setValue(SodaScsStackInterface $stack, string $fieldName, string $value, ?int $index = NULL, string $defaultValue = "", bool $overwriteOldValues = FALSE)
  {
    $oldValues = $stack->get($fieldName)->getValue();

    // Grab old values and put them into $newValues array.

    $fieldType = $stack->get($fieldName)->getFieldDefinition()->getType();
    if ($fieldType == 'entity_reference') {
      foreach ($oldValues as $key => $oldValue) {
        $newValues[$key] = $oldValues[$key];
      }
    } else {
      $newValues = [];
      foreach ($oldValues as $oldValue) {
        $newValues[]["value"] = $oldValues["value"];
      }
    }

    // Optionally overwrite previous values with the provided default.
    if ($overwriteOldValues) {
      for ($i = 0; $i < $index; $i++) {
        $newValues[$i] = $defaultValue;
      }
    }

    $currentCount = count($newValues ?? []);

    // If index is within current bounds, insert and shift items to the right.
    if ($index < $currentCount) {
      if ($fieldType == 'entity_reference') {
        $insertItem = ['target_id' => $value];
      } else {
        $insertItem = ['value' => $value];
      }
      array_splice($newValues, $index, 0, [$insertItem]);
    }
    else {
      // If index is beyond current bounds, pad missing items up to index - 1.
      for ($i = $currentCount; $i < $index; $i++) {
        if ($fieldType == 'entity_reference') {
          $newValues[$i] = $defaultValue;
        } else {
          $newValues[$i]['value'] = $defaultValue;
        }
      }
      // Set the value at the target index.
      if ($fieldType == 'entity_reference') {
        $newValues[$index]['target_id'] = $value;
      } else {
        $newValues[$index]['value'] = $value;
      }
    }

    $stack->set($fieldName, $newValues);
  }

  /**
   * Set the label.
   *
   * @param string $label
   *   The label.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsStackInterface
   *   The called object.
   */
  public function setLabel($label) {
    $this->set('label', $label);
    return $this;
  }

  /**
   * Get the label.
   *
   * @return string
   *   The label.
   */
  public function getLabel() {
    return $this->get('label')->value;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Component was created.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 70,
      ]);

    $fields['description'] = BaseFieldDefinition::create('text_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('The description of the SODa SCS Component.'))
      ->setRequired(FALSE)
      ->setReadOnly(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'label' => 'above',
        'region' => 'hidden',
        'weight' => 30,
        'settings' => [
          'rows' => 10,
          'cols' => 100,
          'format' => 'full_html',
        ],
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 3,
      ]);

    $fields['imageUrl'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setDescription(new TranslatableMarkup('The image of the SODa SCS Stack.'))
      ->setRequired(FALSE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'image',
        'weight' => -10,
      ]);

    // @todo Insure to have only dangling components as references.
    $fields['includedComponents'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Included components'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'soda_scs_component_label_with_type',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setCardinality(1)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 10,
      ]);

    $fields['machineName'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine Name'))
      ->setDescription(new TranslatableMarkup('The machine-readable name.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 15,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      // Add constraint to ensure machine name format is valid.
      ->addPropertyConstraints('value', [
        'Regex' => [
          'pattern' => '/^[a-z0-9-]+$/',
          'message' => t('Machine name must contain only lowercase letters, numbers, and minus.'),
        ],
      ]);

    $fields['notes'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Notes'))
      ->setDescription(new TranslatableMarkup('Notes about the SODa SCS application.'))
      ->setRequired(FALSE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textarea',
        'weight' => 60,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 60,
      ]);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The owner of the SODa SCS Stack.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 30,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 30,
      ]);



    $fields['partOfProjects'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setDescription(new TranslatableMarkup('The project this application belongs to.'))
      ->setSetting('target_type', 'soda_scs_project')
      ->setSetting('handler', 'soda_scs_project_access')
      ->setRequired(FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ]);

    $fields['snapshots'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Snapshots'))
      ->setDescription(new TranslatableMarkup('The snapshots of the SODa SCS Stack.'))
      ->setSetting('target_type', 'soda_scs_snapshot')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 60,
      ]);

    $fields['updated'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Updated'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Component was last updated.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 80,
      ]);

    return $fields;

  }

  /**
   * Define bundle field definitions.
   */
  public static function bundleFieldDefinitions(EntityTypeInterface $entity_type, $bundle, array $base_field_definitions) {
    // Fields to be shared by all bundles go here.
    $definitions = [];

    // Then add fields from the bundle in the current instance.
    $bundles = \Drupal::service('entity_type.bundle.info')->getBundleInfo('soda_scs_stack');
    foreach ($bundles as $key => $values) {
      if ($bundle == $key) {
        // Get a string we can call bundleFieldDefinitions() on that Drupal will
        // be able to find, like
        // "\Drupal\my_module\Entity\Bundle\MyBundleClass".
        $qualified_class = '\\' . $values['class'];
        $definitions = $qualified_class::bundleFieldDefinitions($entity_type, $bundle, []);
      }
    }
    return $definitions;
  }

  /**
   * Get the default user ID.
   *
   * @return int
   *   The default user ID.
   */
  public static function getDefaultUserId() {
    return \Drupal::currentUser()->isAnonymous() ? NULL : \Drupal::currentUser()->id();
  }

  /**
   * Check if the current user is an admin.
   *
   * @return bool
   *   TRUE if the current user is an admin, FALSE otherwise.
   */
  public static function isAdmin() {
    return \Drupal::currentUser()->hasPermission('soda scs manager admin');

  }

}
