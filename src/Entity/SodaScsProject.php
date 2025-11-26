<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\ComputedField\SodaScsGroupIdComputedItemList;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;

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
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsProjectAccessControlHandler",
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
 *   translatable = TRUE,
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
 *     "collection" = "/admin/structure/soda-scs-project/list",
 *   },
 *   config_export = {
 *     "bundle",
 *     "connectedComponents",
 *     "created",
 *     "description",
 *     "id",
 *     "groupId",
 *     "keycloakUuid",
 *     "label",
 *     "langcode",
 *     "machineName",
 *     "members",
 *     "updated",
 *     "owner",
 *     "rights",
 *   }
 * )
 */
class SodaScsProject extends ContentEntityBase implements SodaScsProjectInterface {

  use EntityOwnerTrait;

  /**
   * Starting value for group ID.
   */
  const GROUP_ID_START = 10000;

  /**
   * Get the default project for a user.
   *
   * @param int $ownerId
   *   The owner ID.
   *   The user.
   *
   * @return array[SodaScsProject]|null
   *   The default project for the user.
   */
  public static function loadByOwner($ownerId) {
    $query = \Drupal::entityQuery('soda_scs_project')
      ->condition('owner', $ownerId)
      ->accessCheck(FALSE);
    $result = $query->execute();
    return self::loadMultiple($result);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {

    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['connectedComponents'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Connected applications'))
      ->setDescription(new TranslatableMarkup('The applications associated with this project.'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setSetting('handler', 'soda_scs_component_access')
      ->setSetting('handler_settings', [
        'auto_create' => FALSE,
        'sort' => [
          'field' => 'label',
          'direction' => 'ASC',
        ],
      ])
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'soda_scs_component_nested_fieldset',
        'weight' => 40,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 40,
      ]);

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

    $fields['groupId'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Permission Group ID'))
      ->setDescription(new TranslatableMarkup('The permission group ID associated with the project.'))
      ->setComputed(TRUE)
      ->setClass(SodaScsGroupIdComputedItemList::class)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'integer',
        'weight' => 10,
      ]);

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

    $fields['keycloakUuid'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Keycloak UUID'))
      ->setDescription(new TranslatableMarkup('The Keycloak UUID of the project group.'))
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
      ]);

    $fields['members'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Members'))
      ->setDescription(new TranslatableMarkup('The members associated with the project.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 30,
        'settings' => [
          'link' => FALSE,
        ],
      ]);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The owner of the SODa SCS Project.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 30,
      ]);

    $fields['rights'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Rights'))
      ->setDescription(new TranslatableMarkup('The rights associated with the project.'))
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', FALSE);

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
