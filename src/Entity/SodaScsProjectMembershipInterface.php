<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Entity;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityChangedInterface;
use Drupal\user\UserInterface;

/**
 * Interface for project membership request entities.
 */
interface SodaScsProjectMembershipInterface extends ContentEntityInterface, EntityChangedInterface {

  /**
   * Pending request status.
   */
  public const STATUS_PENDING = 'pending';

  /**
   * Accepted request status.
   */
  public const STATUS_ACCEPTED = 'accepted';

  /**
   * Rejected request status.
   */
  public const STATUS_REJECTED = 'rejected';

  /**
   * Get the related project entity.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface
   *   The project.
   */
  public function getProject(): SodaScsProjectInterface;

  /**
   * Get the requester user entity.
   *
   * @return \Drupal\user\UserInterface
   *   The requester.
   */
  public function getRequester(): UserInterface;

  /**
   * Get the creation timestamp.
   *
   * @return int
   *   The creation timestamp.
   */
  public function getCreatedTime(): int;

  /**
   * Get the recipient user entity.
   *
   * @return \Drupal\user\UserInterface
   *   The recipient.
   */
  public function getRecipient(): UserInterface;

  /**
   * Get the current status.
   *
   * @return string
   *   The status string.
   */
  public function getStatus(): string;

  /**
   * Set the current status.
   *
   * @param string $status
   *   The new status.
   *
   * @return $this
   *   The called object for chaining.
   */
  public function setStatus(string $status): static;

  /**
   * Get the decision timestamp.
   *
   * @return int|null
   *   The timestamp or NULL when undecided.
   */
  public function getDecisionTime(): int|null;

  /**
   * Set the decision timestamp.
   *
   * @param int $timestamp
   *   The timestamp to set.
   *
   * @return $this
   *   The called object.
   */
  public function setDecisionTime(int $timestamp): static;

  /**
   * Check if the request has the provided status.
   *
   * @param string $status
   *   The status to check.
   *
   * @return bool
   *   TRUE when the status matches.
   */
  public function hasStatus(string $status): bool;

}


