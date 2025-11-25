<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the ComponentCredentials entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_service_key",
 *   label = @Translation("Soda SCS Service Key"),
 *   label_collection = @Translation("Soda SCS Service Keys"),
 *   label_singular = @Translation("Soda SCS Service Key"),
 *   label_plural = @Translation("Soda SCS Service Keys"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Soda SCS Service Key",
 *     plural = "@count Soda SCS Service Keys",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsServiceKeyListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsServiceKeyEditForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsServiceKeyCreateForm",
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
 *     "add-form" = "/soda-scs-manager/service-key/add/{bundle}",
 *     "edit-form" = "/soda-scs-manager/service-key/{soda_scs_service_key}/edit",
 *     "delete-form" = "/soda-scs-manager/service-key/{soda_scs_service_key}/delete",
 *     "collection" = "/admin/structure/soda-scs-service-key/list",
 *   },
 *   base_table = "soda_scs_service_key",
 *   data_table = "soda_scs_service_key_field_data",
 *   admin_permission = "administer soda scs service key entities",
 *   field_ui_base_route = "entity.soda_scs_service_key.edit_form",
 *   fieldable = TRUE,
 *   translatable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "bundle" = "bundle",
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "owner",
 *     "uid" = "owner",
 *     "langcode" = "langcode",
 *   },
 *   config_export = {
 *     "bundle",
 *     "id",
 *     "label",
 *     "uuid",
 *     "scsComponent",
 *     "scsComponentBundle",
 *     "owner",
 *     "langcode",
 *     "type",
 *   }
 * )
 */
class SodaScsServiceKey extends ContentEntityBase implements SodaScsServiceKeyInterface {

  use EntityOwnerTrait;

  /**
   * Load service keys by owner.
   *
   * @param int $ownerId
   *   The owner ID.
   *
   * @return array
   *   The service keys.
   */
  public static function loadByOwner($ownerId) {
    $query = \Drupal::entityQuery('soda_scs_service_key')
      ->condition('owner', $ownerId)
      ->accessCheck(FALSE);
    $result = $query->execute();
    return self::loadMultiple($result);
  }

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
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['scsComponent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('SODa SCS Component'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['scsComponentBundle'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('SODa SCS Component Bundle'))
      ->setDescription(new TranslatableMarkup('The bundle of the SODa SCS Component.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['servicePassword'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Password'))
      ->setDescription(new TranslatableMarkup('The password of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 10,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 10,
        'css_class' => 'soda-scs-manager-service-password',
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['type'] = BaseFieldDefinition::create('list_string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setDescription(new TranslatableMarkup('The type of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'options_buttons',
        'weight' => 10,

      ])
      ->setSetting('allowed_values', [
        'password' => 'Password',
        'token' => 'Token',
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ]);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', FALSE);

    return $fields;

  }

}
