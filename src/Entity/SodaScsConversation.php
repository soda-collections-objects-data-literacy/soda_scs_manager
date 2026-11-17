<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\Attribute\ContentEntityType;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityChangedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Access\SodaScsConversationAccessControlHandler;
use Drupal\user\UserInterface;

/**
 * A conversation between one user and the admin team.
 *
 * The admin side is a shared support inbox: every admin sees every
 * conversation, and the team shares one unread flag. A new message sets the
 * flag of the other side and clears the author's side.
 */
#[ContentEntityType(
  id: 'soda_scs_conversation',
  label: new TranslatableMarkup('Conversation'),
  label_collection: new TranslatableMarkup('Conversations'),
  label_singular: new TranslatableMarkup('conversation'),
  label_plural: new TranslatableMarkup('conversations'),
  entity_keys: [
    'id' => 'id',
    'label' => 'label',
    'uuid' => 'uuid',
  ],
  handlers: [
    'access' => SodaScsConversationAccessControlHandler::class,
  ],
  links: [
    'canonical' => '/soda-scs-manager/conversations/{soda_scs_conversation}',
  ],
  admin_permission: 'soda scs manager admin',
  base_table: 'soda_scs_conversation',
  label_count: [
    'singular' => '@count conversation',
    'plural' => '@count conversations',
  ],
)]
final class SodaScsConversation extends ContentEntityBase implements SodaScsConversationInterface {

  use EntityChangedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type): array {
    $fields = parent::baseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(new TranslatableMarkup('Subject'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255);

    $fields['user'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('User'))
      ->setDescription(new TranslatableMarkup('The user who talks with the admin team.'))
      ->setSetting('target_type', 'user')
      ->setRequired(TRUE);

    $fields['startedBy'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(new TranslatableMarkup('Started by'))
      ->setDescription(new TranslatableMarkup('The admin who started the conversation.'))
      ->setSetting('target_type', 'user');

    $fields['lastMessageId'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Last message'))
      ->setDefaultValue(0);

    $fields['lastMessageAuthor'] = BaseFieldDefinition::create('integer')
      ->setLabel(new TranslatableMarkup('Author of the last message'))
      ->setDescription(new TranslatableMarkup('User id; kept when the account is gone.'))
      ->setDefaultValue(0);

    $fields['lastMessageAt'] = BaseFieldDefinition::create('timestamp')
      ->setLabel(new TranslatableMarkup('Last activity'))
      ->setDefaultValue(0);

    $fields['userUnread'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Unread for the user'))
      ->setDefaultValue(FALSE);

    $fields['teamUnread'] = BaseFieldDefinition::create('boolean')
      ->setLabel(new TranslatableMarkup('Unread for the admin team'))
      ->setDefaultValue(FALSE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(new TranslatableMarkup('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(new TranslatableMarkup('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheTagsToInvalidate(): array {
    $tags = parent::getCacheTagsToInvalidate();
    $tags[] = self::TEAM_CACHE_TAG;
    $userId = $this->getParticipantId();
    if ($userId > 0) {
      // The inbox and the menu badge of the user use this tag.
      $tags[] = 'soda_scs_message_list:' . $userId;
    }
    return $tags;
  }

  /**
   * {@inheritdoc}
   */
  public function getLastMessageTime(): int {
    return (int) $this->get('lastMessageAt')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function getParticipant(): ?UserInterface {
    $user = $this->get('user')->entity;
    return $user instanceof UserInterface ? $user : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getParticipantId(): int {
    return (int) $this->get('user')->target_id;
  }

  /**
   * {@inheritdoc}
   */
  public function isUnreadForParticipant(): bool {
    return (bool) $this->get('userUnread')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function isUnreadForTeam(): bool {
    return (bool) $this->get('teamUnread')->value;
  }

  /**
   * {@inheritdoc}
   */
  public function markRead(bool $forTeam): static {
    $this->set($forTeam ? 'teamUnread' : 'userUnread', FALSE);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function setLastMessage(SodaScsConversationMessageInterface $message): static {
    $this->set('lastMessageId', (int) $message->id());
    $this->set('lastMessageAuthor', $message->getAuthorId());
    $this->set('lastMessageAt', $message->getCreatedTime());
    $isFromParticipant = $message->getAuthorId() === $this->getParticipantId();
    $this->set('userUnread', !$isFromParticipant);
    $this->set('teamUnread', $isFromParticipant);
    return $this;
  }

}
