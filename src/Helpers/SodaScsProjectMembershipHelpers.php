<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsMessageHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper class for project membership operations.
 */
final class SodaScsProjectMembershipHelpers {

  use StringTranslationTrait;

  /**
   * The request storage ID.
   */
  private const REQUEST_ENTITY_TYPE = 'soda_scs_project_membership';

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The project helper service.
   */
  private SodaScsProjectHelpers $projectHelpers;

  /**
   * Project DB access helpers (MariaDB/phpMyAdmin SSO for project members).
   */
  private SodaScsProjectDbAccessHelpers $projectDbAccessHelpers;

  /**
   * WebProtégé ontology project helper.
   */
  private SodaScsWebprotegeHelpers $webprotegeHelpers;

  /**
   * The logger service.
   */
  private LoggerChannelInterface $logger;

  /**
   * The language manager.
   */
  private LanguageManagerInterface $languageManager;

  /**
   * The mail manager.
   */
  private MailManagerInterface $mailManager;

  /**
   * The time service.
   */
  private TimeInterface $time;

  /**
   * In-app message helpers.
   */
  private SodaScsMessageHelpers $messageHelpers;

  /**
   * Constructs the manager.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'language_manager')]
    LanguageManagerInterface $languageManager,
    #[Autowire(service: 'logger.factory')]
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'plugin.manager.mail')]
    MailManagerInterface $mailManager,
    #[Autowire(service: 'soda_scs_manager.message.helpers')]
    SodaScsMessageHelpers $messageHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.project_db_access.helpers')]
    SodaScsProjectDbAccessHelpers $projectDbAccessHelpers,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->languageManager = $languageManager;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->mailManager = $mailManager;
    $this->messageHelpers = $messageHelpers;
    $this->projectHelpers = $projectHelpers;
    $this->projectDbAccessHelpers = $projectDbAccessHelpers;
    $this->webprotegeHelpers = $webprotegeHelpers;
    $this->time = $time;
  }

  /**
   * Create invitations for the provided recipients.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project to invite to.
   * @param \Drupal\user\UserInterface $requester
   *   The user sending the invitation.
   * @param \Drupal\user\UserInterface[] $recipients
   *   The users that should receive an invitation.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the operation.
   */
  public function inviteUsers(SodaScsProjectInterface $project, UserInterface $requester, array $recipients): SodaScsResult {
    $requestStorage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);
    $created = [];
    $skipped = [];

    foreach ($recipients as $recipient) {
      if (!$recipient instanceof UserInterface) {
        continue;
      }

      if ($recipient->id() === $project->getOwnerId()) {
        $skipped[] = $recipient->getDisplayName();
        continue;
      }

      if ($requester->id() === $recipient->id()) {
        $skipped[] = $recipient->getDisplayName();
        continue;
      }

      if ($this->isMemberOfProject($project, $recipient)) {
        $skipped[] = $recipient->getDisplayName();
        continue;
      }

      if ($this->hasPendingRequest($project, $recipient)) {
        $skipped[] = $recipient->getDisplayName();
        continue;
      }

      $request = $requestStorage->create([
        'project' => $project->id(),
        'requester' => $requester->id(),
        'recipient' => $recipient->id(),
        'status' => SodaScsProjectMembershipInterface::STATUS_PENDING,
      ]);
      $request->save();
      $this->messageHelpers->createFromProjectInvitation($request);
      $created[] = $recipient->getDisplayName();

      $this->sendInvitationEmail($project, $requester, $recipient);
    }

    if (empty($created)) {
      return SodaScsResult::failure(
        error: 'No invitations created',
        message: (string) $this->t('No invitations were created. Existing members or pending requests were skipped.'),
      );
    }

    $message = (string) $this->t('Sent membership invitations to: @users.', [
      '@users' => implode(', ', $created),
    ]);

    if (!empty($skipped)) {
      $message .= ' ' . (string) $this->t('Skipped: @skipped.', [
        '@skipped' => implode(', ', $skipped),
      ]);
    }

