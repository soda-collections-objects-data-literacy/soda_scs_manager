<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;
use Drupal\user\UserInterface;

/**
 * Defines the ComponentCredentials entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_service_key",
 *   label = @Translation("Service Key"),
 *   label_collection = @Translation("Service Keys"),
 *   label_singular = @Translation("Service Key"),
 *   label_plural = @Translation("Service Keys"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Service Key",
 *     plural = "@count Service Keys",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsServiceKeyListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsServiceKeyCreateForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\soda_scs_manager\Form\SodaScsServiceKeyEditForm",
 *       "delete" = "Drupal\soda_scs_manager\Form\SodaScsServiceKeyDeleteForm",
 *     },
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsServiceKeyAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\DefaultHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/service-key/{soda_scs_service_key}",
 *     "add-form" = "/soda-scs-manager/service-key/add",
 *     "edit-form" = "/soda-scs-manager/service-key/{soda_scs_service_key}/edit",
 *     "delete-form" = "/soda-scs-manager/service-key/{soda_scs_service_key}/delete",
 *     "collection" = "/soda-scs-manager/service-key/list",
 *   },
 *   base_table = "soda_scs_service_key",
 *   data_table = "soda_scs_service_key_field_data",
 *   admin_permission = "administer soda scs service key entities",
 *   field_ui_base_route = "entity.soda_scs_service_key.edit_form",
 *   fieldable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "owner",
 *     "uid" = "owner",
 *     "langcode" = "langcode",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *     "scsComponent",
 *     "scsComponentBundle",
 *     "owner",
 *     "langcode",
 *     "type",
 * 
 *   }
 * )
 */
class SodaScsServiceKey extends ContentEntityBase implements SodaScsServiceKeyInterface {

  use EntityOwnerTrait;
  /**
   * The entity relation to the Soda SCS Component.
   *
   * @var \Drupal\Core\Entity\ContentEntityInterface
   */
  protected $component;

  /**
   * Get the Soda SCS Component.
   */
  public function getComponent() {
    return $this->get('scsComponent')->entity;
  }

  /**
   * Set the Soda SCS Component.
   */
  public function setComponent($component) {
    $this->set('scsComponent', $component);
  }

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);



    $fields['scsComponent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('SODa SCS Component'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    $fields['scsComponentBundle'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('SODa SCS Component Bundle'))
      ->setDescription(new TranslatableMarkup('The bundle of the SODa SCS Component.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['servicePassword'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Password'))
      ->setDescription(new TranslatableMarkup('The password of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
        'css_class' => 'soda-scs-manager-service-password',
        
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setDescription(new TranslatableMarkup('The type of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('form', FALSE);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    return $fields;

  }

}
