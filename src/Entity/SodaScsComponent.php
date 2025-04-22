<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * SODa SCS Component.
 *
 * @ContentEntityType(
 *   id = "soda_scs_component",
 *   label = @Translation("SODa SCS Component"),
 *   label_collection = @Translation("SODa SCS Components"),
 *   label_singular = @Translation("SODa SCS Component"),
 *   label_plural = @Translation("SODa SCS Components"),
 *   label_count = @PluralTranslation(
 *     singular = "@count SODa SCS Component",
 *     plural = "@count SODa SCS Components",
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsComponentListBuilder",
 *     "view_builder" = "Drupal\soda_scs_manager\ViewBuilder\SodaScsComponentViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsComponentCreateForm",
 *       "delete" = "\Drupal\soda_scs_manager\Form\SodaScsComponentDeleteForm",
 *     },
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsComponentAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "soda_scs_component",
 *   data_table = "soda_scs_component_field_data",
 *   admin_permission = "administer soda scs component entities",
 *   field_ui_base_route = "entity.soda_scs_component.edit_form",
 *   fieldable = TRUE,
 *   translatable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "bundle" = "bundle",
 *     "id" = "id",
 *     "langcode" = "langcode",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "uid" = "owner",
 *     "owner" = "owner",
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/component/{soda_scs_component}",
 *     "add-form" = "/soda-scs-manager/component/add/{bundle}",
 *     "delete-form" = "/soda-scs-manager/component/{soda_scs_component}/delete",
 *     "collection" = "/soda-scs-manager/components",
 *   },
 *
 *   config_export = {
 *    "bundle",
 *    "connectedComponents",
 *    "created",
 *    "description",
 *    "flavours",
 *    "id",
 *    "imageUrl",
 *    "label",
 *    "langcode",
 *    "machineName",
 *    "uuid",
 *    "updated",
 *    "owner",
 *    "partOfProjects",
 *    }
 * )
 */
class SodaScsComponent extends ContentEntityBase implements SodaScsComponentInterface {

  use EntityOwnerTrait;

  /**
   * Returns the description of the SODa SCS Component.
   *
   * @return string
   *   The description of the SODa SCS Component.
   */
  public function getDescription() {
    return $this->description;
  }

  /**
   * Sets the description of the SODa SCS Component.
   *
   * @param string $description
   *   The description of the SODa SCS Component.
   *
   * @return $this
   */
  public function setDescription($description) {
    $this->description = $description;
    return $this;
  }

  /**
   * Returns the image of the SODa SCS Component.
   *
   * @return string
   *   The image of the SODa SCS Component.
   */
  public function getImageUrl() {
    return $this->imageUrl;
  }

  /**
   * Sets the image of the SODa SCS Component.
   *
   * @param string $imageUrl
   *   The image of the SODa SCS Component.
   *
   * @return $this
   */
  public function setImageUrl($imageUrl) {
    $this->imageUrl = $imageUrl;
    return $this;
  }

  /**
   * Returns the label of the SODa SCS Component.
   *
   * @return string
   *   The label of the SODa SCS Component.
   */
  public function getLabel() {
    return $this->label->value;
  }

  /**
   * Sets the label of the SODa SCS Component.
   *
   * @param string $label
   *   The label of the SODa SCS Component.
   *
   * @return $this
   */
  public function setLabel($label) {
    $this->label->value = $label;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    // Fetch any existing base field definitions from the parent class (= id, uuid, langcode, bundle).
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Component was created.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
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
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'text_textarea',
        'label' => 'above',
        'region' => 'hidden',
        'weight' => 3,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'text_default',
        'weight' => 3,
      ]);

    $fields['externalId'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('External ID'))
      ->setDescription(new TranslatableMarkup('The external ID of the SODa SCS Component.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['health'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Health Status'))
      ->setDescription(new TranslatableMarkup('The health status of the SODa SCS Component.'))
      ->setRequired(FALSE)
    // Ensure this is read-only as it will be updated via JavaScript.
      ->setReadOnly(TRUE)
      ->setCardinality(1)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'default_value' => 'Unknown',
        'weight' => 0,
      ]);

    $fields['imageUrl'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Image'))
      ->setDescription(new TranslatableMarkup('The image of the SODa SCS Component.'))
      ->setRequired(FALSE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The label of the SODa SCS Component.'))
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ]);

    $fields['machineName'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Machine Name'))
      ->setDescription(new TranslatableMarkup('The machine-readable name of the project.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 32)
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
      ->setDescription(new TranslatableMarkup('Notes about the SODa SCS Component.'))
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
      ->setDescription(new TranslatableMarkup('The owner of the SODa SCS Component.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 40,
      ])
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 40,
      ]);

    $fields['partOfProjects'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Project'))
      ->setDescription(new TranslatableMarkup('The project this component belongs to.'))
      ->setSetting('target_type', 'soda_scs_project')
      ->setSetting('handler', 'soda_scs_project_access')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRequired(FALSE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 45,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 5,
      ]);

    // @todo Implement the reuse of dangling components
    $fields['connectedComponents'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Connected Components'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 30,
      ]);

    $fields['serviceKey'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Service Key'))
      ->setDescription(new TranslatableMarkup('The service key associated with this component.'))
      ->setSetting('target_type', 'soda_scs_service_key')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_entity_view',
        'weight' => 60,
      ]);

    $fields['status'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Status'))
      ->setDescription(new TranslatableMarkup('The status of the SODa SCS Component.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'boolean',
        'weight' => 30,
      ]);

    $fields['updated'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Updated'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Component was last updated.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 70,
      ]);

    return $fields;
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
    return \Drupal::currentUser()->hasPermission('administer sodasc components');

  }

}
