<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\UserInterface;

/**
 * A conversation between one user and the admin team.
 */
interface SodaScsConversationInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Cache tag of every conversation, for the admins' badge and list.
   */
  public const TEAM_CACHE_TAG = 'soda_scs_conversation_team';

  /**
   * Time of the last message, for sorting.
   */
  public function getLastMessageTime(): int;

  /**
   * The user participant.
   */
  public function getParticipant(): ?UserInterface;

  /**
   * Id of the user participant, also when the account is gone.
   */
  public function getParticipantId(): int;

  /**
   * Whether the participant has a message they have not read.
   */
  public function isUnreadForParticipant(): bool;

  /**
   * Whether the admin team has a message of the participant it has not read.
   */
  public function isUnreadForTeam(): bool;

  /**
   * Records a new message as the last one of the conversation.
   *
   * The side that did not write it has something unread; the author's side
   * has read everything.
   */
  public function setLastMessage(SodaScsConversationMessageInterface $message): static;

  /**
   * Marks the conversation read for one side.
   *
   * @param bool $forTeam
   *   TRUE for the admin team's marker, FALSE for the participant's.
   */
  public function markRead(bool $forTeam): static;

}
