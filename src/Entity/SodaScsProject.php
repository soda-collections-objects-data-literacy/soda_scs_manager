<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * SODa SCS Project.
 *
 * @ContentEntityType(
 *   id = "soda_scs_project",
 *   label = @Translation("SODa SCS Project"),
 *   label_collection = @Translation("SODa SCS Projects"),
 *   label_singular = @Translation("SODa SCS Project"),
 *   label_plural = @Translation("SODa SCS Projects"),
 *   label_count = @PluralTranslation(
 *     singular = "@count SODa SCS Project",
 *     plural = "@count SODa SCS Projects",
 *   ),
 *   handlers = {
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsProjectListBuilder",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsProjectForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsProjectCreateForm",
 *       "edit" = "Drupal\soda_scs_manager\Form\SodaScsProjectEditForm",
 *       "delete" = "Drupal\soda_scs_manager\Form\SodaScsProjectDeleteForm",
 *     },
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "soda_scs_project",
 *   data_table = "soda_scs_project_field_data",
 *   admin_permission = "administer soda scs project entities",
 *   field_ui_base_route = "entity.soda_scs_project.edit_form",
 *   common_reference_target = TRUE,
 *   fieldable = TRUE,
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
 *     "canonical" = "/soda-scs-manager/project/{soda_scs_project}",
 *     "add-form" = "/soda-scs-manager/project/add/{bundle}",
 *     "edit-form" = "/soda-scs-manager/project/{soda_scs_project}/edit",
 *     "delete-form" = "/soda-scs-manager/project/{soda_scs_project}/delete",
 *     "collection" = "/soda-scs-manager/project/list",
 *   },
 *   config_export = {
 *     "bundle",
 *     "created",
 *     "description",
 *     "id",
 *     "label",
 *     "langcode",
 *     "machineName",
 *     "uuid",
 *     "updated",
 *     "owner",
 *     "members",
 *     "rights",
 *     "scsComponent",
 *   }
 * )
 */
class SodaScsProject extends ContentEntityBase implements EntityInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Project was created.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['description'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Description'))
      ->setDescription(new TranslatableMarkup('The description of the project.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The label of the project.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
        'settings' => [
          'size' => 30,
        ],
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
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
        'settings' => [
          'size' => 30,
        ],
        // Make the field read-only in the form display.
        'third_party_settings' => [
          'readonly' => TRUE,
        ],
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'inline',
        'type' => 'string',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      // Add constraint to ensure machine name format is valid.
      ->addConstraint('RegexPattern', [
        'pattern' => '/^[a-z0-9_]+$/',
        'message' => t('Machine name must contain only lowercase letters, numbers, and underscores.'),
      ]);

    $fields['members'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Members'))
      ->setDescription(new TranslatableMarkup('The members associated with the project.'))
      ->setSetting('target_type', 'user')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The owner of the SODa SCS Project.'))
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

    $fields['rights'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Rights'))
      ->setDescription(new TranslatableMarkup('The rights associated with the project.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['scsComponent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('SCS Components'))
      ->setDescription(new TranslatableMarkup('The SCS components associated with this project.'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setComputed(TRUE)
      ->setClass('\Drupal\soda_scs_manager\ComputedField\ScsComponentsReferenceField')
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 40,
      ]);

    $fields['updated'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Updated'))
      ->setDescription(new TranslatableMarkup('The time that the SODa SCS Project was last updated.'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('view', FALSE);

    return $fields;
  }

}