    return SodaScsResult::success(
      data: [
        'created' => $created,
        'skipped' => $skipped,
      ],
      message: $message,
    );
  }

  /**
   * Accept a membership request.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface $request
   *   The request entity.
   * @param \Drupal\user\UserInterface $actor
   *   The user performing the action.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The operation result.
   */
  public function approveRequest(SodaScsProjectMembershipInterface $request, UserInterface $actor): SodaScsResult {
    if ($request->getRecipient()->id() !== $actor->id()) {
      return SodaScsResult::failure(
        error: 'not_allowed',
        message: (string) $this->t('Only the invited user can accept this request.'),
      );
    }

    if (!$request->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING)) {
      return SodaScsResult::failure(
        error: 'invalid_state',
        message: (string) $this->t('This membership request is no longer pending.'),
      );
    }

    $project = $request->getProject();
    if ($project === NULL) {
      try {
        $request->delete();
      }
      catch (\Throwable) {
        // Best-effort cleanup of orphaned request records.
      }

      return SodaScsResult::failure(
        error: 'missing_project',
        message: (string) $this->t('This invitation is no longer valid because the project was removed.'),
      );
    }

    $recipient = $request->getRecipient();

    try {
      if (!$this->isMemberOfProject($project, $recipient)) {
        $project->get('members')->appendItem($recipient->id());
        $project->save();
        $this->projectHelpers->syncKeycloakGroupMembers($project);
        $this->projectDbAccessHelpers->syncProjectMembersDbAccess($project);
        $this->webprotegeHelpers->grantEdit($project, $recipient);
      }

      $request->setStatus(SodaScsProjectMembershipInterface::STATUS_ACCEPTED);
      $request->setDecisionTime($this->time->getRequestTime());
      $request->save();
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Failed to accept project membership request: @message', [
        '@message' => $throwable->getMessage(),
      ]);

      return SodaScsResult::failure(
        error: 'storage_error',
        message: (string) $this->t('Failed to accept the membership request. Please try again.'),
      );
    }

    return SodaScsResult::success(
      data: ['request' => $request->id()],
      message: (string) $this->t('You are now a member of the project @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Reject a membership request.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface $request
   *   The request entity.
   * @param \Drupal\user\UserInterface $actor
   *   The acting user.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The operation result.
   */
  public function rejectRequest(SodaScsProjectMembershipInterface $request, UserInterface $actor): SodaScsResult {
    if ($request->getRecipient()->id() !== $actor->id()) {
      return SodaScsResult::failure(
        error: 'not_allowed',
        message: (string) $this->t('Only the invited user can reject this request.'),
      );
    }

    if (!$request->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING)) {
      return SodaScsResult::failure(
        error: 'invalid_state',
        message: (string) $this->t('This membership request is no longer pending.'),
      );
    }

    try {
      $request->setStatus(SodaScsProjectMembershipInterface::STATUS_REJECTED);
      $request->setDecisionTime($this->time->getRequestTime());
      $request->save();
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Failed to reject project membership request: @message', [
        '@message' => $throwable->getMessage(),
      ]);

      return SodaScsResult::failure(
        error: 'storage_error',
        message: (string) $this->t('Failed to reject the membership request. Please try again.'),
      );
    }

    $project = $request->getProject();
    $projectLabel = $project !== NULL ? $project->label() : (string) $this->t('Unknown project');

    return SodaScsResult::success(
      data: ['request' => $request->id()],
      message: (string) $this->t('Invitation for project @project has been rejected.', [
        '@project' => $projectLabel,
      ]),
    );
  }

  /**
   * Remove orphaned membership requests and return those with a valid project.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[] $requests
   *   Membership requests keyed by entity ID.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[]
   *   Requests whose project entity still exists.
   */
  public function purgeOrphanedAndFilterRequests(array $requests): array {
    $valid = [];

    foreach ($requests as $key => $request) {
      if (!$request instanceof SodaScsProjectMembershipInterface) {
        continue;
      }

      if ($request->getProject() === NULL) {
        try {
          $request->delete();
        }
        catch (\Throwable $throwable) {
          $this->logger->warning('Could not delete orphaned project membership @id: @message', [
            '@id' => $request->id(),
            '@message' => $throwable->getMessage(),
          ]);
        }
        continue;
      }

      $valid[$key] = $request;
    }

    return $valid;
  }

  /**
   * Delete all membership requests linked to a project.
   */
  public function deleteRequestsForProject(SodaScsProjectInterface $project): void {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);
    $ids = $storage->getQuery()
      ->condition('project', $project->id())
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return;
    }

    $requests = $storage->loadMultiple($ids);
    $storage->delete($requests);
  }

  /**
   * Send an invitation email to the recipient.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project the recipient is invited to.
   * @param \Drupal\user\UserInterface $requester
   *   The user who sent the invitation.
   * @param \Drupal\user\UserInterface $recipient
   *   The user receiving the invitation.
   */
  private function sendInvitationEmail(SodaScsProjectInterface $project, UserInterface $requester, UserInterface $recipient): void {
    $recipientEmail = $recipient->getEmail();
    if (empty($recipientEmail)) {
      return;
    }

    $langcode = $recipient->getPreferredLangcode() ?: $this->languageManager->getDefaultLanguage()->getId();
    $messagesUrl = Url::fromRoute('soda_scs_manager.messages')
      ->setAbsolute()
      ->toString();

    $params = [
      'messages_url'    => $messagesUrl,
      'project_label'   => $project->label(),
      'recipient_name'  => $recipient->getDisplayName(),
      'requester_name'  => $requester->getDisplayName(),
    ];

    $result = $this->mailManager->mail(
      'soda_scs_manager',
      'project_membership_invitation',
      $recipientEmail,
      $langcode,
      $params,
    );

    if (!$result['result']) {
      $this->logger->error('Failed to send invitation email to @email for project @project.', [
        '@email'   => $recipientEmail,
        '@project' => $project->label(),
      ]);
    }
  }

  /**
   * Whether the account may invite members to the project.
   *
   * Owners and SCS admins can add members. Regular members cannot.
   */
  public function canAddMembers(SodaScsProjectInterface $project, AccountInterface $actor): bool {
    if ($actor->isAnonymous()) {
      return FALSE;
    }
    if ($actor->hasPermission('soda scs manager admin')) {
      return TRUE;
    }

    return (int) $project->getOwnerId() === (int) $actor->id();
  }

  /**
   * Whether the account may remove this listed member from the project.
   *
   * Admins and the project owner may remove any listed member. A member may
   * only remove themselves. The owner cannot be removed from the members
   * field this way.
   */
  public function canRemoveMember(SodaScsProjectInterface $project, AccountInterface $actor, UserInterface $member): bool {
    if ($actor->isAnonymous()) {
      return FALSE;
    }

    $memberId = (int) $member->id();
    if ($memberId <= 0 || !$this->isListedMember($project, $memberId)) {
      return FALSE;
    }
    if ((int) $project->getOwnerId() === $memberId) {
      return FALSE;
    }
    if ($actor->hasPermission('soda scs manager admin')) {
      return TRUE;
    }
    if ((int) $actor->id() === $memberId) {
      return TRUE;
    }

    return (int) $project->getOwnerId() === (int) $actor->id();
  }

  /**
   * Whether the user id is in the project's members field (not the owner).
   */
  public function isListedMember(SodaScsProjectInterface $project, int $userId): bool {
    if ($userId <= 0 || !$project->hasField('members')) {
      return FALSE;
    }
    foreach ($project->get('members')->getValue() ?? [] as $item) {
      if ((int) ($item['target_id'] ?? 0) === $userId) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Remove a listed member from the project and sync related services.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The project.
   * @param \Drupal\user\UserInterface $member
   *   The member to remove.
   * @param \Drupal\Core\Session\AccountInterface $actor
   *   The user performing the removal.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The operation result.
   */
  public function removeMember(SodaScsProjectInterface $project, UserInterface $member, AccountInterface $actor): SodaScsResult {
    if (!$this->canRemoveMember($project, $actor, $member)) {
      return SodaScsResult::failure(
        error: 'not_allowed',
        message: (string) $this->t('You are not allowed to remove this member.'),
        log: FALSE,
      );
    }

    $memberId = (int) $member->id();
    $this->projectDbAccessHelpers->revokeProjectMemberDbAccess($member, $project);
    $this->webprotegeHelpers->revokeAccess($project, $member);

    $updatedMembers = array_values(array_filter(
      $project->get('members')->getValue(),
      static fn(array $item): bool => (int) ($item['target_id'] ?? 0) !== $memberId,
    ));
    $project->set('members', $updatedMembers);

    try {
      $project->save();
      $this->projectHelpers->syncKeycloakGroupMembers($project);
    }
    catch (\Throwable $throwable) {
      $this->logger->error('Failed to remove member from project: @message', [
        '@message' => $throwable->getMessage(),
      ]);

      return SodaScsResult::failure(
        error: 'storage_error',
        message: (string) $this->t('Failed to remove the member. Please try again.'),
        log: FALSE,
      );
    }

    $isSelf = (int) $actor->id() === $memberId;
    $message = $isSelf
      ? (string) $this->t('You have left the project @project.', [
        '@project' => $project->label(),
      ])
      : (string) $this->t('@member has been removed from the project.', [
        '@member' => $member->getDisplayName(),
      ]);

    return SodaScsResult::success(
      data: ['member' => $memberId],
      message: $message,
    );
  }

  /**
   * Check whether the user is already a member of the project.
   */
  public function isMemberOfProject(SodaScsProjectInterface $project, UserInterface $user): bool {
    if ($this->isListedMember($project, (int) $user->id())) {
      return TRUE;
    }

    return (int) $project->getOwnerId() === (int) $user->id();
  }

  /**
   * Check whether a pending request already exists.
   */
  private function hasPendingRequest(SodaScsProjectInterface $project, UserInterface $recipient): bool {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);
    $ids = $storage->getQuery()
      ->condition('project', $project->id())
      ->condition('recipient', $recipient->id())
      ->condition('status', SodaScsProjectMembershipInterface::STATUS_PENDING)
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return FALSE;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[] $requests */
    $requests = $storage->loadMultiple($ids);
    $valid = $this->purgeOrphanedAndFilterRequests($requests);

    return !empty($valid);
  }

}
