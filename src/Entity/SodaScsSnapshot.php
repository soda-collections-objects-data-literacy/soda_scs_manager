<?php

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\EntityOwnerTrait;

/**
 * Defines the Soda SCS Snapshot entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_snapshot",
 *   label = @Translation("Snapshot"),
 *   label_collection = @Translation("Snapshots"),
 *   label_singular = @Translation("Snapshot"),
 *   label_plural = @Translation("Snapshots"),
 *   label_count = @PluralTranslation(
 *     singular = "@count Snapshot",
 *     plural = "@count Snapshots",
 *   ),
 *   handlers = {
 *     "storage" = "Drupal\Core\Entity\Sql\SqlContentEntityStorage",
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\soda_scs_manager\ListBuilder\SnapshotListBuilder",
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "translation" = "Drupal\content_translation\ContentTranslationHandler",
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsSnapshotAccessControlHandler",
 *     "form" = {
 *       "default" = "Drupal\soda_scs_manager\Form\SodaScsSnapshotCreateForm",
 *       "add" = "Drupal\soda_scs_manager\Form\SodaScsSnapshotCreateForm",
 *       "delete" = "Drupal\soda_scs_manager\Form\SodaScsSnapshotDeleteForm",
 *     },
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/snapshot/{soda_scs_snapshot}",
 *     "add-form" = "/soda-scs-manager/snapshot/add",
 *     "delete-form" = "/soda-scs-manager/snapshot/{soda_scs_snapshot}/delete",
 *     "collection" = "/soda-scs-manager/snapshots",
 *   },
 *   base_table = "soda_scs_snapshot",
 *   data_table = "soda_scs_snapshot_field_data",
 *   fieldable = TRUE,
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "uid" = "owner",
 *     "langcode" = "langcode",
 *     "owner" = "owner",
 *   },
 * )
 */
class SodaScsSnapshot extends ContentEntityBase implements SodaScsSnapshotInterface {

  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['snapshotOfStack'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Stack'))
      ->setDescription(new TranslatableMarkup('The stack this snapshot is taken from.'))
      ->setSetting('target_type', 'soda_scs_stack')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 10,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 10,
      ]);

    $fields['snapshotOfComponent'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Component'))
      ->setDescription(new TranslatableMarkup('The component this snapshot is taken from.'))
      ->setSetting('target_type', 'soda_scs_component')
      ->setSetting('handler', 'default')
      ->setRequired(FALSE)
      ->setCardinality(1)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 20,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 20,
      ]);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Label'))
      ->setDescription(new TranslatableMarkup('The label of the snapshot.'))
      ->setRequired(TRUE)
      ->setTranslatable(FALSE)
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => 0,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 0,
      ]);

    $fields['owner'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Owner'))
      ->setDescription(new TranslatableMarkup('The owner of the snapshot.'))
      ->setSetting('target_type', 'user')
      ->setSetting('handler', 'default')
      ->setRequired(TRUE)
      ->setDefaultValueCallback(static::class . '::getDefaultUserId')
      ->setDisplayConfigurable('form', TRUE)
      ->setDisplayOptions('form', [
        'type' => 'entity_reference_autocomplete',
        'weight' => 30,
      ])
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'entity_reference_label',
        'weight' => 30,
      ]);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time that the snapshot was created.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 40,
      ]);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time that the snapshot was last updated.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'timestamp',
        'weight' => 50,
      ]);

    $fields['checksum'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Checksum'))
      ->setDescription(new TranslatableMarkup('The checksum of the snapshot content.'))
      ->setRequired(TRUE)
      ->setDisplayConfigurable('form', FALSE)
      ->setDisplayConfigurable('view', TRUE)
      ->setDisplayOptions('view', [
        'label' => 'above',
        'type' => 'string',
        'weight' => 60,
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
   * {@inheritdoc}
   */
  public function getCreatedTime() {
    return $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setCreatedTime($timestamp) {
    $this->set('created', $timestamp);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getLabel() {
    return $this->get('label')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setLabel($label) {
    $this->set('label', $label);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMachineName() {
    return $this->get('machineName')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setMachineName($machine_name) {
    $this->set('machineName', $machine_name);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getChecksum() {
    return $this->get('checksum')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function setChecksum($checksum) {
    $this->set('checksum', $checksum);
    return $this;
  }

}
