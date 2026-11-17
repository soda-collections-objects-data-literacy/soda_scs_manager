<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Access\SodaScsConversationMessageAccessControlHandler;
use Drupal\user\UserInterface;

/**
 * One message of a conversation.
 *
 * A deleted message keeps its row with an empty body and deletedAt set, so
 * the thread shows a placeholder in its place.
 */
#[ContentEntityType(
  id: 'soda_scs_conversation_message',
  label: new TranslatableMarkup('Conversation message'),
  label_collection: new TranslatableMarkup('Conversation messages'),
  label_singular: new TranslatableMarkup('conversation message'),
  label_plural: new TranslatableMarkup('conversation messages'),
  entity_keys: [
    'id' => 'id',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => SodaScsConversationMessageAccessControlHandler::class,
  ],
  admin_permission: 'soda scs manager admin',
  base_table: 'soda_scs_conversation_message',
  label_count: [
    'singular' => '@count conversation message',
    'plural' => '@count conversation messages',
  ],
)]
final class SodaScsConversationMessage extends ContentEntityBase implements SodaScsConversationMessageInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['conversation'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Conversation'))
      ->setSetting('target_type', 'soda_scs_conversation')
      ->setRequired(TRUE);

    $fields['author'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Author'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['body'] = BaseFieldDefinition::create('string_long')
      ->setLabel(new TranslatableMarkup('Text'))
      ->setDescription(new TranslatableMarkup('Plain text; empty once the message is deleted.'));

    $fields['editedAt'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Edited'));

    $fields['deletedAt'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Deleted'));

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthor(): ?UserInterface {
    $author = $this->get('author')->entity;
    return $author instanceof UserInterface ? $author : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAuthorId(): int {
    return (int) $this->get('author')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function getBody(): string {
    return (string) $this->get('body')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    $tags = parent::getCacheTagsToInvalidate();
    $conversation = $this->getConversation();
    if ($conversation !== NULL) {
      $tags = Cache::mergeTags($tags, $conversation->getCacheTagsToInvalidate());
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getConversation(): ?SodaScsConversationInterface {
    $conversation = $this->get('conversation')->entity;
    return $conversation instanceof SodaScsConversationInterface ? $conversation : NULL;
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
  public function isDeleted(): bool {
    return !$this->get('deletedAt')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function isEdited(): bool {
    return !$this->get('editedAt')->isEmpty();
  }

  /**
   * {@inheritdoc}
   */
  public function markDeleted(int $time): static {
    $this->set('body', '');
    $this->set('deletedAt', $time);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE): void {
    parent::postSave($storage, $update);
    if ($update) {
      return;
    }
    $conversation = $this->getConversation();
    if ($conversation !== NULL) {
      $conversation->setLastMessage($this)->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setEditedBody(string $body, int $time): static {
    $this->set('body', $body);
    $this->set('editedAt', $time);
    return $this;
  }

}
