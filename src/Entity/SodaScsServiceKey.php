<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Field\FieldStorageDefinitionInterface;

/**
 * Defines the ComponentCredentials entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_service_key",
 *   label = @Translation("Service Key"),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
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
 *   base_table = "soda_scs_service_key",
 *   data_table = "soda_scs_service_key_field_data",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *   },
 *   config_export = {
 *     "id",
 *     "label",
 *     "uuid",
 *   }
 * )
 */

class SodaScsServiceKey extends ContentEntityBase {

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
        return $this->get('component')->entity;
    }

    /**
     * Set the Soda SCS Component.
     */
    public function setComponent($component) {
        $this->set('component', $component);
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
      ->setDisplayConfigurable('view', TRUE);

    $fields['id'] = BaseFieldDefinition::create('integer')
    ->setLabel(new TranslatableMarkup('ID'))
    ->setDescription(new TranslatableMarkup('The ID of the SCS component entity.'))
    ->setReadOnly(TRUE)
    ->setDisplayConfigurable('view', TRUE);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The name of the service key.'))
      ->setRequired(TRUE)
      ->setTranslatable(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'hidden',
        'type' => 'string',
        'weight' => 0,
      ]);
    
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
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayConfigurable('form', TRUE);

    $fields['servicePassword'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Password'))
      ->setDescription(new TranslatableMarkup('The password of the service key.'))
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE)
      ->setSettings([
        'max_length' => 255,
        'text_processing' => 0,
      ])
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE);

      $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setCardinality(1)
      ->setRequired(TRUE)
      ->setReadOnly(TRUE);

      $fields['uuid'] = BaseFieldDefinition::create('uuid')
      ->setLabel(new TranslatableMarkup('UUID'))
      ->setDescription(new TranslatableMarkup('The UUID of the SODa SCS Component entity.'))
      ->setReadOnly(TRUE);

      return $fields;

  }
}