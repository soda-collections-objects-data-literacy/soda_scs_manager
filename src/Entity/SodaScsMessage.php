<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\UserInterface;

/**
 * Defines the SODa SCS in-app message entity.
 *
 * @ContentEntityType(
 *   id = "soda_scs_message",
 *   label = @Translation("Message"),
 *   label_collection = @Translation("Messages"),
 *   label_singular = @Translation("message"),
 *   label_plural = @Translation("messages"),
 *   label_count = @PluralTranslation(
 *     singular = "@count message",
 *     plural = "@count messages",
 *   ),
 *   handlers = {
 *     "view_builder" = "Drupal\Core\Entity\EntityViewBuilder",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\soda_scs_manager\Access\SodaScsMessageAccessControlHandler",
 *   },
 *   base_table = "soda_scs_message",
 *   data_table = "soda_scs_message_field_data",
 *   admin_permission = "soda scs manager admin",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label",
 *     "uuid" = "uuid",
 *   },
 *   links = {
 *     "canonical" = "/soda-scs-manager/messages/{soda_scs_message}",
 *   }
 * )
 */
final class SodaScsMessage extends ContentEntityBase implements SodaScsMessageInterface {

  use EntityChangedTrait;
  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject'))
      ->setDescription(new TranslatableMarkup('Stored subject used as fallback when the message type cannot build one.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['type'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Type'))
      ->setDescription(new TranslatableMarkup('Message type plugin ID (for example project_invitation). Unknown types fall back to stored subject and body.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDefaultValue(self::TYPE_PROJECT_INVITATION);

    $fields['sender'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Sender'))
      ->setDescription(new TranslatableMarkup('The user who sent the message.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setCardinality(1);

    $fields['recipient'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Recipient'))
      ->setDescription(new TranslatableMarkup('The inbox owner for this copy of the message.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE)
      ->setCardinality(1);

    $fields['body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Body'))
      ->setDescription(new TranslatableMarkup('Optional stored body. Type plugins may replace this at display time.'))
      ->setRequired(FALSE);

    $fields['relatedEntityType'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Related entity type'))
      ->setDescription(new TranslatableMarkup('Optional entity type this message is about (for example a project invitation).'))
      ->setRequired(FALSE)
      ->setSetting('max_length', 64);

    $fields['relatedEntityId'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Related entity ID'))
      ->setDescription(new TranslatableMarkup('Optional ID of the related entity.'))
      ->setRequired(FALSE);

    $fields['isRead'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Read'))
      ->setDescription(new TranslatableMarkup('Whether the recipient has opened the message.'))
      ->setDefaultValue(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'))
      ->setDescription(new TranslatableMarkup('The time the message was created.'))
      ->setTranslatable(FALSE);

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'))
      ->setDescription(new TranslatableMarkup('The time the message was last updated.'))
      ->setTranslatable(FALSE);

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    $tags = parent::getCacheTagsToInvalidate();
    $recipientId = $this->get('recipient')->target_id;
    if ($recipientId) {
      $tags[] = 'soda_scs_message_list:' . $recipientId;
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getCreatedTime(): int {
    return (int) $this->get('created')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRecipient(): UserInterface|null {
    $recipient = $this->get('recipient')->entity;
    return $recipient instanceof UserInterface ? $recipient : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelatedEntityId(): int|null {
    $value = $this->get('relatedEntityId')->value;
    return $value === NULL ? NULL : (int) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getRelatedEntityType(): string|null {
    $value = $this->get('relatedEntityType')->value;
    return $value === NULL || $value === '' ? NULL : (string) $value;
  }

  /**
   * {@inheritdoc}
   */
  public function getSender(): UserInterface|null {
    $sender = $this->get('sender')->entity;
    return $sender instanceof UserInterface ? $sender : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getSubject(): string {
    return (string) $this->get('label')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getType(): string {
    return (string) $this->get('type')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isRead(): bool {
    return (bool) $this->get('isRead')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage): void {
    parent::preSave($storage);

    $label = $this->get('label')->value;
    if ($label === NULL || $label === '') {
      $this->set('label', (string) $this->t('Message'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setRead(bool $isRead): static {
    $this->set('isRead', $isRead);
    return $this;
  }

}
