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
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SodaScsServiceKeyListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\Core\Entity\ContentEntityForm",
 *       "add" = "Drupal\Core\Entity\ContentEntityForm",
 *       "edit" = "Drupal\Core\Entity\ContentEntityForm",
 *       "delete" = "Drupal\Core\Entity\ContentEntityDeleteForm",
 *     },
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
 *   field_ui_base_route = "entity.soda_scs_service_key.edit_form",
 *   fieldable = TRUE,
 *   common_reference_target = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "owner" = "user",
 *     "user_id" = "user",
 *     "langcode" = "langcode",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
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
   * Get the owner of the SODa SCS Component.
   *
   * @return \Drupal\user\Entity\User
   *   The owner of the SODa SCS Component.
   */
  public function getOwner() {
    return $this->get('user')->entity;
  }

  /**
   * Get the owner ID of the SODa SCS Component.
   *
   * @return int
   *   The owner ID of the SODa SCS Component.
   */
  public function getOwnerId() {
    return $this->get('user')->target_id;
  }

  /**
   * Set the owner of the SODa SCS Component.
   *
   * @param \Drupal\user\Entity\User $account
   *   The owner of the SODa SCS Component.
   *
   * @return $this
   */
  public function setOwner(UserInterface $account) {
    $this->set('user', $account);
    return $this;
  }

  /**
   * Set the owner ID of the SODa SCS Component.
   *
   * @param int $uid
   *   The owner ID of the SODa SCS Component.
   *
   * @return $this
   */
  public function setOwnerId($uid): self {
    $this->set('user', $this->get('user')->target_id);
    return $this;
  }

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

    $fields['bundle'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Bundle'))
      ->setSetting('target_type', 'soda_scs_component_bundle')
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['id'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('ID'))
      ->setDescription(new TranslatableMarkup('The ID of the SCS component entity.'))
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The name of the service key.'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 0,
      ]);

    $fields['langcode'] = BaseFieldDefinition::create('language')
      ->setLabel(t('Language code'))
      ->setDescription(t('The node language code.'));

    $fields['scsComponent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('SODa SCS Component'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setCardinality(FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    $fields['servicePassword'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Password'))
      ->setDescription(new TranslatableMarkup('The password of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(FALSE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', FALSE);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setDescription(new TranslatableMarkup('The type of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the SODa SCS Component entity.'))
      ->setReadOnly(TRUE)
      ->setDisplayConfigurable('view', FALSE)
      ->setDisplayConfigurable('form', FALSE);

    return $fields;

  }

}
