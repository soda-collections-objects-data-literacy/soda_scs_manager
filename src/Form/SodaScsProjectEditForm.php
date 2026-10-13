<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Traits\SodaScsProjectMemberInvitationTrait;
use Drupal\soda_scs_manager\Traits\SodaScsProjectNameCheckFormTrait;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the ScsComponent entity edit form.
 */
class SodaScsProjectEditForm extends ContentEntityForm {

  use SodaScsProjectMemberInvitationTrait;
  use SodaScsProjectNameCheckFormTrait;

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The bundle.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * Keycloak client actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions;

  /**
   * Keycloak group actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions;

  /**
   * Keycloak user actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions;

  /**
   * Project helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The membership helper service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers
   */
  protected SodaScsProjectMembershipHelpers $projectMembershipHelpers;

  /**
   * Project DB access helpers (MariaDB/phpMyAdmin SSO for project members).
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers
   */
  protected SodaScsProjectDbAccessHelpers $projectDbAccessHelpers;

  /**
   * WebProtégé ontology project helper.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers
   */
  protected SodaScsWebprotegeHelpers $webprotegeHelpers;

  /**
   * Project Team Folder mounts of the Nextcloud service account.
   */
  protected NextcloudProjectMountManager $projectMountManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory'),
      $container->get('soda_scs_manager.keycloak_service.client.actions'),
      $container->get('soda_scs_manager.keycloak_service.group.actions'),
      $container->get('soda_scs_manager.keycloak_service.user.actions'),
      $container->get('soda_scs_manager.project.helpers'),
      $container->get('soda_scs_manager.project_membership.helpers'),
      $container->get('soda_scs_manager.project_db_access.helpers'),
      $container->get('soda_scs_manager.webprotege.helpers'),
      $container->get('soda_scs_manager.nextcloud_project_mount.manager'),
    );
  }

  /**
   * Constructs a new SodaScsProjectEditForm.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    TimeInterface $time,
    AccountProxyInterface $currentUser,
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceClientActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceGroupActions,
    SodaScsServiceRequestInterface $sodaScsKeycloakServiceUserActions,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    SodaScsProjectMembershipHelpers $projectMembershipHelpers,
    SodaScsProjectDbAccessHelpers $projectDbAccessHelpers,
    SodaScsWebprotegeHelpers $webprotegeHelpers,
    NextcloudProjectMountManager $projectMountManager,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->currentUser = $currentUser;
    $this->entityTypeManager = $entityTypeManager;
    $this->settings = $configFactory->getEditable('soda_scs_manager.settings');
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->sodaScsKeycloakServiceClientActions = $sodaScsKeycloakServiceClientActions;
    $this->sodaScsKeycloakServiceGroupActions = $sodaScsKeycloakServiceGroupActions;
    $this->sodaScsKeycloakServiceUserActions = $sodaScsKeycloakServiceUserActions;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->projectMembershipHelpers = $projectMembershipHelpers;
    $this->projectDbAccessHelpers = $projectDbAccessHelpers;
    $this->webprotegeHelpers = $webprotegeHelpers;
    $this->projectMountManager = $projectMountManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_edit_form';
  }

  /**
   * Cancel form submission handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.soda_scs_project.canonical', [
      'soda_scs_project' => $this->entity->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $form = parent::buildForm($form, $form_state);
    // Debug WSOD error handling. Remove this line in production.
    // quatsch("lol");
    $form[] = ['#markup' => "hello"];
    $current_user = $this->currentUser;

    $form['owner']['widget']['#default_value'] = $current_user->id();
    if (!$current_user->hasPermission('soda scs manager admin')) {
      $form['owner']['#access'] = FALSE;
    }

    // Remove the delete button.
    unset($form['actions']['delete']);

    // Add an abort button.
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelForm'],
      '#limit_validation_errors' => [],
      '#weight' => 10,
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'button--cancel'],
      ],
    ];

    if (isset($form['members'])) {
      $form['members']['#access'] = FALSE;
    }

    $this->buildCurrentMembersSection($form, $form_state);
    $this->buildPendingInvitationsSection($form, $form_state);
    $this->buildMemberInvitationElement($form, $form_state);
    $this->attachProjectNameCheck($form, $this->entity->id());

    return $form;
  }

  /**
   * Build the current members management section.
   */
  protected function buildCurrentMembersSection(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;

    $form['current_members'] = [
      '#type' => 'details',
      '#title' => $this->t('Current Members'),
      '#open' => TRUE,
      '#weight' => 32,
    ];

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $membersField */
    $membersField = $project->get('members');
    $members = $membersField->referencedEntities();
    $owner = $project->getOwner();

    if (empty($members) && !$owner) {
      $form['current_members']['empty'] = [
        '#markup' => '<p>' . $this->t('No members yet.') . '</p>',
      ];
      return;
    }

    $form['current_members']['table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['project-members-table-wrapper']],
    ];

    $form['current_members']['table_wrapper']['table'] = [
      '#type' => 'table',
      '#header' => [
        'name' => $this->t('Name'),
        'email' => $this->t('Email'),
        'role' => $this->t('Role'),
        'actions' => $this->t('Actions'),
      ],
      '#empty' => $this->t('No members.'),
      '#attributes' => ['class' => ['project-members-table']],
    ];

    // Add owner first.
    if ($owner) {
      $form['current_members']['table_wrapper']['table']['owner'] = [
        'name' => ['#markup' => $owner->getDisplayName()],
        'email' => ['#markup' => $owner->getEmail()],
        'role' => ['#markup' => '<strong>' . $this->t('Owner') . '</strong>'],
        'actions' => ['#markup' => '—'],
      ];
    }

    // Add members with remove buttons.
    foreach ($members as $member) {
      /** @var \Drupal\user\UserInterface $member */
      $memberId = $member->id();
      $form['current_members']['table_wrapper']['table'][$memberId] = [
        'name' => ['#markup' => $member->getDisplayName()],
        'email' => ['#markup' => $member->getEmail()],
        'role' => ['#markup' => $this->t('Member')],
        'actions' => [
          '#type' => 'submit',
          '#value' => $this->t('Remove'),
          '#name' => 'remove_member_' . $memberId,
          '#member_id' => $memberId,
          '#submit' => ['::removeMember'],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['button--danger', 'button--small']],
        ],
      ];
    }
  }

  /**
   * Build the pending invitations section.
   */
  protected function buildPendingInvitationsSection(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;

    $openInvitations = $this->projectMembershipHelpers->loadOpenInvitations($project);
    if ($openInvitations === []) {
      return;
    }

    $form['pending_invitations'] = [
      '#type' => 'details',
      '#title' => $this->t('Pending invitations (@count)', ['@count' => count($openInvitations)]),
      '#open' => TRUE,
      '#weight' => 33,
    ];

    $form['pending_invitations']['table_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['project-members-table-wrapper']],
    ];

    $form['pending_invitations']['table_wrapper']['table'] = [
      '#type' => 'table',
      '#header' => [
        'recipient' => $this->t('Invited User'),
        'email' => $this->t('Email'),
        'status' => $this->t('Status'),
        'invited_by' => $this->t('Invited By'),
        'date' => $this->t('Date'),
        'actions' => $this->t('Actions'),
      ],
      '#empty' => $this->t('No pending invitations.'),
      '#attributes' => ['class' => ['project-members-table']],
    ];

    foreach ($openInvitations as $request) {
      $requestId = $request->id();
      $recipient = $request->get('recipient')->entity;
      $requester = $request->get('requester')->entity;
      if (!$recipient instanceof UserInterface) {
        continue;
      }

      $isDeclined = $request->hasStatus(SodaScsProjectMembershipInterface::STATUS_REJECTED);
      $actions = [
        '#type' => 'container',
      ];
      if ($isDeclined) {
        $actions['resend'] = $this->buildInvitationActionButton($this->t('Resend'), 'resend', $requestId);
        $actions['delete'] = $this->buildInvitationActionButton($this->t('Delete'), 'delete', $requestId);
      }
      else {
        $actions['withdraw'] = $this->buildInvitationActionButton($this->t('Withdraw'), 'withdraw', $requestId);
      }

      $form['pending_invitations']['table_wrapper']['table'][$requestId] = [
        'recipient' => ['#markup' => $recipient->getDisplayName()],
        'email' => ['#markup' => $recipient->getEmail()],
        'status' => [
          '#markup' => $isDeclined ? $this->t('Declined') : $this->t('Pending'),
        ],
        'invited_by' => [
          '#markup' => $requester instanceof UserInterface ? $requester->getDisplayName() : '',
        ],
        'date' => [
          '#markup' => \Drupal::service('date.formatter')->format(
            $request->getCreatedTime(),
            'short'
          ),
        ],
        'actions' => $actions,
      ];
    }
  }

  /**
   * Withdraw, delete, or resend an invitation from the project edit form.
   */
  public function manageInvitation(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $requestId = (int) ($trigger['#request_id'] ?? 0);
    $operation = (string) ($trigger['#invitation_operation'] ?? '');

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    $actor = $this->loadCurrentUserEntity();
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $request = $storage->load($requestId);

    if (!$actor instanceof UserInterface || !$request instanceof SodaScsProjectMembershipInterface || !$project instanceof SodaScsProjectInterface) {
      $this->messenger()->addError($this->t('This invitation cannot be changed.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $result = match ($operation) {
      'withdraw' => $this->projectMembershipHelpers->withdrawInvitation($project, $request, $actor),
      'delete' => $this->projectMembershipHelpers->deleteDeclinedInvitation($project, $request, $actor),
      'resend' => $this->projectMembershipHelpers->resendInvitation($project, $request, $actor),
      default => NULL,
    };

    if ($result === NULL) {
      $this->messenger()->addError($this->t('This invitation cannot be changed.'));
    }
    elseif ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Remove member submit handler.
   */
  public function removeMember(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $memberId = (int) ($trigger['#member_id'] ?? 0);

    if ($memberId <= 0) {
      $this->messenger()->addError($this->t('Invalid member ID.'));
      return;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;

    $memberStorage = $this->entityTypeManager->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $member */
    $member = $memberStorage->load($memberId);

    if (!$member) {
      $this->messenger()->addError($this->t('Member not found.'));
      return;
    }

    $result = $this->projectMembershipHelpers->removeMember($project, $member, $this->currentUser);
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $disallowedIds = [(int) $this->currentUser->id()];
    $existingMemberIds = $this->collectExistingMemberIds();
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    $alreadyInvitedIds = $project instanceof SodaScsProjectInterface
      ? $this->projectMembershipHelpers->openInvitationRecipientIds($project)
      : [];
    $this->validateMemberInvitations($form_state, $disallowedIds, $existingMemberIds, $alreadyInvitedIds);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;
    $previousLabel = '';
    if (isset($project->original) && $project->original instanceof SodaScsProjectInterface) {
      $previousLabel = (string) $project->original->label();
    }

    parent::save($form, $form_state);

    // Ensure all members have the project's Keycloak group (by gid).
    $requester = $this->loadCurrentUserEntity();
    if ($requester) {
      $this->dispatchMemberInvitations(
        $form_state,
        $project,
        $requester,
        $this->projectMembershipHelpers,
        $this->messenger(),
      );
    }

    $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);
    $this->projectDbAccessHelpers->syncProjectMembersDbAccess($project);

    $newLabel = (string) $project->label();
    if ($previousLabel !== '' && $previousLabel !== $newLabel) {
      $updateAttrs = $this->sodaScsProjectHelpers->updateProjectGroupAttributes($project);
      if (!$updateAttrs->success) {
        $this->messenger()->addError($this->t('Failed to update Keycloak group label for project @project: @error', [
          '@project' => $newLabel,
          '@error' => $updateAttrs->error,
        ]));
      }
      $updateFolder = $this->sodaScsProjectHelpers->updateProjectTeamFolderLabel($project);
      if (!$updateFolder->success && !empty($updateFolder->data['conflict'])) {
        // Nextcloud already has a folder with that name (e.g. a platform
        // folder): keep the project and its Team Folder on the old name.
        $project->set('label', $previousLabel);
        $project->save();
        $this->sodaScsProjectHelpers->updateProjectGroupAttributes($project);
        $this->messenger()->addError($updateFolder->message);
      }
      elseif (!$updateFolder->success) {
        $this->messenger()->addError($this->t('Failed to rename Nextcloud Team Folder for project @project: @error', [
          '@project' => $newLabel,
          '@error' => $updateFolder->error,
        ]));
      }
      elseif ($this->projectMountManager->needsProjectMount($project)) {
        // The mount path contains the label; remount now instead of on cron.
        $this->projectMountManager->ensureProjectMounted($project);
      }
    }

    $form_state->setRedirect('entity.soda_scs_project.canonical', [
      'soda_scs_project' => $project->id(),
    ]);
  }

  /**
   * Submit button for one invitation operation.
   *
   * @return array<string, mixed>
   *   A submit element.
   */
  private function buildInvitationActionButton(TranslatableMarkup $label, string $operation, int|string $requestId): array {
    return [
      '#type' => 'submit',
      '#value' => $label,
      '#name' => $operation . '_invitation_' . $requestId,
      '#request_id' => $requestId,
      '#invitation_operation' => $operation,
      '#submit' => ['::manageInvitation'],
      '#limit_validation_errors' => [],
      '#attributes' => ['class' => ['button--small']],
    ];
  }

  /**
   * Collect IDs of existing project members including the owner.
   */
  private function collectExistingMemberIds(): array {
    $ids = [];
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    if ($project && !$project->get('members')->isEmpty()) {
      foreach ($project->get('members')->getValue() as $member) {
        $ids[] = (int) ($member['target_id'] ?? 0);
      }
    }
    if ($project && $project->getOwnerId()) {
      $ids[] = (int) $project->getOwnerId();
    }
    return array_values(array_unique(array_filter($ids)));
  }

  /**
   * Load the active user entity from storage.
   */
  private function loadCurrentUserEntity(): UserInterface|null {
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user) {
      $this->messenger()->addError($this->t('Unable to load the current user account.'));
    }
    return $user;
  }

}
