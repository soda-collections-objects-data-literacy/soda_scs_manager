<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\MessageType;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Project invitation: accept or decline from the message detail view.
 */
final class SodaScsProjectInvitationMessageType implements SodaScsMessageTypeInterface {

  use StringTranslationTrait;

  public function __construct(
    #[Autowire(service: 'entity_type.manager')]
    private readonly EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    private readonly SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'soda_scs_manager.project_membership.helpers')]
    private readonly SodaScsProjectMembershipHelpers $membershipHelpers,
    #[Autowire(service: 'messenger')]
    private readonly MessengerInterface $messenger,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function buildActions(SodaScsMessageInterface $message): array {
    $membership = $this->loadMembership($message);
    if ($membership === NULL) {
      return [
        'delete' => [
          'title' => $this->t('Delete'),
          'classes' => ['btn', 'btn--secondary'],
        ],
      ];
    }

    if ($membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING)) {
      return [
        'accept' => [
          'title' => $this->t('Accept'),
          'classes' => ['btn', 'btn--primary'],
        ],
        'decline' => [
          'title' => $this->t('Decline'),
          'classes' => ['btn', 'btn--secondary'],
        ],
      ];
    }

    return [
      'delete' => [
        'title' => $this->t('Delete'),
        'classes' => ['btn', 'btn--secondary'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildBody(SodaScsMessageInterface $message): array {
    $membership = $this->loadMembership($message);
    $senderName = $message->getSender()?->getDisplayName() ?? (string) $this->t('Unknown sender');
    $project = $membership?->getProject();
    $projectLabel = $project !== NULL ? $project->label() : (string) $this->t('a project');

    $items = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['scs-manager--message-body-copy', 'space-y-3'],
      ],
      'intro' => [
        '#markup' => '<p>' . $this->t('@sender has invited you to join the project "@project".', [
          '@sender' => $senderName,
          '@project' => $projectLabel,
        ]) . '</p>',
      ],
    ];

    if ($membership === NULL) {
      $items['status'] = [
        '#markup' => '<p>' . $this->t('This invitation is no longer valid.') . '</p>',
      ];
      return $items;
    }

    if ($membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING)) {
      $items['status'] = [
        '#markup' => '<p>' . $this->t('You can accept or decline this invitation.') . '</p>',
      ];
      return $items;
    }

    if ($membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_ACCEPTED)) {
      $items['status'] = [
        '#markup' => '<p>' . $this->t('You accepted this invitation.') . '</p>',
      ];
      if ($project !== NULL) {
        $items['project'] = [
          '#type' => 'link',
          '#title' => $this->t('Open project @project', ['@project' => $projectLabel]),
          '#url' => $project->toUrl(),
          '#attributes' => [
            'class' => ['inline-block', 'mt-2'],
          ],
        ];
      }
      return $items;
    }

    if ($membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_REJECTED)) {
      $items['status'] = [
        '#markup' => '<p>' . $this->t('You declined this invitation.') . '</p>',
      ];
    }

    return $items;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSubject(SodaScsMessageInterface $message): string {
    $membership = $this->loadMembership($message);
    $project = $membership?->getProject();
    if ($project !== NULL) {
      return (string) $this->t('@sender invited you to join the Project <strong>@project</strong>', [
        '@sender' => $message->getSender()?->getDisplayName() ?? (string) $this->t('Unknown sender'),
        '@project' => $project->label(),
      ]);
    }

    $fallback = $message->getSubject();
    return $fallback !== '' ? $fallback : (string) $this->t('You have been invited to a project');
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return SodaScsMessageInterface::TYPE_PROJECT_INVITATION;
  }

  /**
   * {@inheritdoc}
   */
  public function handleAction(string $action, SodaScsMessageInterface $message, UserInterface $actor): SodaScsResult {
    return match ($action) {
      'accept' => $this->accept($message, $actor),
      'decline' => $this->decline($message, $actor),
      'delete' => $this->delete($message),
      default => SodaScsResult::failure(
        error: 'unknown_action',
        message: (string) $this->t('This action is not available for this message.'),
        log: FALSE,
      ),
    };
  }

  /**
   * Accept the linked project invitation.
   */
  private function accept(SodaScsMessageInterface $message, UserInterface $actor): SodaScsResult {
    $membership = $this->loadMembership($message);
    if ($membership === NULL) {
      return SodaScsResult::failure(
        error: 'missing_invitation',
        message: (string) $this->t('This invitation is no longer valid.'),
        log: FALSE,
      );
    }

    $result = $this->membershipHelpers->approveRequest($membership, $actor);
    if (!$result->success) {
      return $result;
    }

    $project = $membership->getProject();
    $redirect = $project !== NULL ? ['redirect_url' => $project->toUrl()] : [];
    $message->delete();
    $this->jupyterHelpers->warnRestartNotebookForProjectMounts($this->messenger);

    return SodaScsResult::success(
      data: array_merge($result->data ?? [], $redirect),
      message: $result->message,
    );
  }

  /**
   * Decline the linked project invitation.
   */
  private function decline(SodaScsMessageInterface $message, UserInterface $actor): SodaScsResult {
    $membership = $this->loadMembership($message);
    if ($membership === NULL) {
      return SodaScsResult::failure(
        error: 'missing_invitation',
        message: (string) $this->t('This invitation is no longer valid.'),
        log: FALSE,
      );
    }

    $result = $this->membershipHelpers->rejectRequest($membership, $actor);
    if ($result->success) {
      $message->delete();
    }
    return $result;
  }

  /**
   * Remove the inbox copy (does not cancel a pending invitation).
   */
  private function delete(SodaScsMessageInterface $message): SodaScsResult {
    $membership = $this->loadMembership($message);
    if ($membership !== NULL && $membership->hasStatus(SodaScsProjectMembershipInterface::STATUS_PENDING)) {
      return SodaScsResult::failure(
        error: 'pending_invitation',
        message: (string) $this->t('Accept or decline this invitation before deleting it.'),
        log: FALSE,
      );
    }

    try {
      $message->delete();
    }
    catch (\Throwable) {
      return SodaScsResult::failure(
        error: 'storage_error',
        message: (string) $this->t('Could not delete the message. Please try again.'),
      );
    }

    return SodaScsResult::success(
      data: [],
      message: (string) $this->t('The message has been deleted.'),
    );
  }

  /**
   * Load the membership request this invitation message refers to.
   */
  private function loadMembership(SodaScsMessageInterface $message): SodaScsProjectMembershipInterface|null {
    if ($message->getRelatedEntityType() !== 'soda_scs_project_membership') {
      return NULL;
    }
    $id = $message->getRelatedEntityId();
    if ($id === NULL) {
      return NULL;
    }

    $entity = $this->entityTypeManager
      ->getStorage('soda_scs_project_membership')
      ->load($id);

    return $entity instanceof SodaScsProjectMembershipInterface ? $entity : NULL;
  }

}
