<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Traits;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;

/**
 * Shared helpers for handling project member invitations via forms.
 *
 * Classes using this trait must define an $entityTypeManager property.
 */
trait SodaScsProjectMemberInvitationTrait {

  /**
   * Form element machine name for invitation input.
   */
  private const INVITATION_FIELD_NAME = 'memberInvitations';

  /**
   * Form state key holding validated invitees.
   */
  private const INVITATION_STATE_KEY = 'soda_scs_member_invitations';

  /**
   * Add the invitation textarea to the form.
   */
  protected function buildMemberInvitationElement(array &$form, FormStateInterface $form_state): void {
    $form[self::INVITATION_FIELD_NAME] = [
      '#type' => 'textarea',
      '#title' => $this->t('Invite members'),
      '#description' => $this->t('Enter usernames or e-mail addresses separated by commas or new lines. Each user will receive a request they can accept or reject.'),
      '#attributes' => [
        'autocomplete' => 'off',
        'class' => ['soda-scs-manager--member-invitations'],
      ],
      '#rows' => 3,
      '#default_value' => $form_state->getValue(self::INVITATION_FIELD_NAME) ?? '',
      '#weight' => 35,
    ];
  }

  /**
   * Validate the invitation input and load matching users.
   *
   * @param array $disallowedUserIds
   *   User IDs that are not allowed to be invited.
   * @param array $existingMemberIds
   *   User IDs already part of the project.
   */
  protected function validateMemberInvitations(FormStateInterface $form_state, array $disallowedUserIds = [], array $existingMemberIds = []): void {
    $rawInput = (string) $form_state->getValue(self::INVITATION_FIELD_NAME, '');
    $identifiers = $this->extractInvitationIdentifiers($rawInput);

    if (empty($identifiers)) {
      $form_state->set(self::INVITATION_STATE_KEY, []);
      return;
    }

    $userStorage = $this->entityTypeManager->getStorage('user');
    $loadedUsers = [];

    foreach ($identifiers as $identifier) {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $this->loadUserByIdentifier($userStorage, $identifier);
      if (!$user) {
        $form_state->setErrorByName(self::INVITATION_FIELD_NAME, $this->t('User "@identifier" could not be found.', [
          '@identifier' => $identifier,
        ]));
        continue;
      }

      if (in_array((int) $user->id(), $disallowedUserIds, TRUE)) {
        $form_state->setErrorByName(self::INVITATION_FIELD_NAME, $this->t('You cannot invite @user.', [
          '@user' => $user->getDisplayName(),
        ]));
        continue;
      }

      if (in_array((int) $user->id(), $existingMemberIds, TRUE)) {
        $form_state->setErrorByName(self::INVITATION_FIELD_NAME, $this->t('@user is already part of this project.', [
          '@user' => $user->getDisplayName(),
        ]));
        continue;
      }

      $loadedUsers[(int) $user->id()] = $user;
    }

    $form_state->set(self::INVITATION_STATE_KEY, $loadedUsers);
  }

  /**
   * Send invitations for the validated users and report the result.
   */
  protected function dispatchMemberInvitations(
    FormStateInterface $form_state,
    SodaScsProjectInterface $project,
    UserInterface $requester,
    SodaScsProjectMembershipHelpers $membershipHelpers,
    MessengerInterface $messenger,
  ): void {
    $invitees = $form_state->get(self::INVITATION_STATE_KEY) ?? [];
    $form_state->set(self::INVITATION_STATE_KEY, []);
    $form_state->setValue(self::INVITATION_FIELD_NAME, '');

    if (empty($invitees)) {
      return;
    }

    /** @var \Drupal\user\UserInterface[] $inviteUsers */
    $inviteUsers = array_values($invitees);

    $result = $membershipHelpers->inviteUsers($project, $requester, $inviteUsers);

    $this->reportInvitationResult($result, $messenger);
  }

  /**
   * Extract normalized identifiers from raw input.
   */
  private function extractInvitationIdentifiers(string $rawInput): array {
    $tokens = preg_split('/[\n\r,;]+/', $rawInput) ?: [];
    $tokens = array_map(static fn(string $value): string => trim($value), $tokens);
    $tokens = array_filter($tokens, static fn(string $value): bool => $value !== '');
    return array_values(array_unique($tokens));
  }

  /**
   * Load a user entity for the provided identifier.
   */
  private function loadUserByIdentifier($userStorage, string $identifier): UserInterface|null {
    if (is_numeric($identifier)) {
      /** @var \Drupal\user\UserInterface|null $user */
      $user = $userStorage->load((int) $identifier);
      if ($user) {
        return $user;
      }
    }

    $query = $userStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 1)
      ->range(0, 1);

    $group = $query->orConditionGroup()
      ->condition('name', $identifier)
      ->condition('mail', $identifier);

    $query->condition($group);
    $result = $query->execute();
    if (empty($result)) {
      return NULL;
    }

    /** @var \Drupal\user\UserInterface|null $user */
    $user = $userStorage->load(reset($result));
    return $user;
  }

  /**
   * Helper to report the invitation result via messenger service.
   */
  private function reportInvitationResult(SodaScsResult $result, MessengerInterface $messenger): void {
    if ($result->success) {
      $messenger->addStatus($result->message);
    }
    else {
      $messenger->addError($result->message);
    }
  }

}


