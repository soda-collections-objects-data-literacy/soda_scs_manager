<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\UserInterface;

/**
 * One message of a conversation; it exists once, for every reader.
 */
interface SodaScsConversationMessageInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * The author, or NULL when the account is gone.
   */
  public function getAuthor(): ?UserInterface;

  /**
   * Id of the author, also when the account is gone.
   */
  public function getAuthorId(): int;

  /**
   * The plain text body; empty for a deleted message.
   */
  public function getBody(): string;

  /**
   * The conversation the message belongs to.
   */
  public function getConversation(): ?SodaScsConversationInterface;

  /**
   * Time the message was written.
   */
  public function getCreatedTime(): int;

  /**
   * Whether the message was deleted; it stays as a placeholder.
   */
  public function isDeleted(): bool;

  /**
   * Whether the author changed the text after sending it.
   */
  public function isEdited(): bool;

  /**
   * Removes the text and keeps the message as a placeholder.
   */
  public function markDeleted(int $time): static;

  /**
   * Replaces the text and records the edit.
   */
  public function setEditedBody(string $body, int $time): static;

}
