<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\UserInterface;

/**
 * Interface for in-app message entities.
 *
 * One row is one inbox copy for a single recipient. Future person-to-person or
 * group messages can reuse the same entity (fan-out one copy per recipient)
 * and a new message type plugin for actions.
 */
interface SodaScsMessageInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Project invitation message type.
   */
  public const TYPE_PROJECT_INVITATION = 'project_invitation';

  /**
   * Get the creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Get the recipient user.
   */
  public function getRecipient(): UserInterface|null;

  /**
   * Get the related entity ID, if any.
   */
  public function getRelatedEntityId(): int|null;

  /**
   * Get the related entity type ID, if any.
   */
  public function getRelatedEntityType(): string|null;

  /**
   * Get the sender user.
   */
  public function getSender(): UserInterface|null;

  /**
   * Get the stored subject (entity label).
   */
  public function getSubject(): string;

  /**
   * Get the message type plugin ID.
   */
  public function getType(): string;

  /**
   * Whether the recipient has opened the message.
   */
  public function isRead(): bool;

  /**
   * Mark the message as read or unread.
   */
  public function setRead(bool $isRead): static;

}
