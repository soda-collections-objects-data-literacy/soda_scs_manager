<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
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
   * The logger service.
   */
  private LoggerChannelInterface $logger;

  /**
   * The time service.
   */
  private TimeInterface $time;

  /**
   * Constructs the manager.
   */
  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'logger.factory')]
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->projectHelpers = $projectHelpers;
    $this->logger = $loggerFactory->get('soda_scs_manager');
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
      $created[] = $recipient->getDisplayName();
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
    $recipient = $request->getRecipient();

    try {
      if (!$this->isMemberOfProject($project, $recipient)) {
        $project->get('members')->appendItem($recipient->id());
        $project->save();
        $this->projectHelpers->syncKeycloakGroupMembers($project);
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

    return SodaScsResult::success(
      data: ['request' => $request->id()],
      message: (string) $this->t('Invitation for project @project has been rejected.', [
        '@project' => $request->getProject()->label(),
      ]),
    );
  }

  /**
   * Check whether the user is already a member of the project.
   */
  private function isMemberOfProject(SodaScsProjectInterface $project, UserInterface $user): bool {
    $members = $project->get('members')->getValue() ?? [];
    foreach ($members as $item) {
      if ((int) ($item['target_id'] ?? 0) === (int) $user->id()) {
        return TRUE;
      }
    }

    return (int) $project->getOwnerId() === (int) $user->id();
  }

  /**
   * Check whether a pending request already exists.
   */
  private function hasPendingRequest(SodaScsProjectInterface $project, UserInterface $recipient): bool {
    $storage = $this->entityTypeManager->getStorage(self::REQUEST_ENTITY_TYPE);
    $query = $storage->getQuery()
      ->condition('project', $project->id())
      ->condition('recipient', $recipient->id())
      ->condition('status', SodaScsProjectMembershipInterface::STATUS_PENDING)
      ->accessCheck(FALSE);

    return !empty($query->execute());
  }

}


