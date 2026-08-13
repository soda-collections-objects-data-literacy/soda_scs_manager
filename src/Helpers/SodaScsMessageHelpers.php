<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helpers for creating and listing in-app messages.
 */
final class SodaScsMessageHelpers {

  use StringTranslationTrait;

  private const ENTITY_TYPE = 'soda_scs_message';

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Count messages that should light the inbox menu badge.
   *
   * Unread copies, plus project invitations that are still pending even if
   * already opened.
   */
  public function countAttentionItems(AccountInterface $account): int {
    $unreadIds = $this->queryRecipientMessageIds($account, unreadOnly: TRUE);
    $pendingInvitationIds = $this->queryPendingInvitationMessageIds($account);
    return count(array_unique([...$unreadIds, ...$pendingInvitationIds]));
  }

  /**
   * Create an inbox copy from a project membership invitation.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsMessageInterface|null
   *   The message, or NULL when sender or recipient is missing.
   */
  public function createFromProjectInvitation(SodaScsProjectMembershipInterface $membership): SodaScsMessageInterface|null {
    $existing = $this->loadByRelated('soda_scs_project_membership', (int) $membership->id());
    if ($existing !== NULL) {
      return $existing;
    }

    $sender = $membership->get('requester')->entity;
    $recipient = $membership->get('recipient')->entity;
    if (!$sender instanceof UserInterface || !$recipient instanceof UserInterface) {
      return NULL;
    }

    $project = $membership->getProject();
    $projectLabel = $project !== NULL ? $project->label() : (string) $this->t('a project');

    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsMessageInterface $message */
    $message = $storage->create([
      'type' => SodaScsMessageInterface::TYPE_PROJECT_INVITATION,
      'sender' => $sender->id(),
      'recipient' => $recipient->id(),
      'label' => (string) $this->t('You have been invited to the project @project', [
        '@project' => $projectLabel,
      ]),
      'body' => (string) $this->t('@sender has invited you to join the project "@project".', [
        '@sender' => $sender->getDisplayName(),
        '@project' => $projectLabel,
      ]),
      'relatedEntityType' => 'soda_scs_project_membership',
      'relatedEntityId' => $membership->id(),
      'isRead' => !$membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING),
      'created' => $membership->getCreatedTime(),
    ]);
    $message->save();

    return $message;
  }

  /**
   * Delete inbox copies that point at a related entity.
   */
  public function deleteByRelated(string $entityType, int $entityId): void {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $ids = $storage->getQuery()
      ->condition('relatedEntityType', $entityType)
      ->condition('relatedEntityId', $entityId)
      ->accessCheck(FALSE)
      ->execute();

    if ($ids === []) {
      return;
    }

    $storage->delete($storage->loadMultiple($ids));
  }

  /**
   * Load inbox messages for a user, newest first.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsMessageInterface[]
   *   Messages keyed by entity ID.
   */
  public function loadInbox(AccountInterface $account, int $limit = 50): array {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $ids = $storage->getQuery()
      ->condition('recipient', $account->id())
      ->sort('created', 'DESC')
      ->range(0, $limit)
      ->accessCheck(FALSE)
      ->execute();

    if ($ids === []) {
      return [];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsMessageInterface[] $messages */
    $messages = $storage->loadMultiple($ids);
    return $messages;
  }

  /**
   * Load the message that refers to a related entity, if any.
   */
  public function loadByRelated(string $entityType, int $entityId): SodaScsMessageInterface|null {
    $storage = $this->entityTypeManager->getStorage(self::ENTITY_TYPE);
    $ids = $storage->getQuery()
      ->condition('relatedEntityType', $entityType)
      ->condition('relatedEntityId', $entityId)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $message = $storage->load(reset($ids));
    return $message instanceof SodaScsMessageInterface ? $message : NULL;
  }

  /**
   * Mark a message as read if it is not already.
   */
  public function markRead(SodaScsMessageInterface $message): void {
    if ($message->isRead()) {
      return;
    }
    $message->setRead(TRUE);
    $message->save();
  }

  /**
   * Sender display name with a fallback.
   */
  public function resolveSenderName(SodaScsMessageInterface $message): string {
    $sender = $message->getSender();
    if ($sender instanceof UserInterface) {
      return $sender->getDisplayName();
    }
    return (string) $this->t('Unknown sender');
  }

  /**
   * Message IDs for the recipient, optionally unread only.
   *
   * @return list<string|int>
   *   Entity IDs.
   */
  private function queryRecipientMessageIds(AccountInterface $account, bool $unreadOnly): array {
    $query = $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->getQuery()
      ->condition('recipient', $account->id())
      ->accessCheck(FALSE);
    if ($unreadOnly) {
      $query->condition('isRead', 0);
    }
    return array_values($query->execute());
  }

  /**
   * Invitation messages whose membership request is still pending.
   *
   * @return list<string|int>
   *   Message entity IDs.
   */
  private function queryPendingInvitationMessageIds(AccountInterface $account): array {
    $membershipStorage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $membershipIds = $membershipStorage->getQuery()
      ->condition('recipient', $account->id())
      ->condition('status', SodaScsProjectMembershipInterface::STATUS_PENDING)
      ->accessCheck(FALSE)
      ->execute();

    if ($membershipIds === []) {
      return [];
    }

    $messageIds = $this->entityTypeManager->getStorage(self::ENTITY_TYPE)->getQuery()
      ->condition('recipient', $account->id())
      ->condition('type', SodaScsMessageInterface::TYPE_PROJECT_INVITATION)
      ->condition('relatedEntityType', 'soda_scs_project_membership')
      ->condition('relatedEntityId', array_values($membershipIds), 'IN')
      ->accessCheck(FALSE)
      ->execute();

    return array_values($messageIds);
  }

}
