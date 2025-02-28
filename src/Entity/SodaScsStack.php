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
 *   label = @Translation("Stack"),
 *   label_collection = @Translation("Stacks"),
 *   label_singular = @Translation("Stack"),
 *   label_plural = @Translation("Stacks"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Stacks",
 *     plural = "@count Stacks",
 *   ),
 *   bundle_label = @Translation("Stack type"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsStackListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsStackAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsStackCreateForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsStackCreateForm",
 *       "delete" = "\Drupal\soda_scs_manager\Form\SodaScsStackDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/stack/{soda_scs_stack}",
 *     "add-form" = "/soda-scs-manager/stack/add/{bundle}",
 *     "delete-form" = "/soda-scs-manager/stack/{soda_scs_stack}/delete",
 *     "collection" = "/soda-scs-manager/stacks",
 *   },
 *   base_table = "soda_scs_stack",
 *   data_table = "soda_scs_stack_field_data",
 *   field_ui_base_route = "entity.soda_scs_stack.edit_form",
 *   fieldable = TRUE,
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
   */
  public function getIncludedComponents() {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $referencedEntities */
    $referencedEntities = $this->get('includedComponents');
    return $referencedEntities->referencedEntities();
  }

  /**
   * Add included Soda SCS Component.
   */
  public function addIncludedComponent($component) {
    $this->set('includedComponents', $component);
    return $this;
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
        'weight' => 7,
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
        'weight' => 3,
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
        'weight' => 0,
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
        'type' => 'entity_reference_label',
        'weight' => 2,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The label of the SODa SCS Component.'))
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
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
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

    $fields['machineName'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('machineName'))
      ->setDescription(new TranslatableMarkup('Used for "machineName".soda-scs.org.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setCardinality(1)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_default',
        'weight' => 20,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 20,
      ]);

    $fields['partOfProject'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setDescription(new TranslatableMarkup('The project this component belongs to.'))
      ->setSetting('target_type', 'soda_scs_project')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'inline_entity_form_complex',
        'weight' => 5,
        'settings' => [
          'allow_new' => TRUE,
          'allow_existing' => TRUE,
          'match_operator' => 'CONTAINS',
        ],
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 5,
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
        'weight' => 8,
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
