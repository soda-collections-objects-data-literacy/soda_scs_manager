<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Withdraw, delete, or resend one project invitation.
 */
final class SodaScsProjectInvitationActionForm extends ConfirmFormBase {

  /**
   * The invitation this form acts on.
   */
  private ?SodaScsProjectMembershipInterface $invitation = NULL;

  /**
   * withdraw, delete, or resend.
   */
  private string $operation = '';

  /**
   * Queue the form id for the next embedded build.
   *
   * Several of these forms are on one page. Drupal only tells the submitted
   * form apart when each build has its own form id, and getFormId() runs
   * before the membership argument is available.
   */
  public static function queueFormId(int|string $invitationId, string $operation): void {
    self::$formIdQueue[] = 'soda_scs_manager_project_invitation_' . $operation . '_' . $invitationId;
  }

  /**
   * The project the invitation belongs to.
   */
  private ?SodaScsProjectInterface $project = NULL;

  /**
   * Form ids for the invitation forms built during this request.
   *
   * @var list<string>
   */
  private static array $formIdQueue = [];

  /**
   * Constructs the form.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsProjectInterface $soda_scs_project = NULL, ?SodaScsProjectMembershipInterface $soda_scs_project_membership = NULL, string $operation = ''): array {
    $this->project = $soda_scs_project;
    $this->invitation = $soda_scs_project_membership;
    $this->operation = $operation;

    $form['invitation_operation'] = [
      '#type' => 'hidden',
      '#value' => $operation,
    ];

    if ($operation === 'resend') {
      $form['#attributes']['class'][] = 'project-member__resend-form';
      $form['actions'] = [
        '#type' => 'actions',
        'submit' => [
          '#type' => 'submit',
          '#value' => $this->t('Resend'),
          '#attributes' => [
            'class' => ['btn', 'btn--secondary'],
          ],
        ],
      ];
      return $form;
    }

    $form = parent::buildForm($form, $form_state);
    $form['#attributes']['class'][] = 'project-member__invitation-confirm';

    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'btn';
    }
    if (isset($form['actions']['cancel'])) {
      $form['actions']['cancel']['#attributes']['class'][] = 'btn';
      $form['actions']['cancel']['#attributes']['class'][] = 'btn--secondary';
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    if ($this->project instanceof SodaScsProjectInterface) {
      return new Url('entity.soda_scs_project.canonical', [
        'soda_scs_project' => $this->project->id(),
      ]);
    }

    return new Url('soda_scs_manager.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->operation === 'withdraw'
      ? $this->t('Withdraw')
      : $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $name = $this->recipientName();
    if ($this->operation === 'withdraw') {
      return $this->t('The invitation will be cancelled. @user can be invited again afterwards.', [
        '@user' => $name,
      ]);
    }

    return $this->t('The declined invitation will be removed. @user can be invited again afterwards.', [
      '@user' => $name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    if (self::$formIdQueue !== []) {
      return (string) array_shift(self::$formIdQueue);
    }

    return 'soda_scs_manager_project_invitation_action';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $name = $this->recipientName();
    if ($this->operation === 'withdraw') {
      return $this->t('Withdraw invitation for @user?', [
        '@user' => $name,
      ]);
    }

    return $this->t('Delete the declined invitation for @user?', [
      '@user' => $name,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $actor = $this->loadActor();
    if (!$actor instanceof UserInterface || !$this->project instanceof SodaScsProjectInterface || !$this->invitation instanceof SodaScsProjectMembershipInterface) {
      $this->messenger()->addError($this->t('This invitation cannot be changed.'));
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }

    $result = match ($this->operation) {
      'withdraw' => $this->projectMembershipHelpers->withdrawInvitation($this->project, $this->invitation, $actor),
      'delete' => $this->projectMembershipHelpers->deleteDeclinedInvitation($this->project, $this->invitation, $actor),
      'resend' => $this->projectMembershipHelpers->resendInvitation($this->project, $this->invitation, $actor),
      default => SodaScsResult::failure(
        error: 'unknown_operation',
        message: (string) $this->t('This invitation cannot be changed.'),
        log: FALSE,
      ),
    };

    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->operation !== 'resend') {
      parent::validateForm($form, $form_state);
    }

    $actor = $this->loadActor();
    $allowed = $actor instanceof UserInterface
      && $this->project instanceof SodaScsProjectInterface
      && $this->invitation instanceof SodaScsProjectMembershipInterface
      && $this->projectMembershipHelpers->canRunInvitationOperation($this->project, $this->invitation, $actor, $this->operation);

    if (!$allowed) {
      $form_state->setErrorByName('actions', $this->t('This invitation cannot be changed.'));
    }
  }

  /**
   * Load the acting user account.
   */
  private function loadActor(): UserInterface|null {
    $actor = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    return $actor instanceof UserInterface ? $actor : NULL;
  }

  /**
   * Display name of the invited person.
   */
  private function recipientName(): string {
    $recipient = $this->invitation?->get('recipient')->entity;
    if ($recipient instanceof UserInterface) {
      return $recipient->getDisplayName();
    }
    return (string) $this->t('this person');
  }

}
