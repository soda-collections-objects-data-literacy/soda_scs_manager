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
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Traits\SodaScsProjectMemberInvitationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the ScsComponent entity edit form.
 */
class SodaScsProjectEditForm extends ContentEntityForm {

  use SodaScsProjectMemberInvitationTrait;

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
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
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

    // Load pending invitations for this project.
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $query = $storage->getQuery()
      ->condition('project', $project->id())
      ->condition('status', 'pending')
      ->sort('created', 'DESC')
      ->accessCheck(FALSE);

    $ids = $query->execute();

    if (empty($ids)) {
      return;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[] $pendingRequests */
    $pendingRequests = $storage->loadMultiple($ids);

    $form['pending_invitations'] = [
      '#type' => 'details',
      '#title' => $this->t('Pending Invitations (@count)', ['@count' => count($pendingRequests)]),
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
        'invited_by' => $this->t('Invited By'),
        'date' => $this->t('Date'),
        'actions' => $this->t('Actions'),
      ],
      '#empty' => $this->t('No pending invitations.'),
      '#attributes' => ['class' => ['project-members-table']],
    ];

    foreach ($pendingRequests as $request) {
      $requestId = $request->id();
      $recipient = $request->getRecipient();
      $requester = $request->getRequester();

      $form['pending_invitations']['table_wrapper']['table'][$requestId] = [
        'recipient' => ['#markup' => $recipient->getDisplayName()],
        'email' => ['#markup' => $recipient->getEmail()],
        'invited_by' => ['#markup' => $requester->getDisplayName()],
        'date' => [
          '#markup' => \Drupal::service('date.formatter')->format(
            $request->getCreatedTime(),
            'short'
          ),
        ],
        'actions' => [
          '#type' => 'submit',
          '#value' => $this->t('Cancel'),
          '#name' => 'cancel_invitation_' . $requestId,
          '#request_id' => $requestId,
          '#submit' => ['::cancelInvitation'],
          '#limit_validation_errors' => [],
          '#attributes' => ['class' => ['button--danger', 'button--small']],
        ],
      ];
    }
  }

  /**
   * Cancel invitation submit handler.
   */
  public function cancelInvitation(array &$form, FormStateInterface $form_state): void {
    $trigger = $form_state->getTriggeringElement();
    $requestId = (int) ($trigger['#request_id'] ?? 0);

    if ($requestId <= 0) {
      $this->messenger()->addError($this->t('Invalid invitation ID.'));
      return;
    }

    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface|null $request */
    $request = $storage->load($requestId);

    if (!$request) {
      $this->messenger()->addError($this->t('Invitation not found.'));
      return;
    }

    $recipientName = $request->getRecipient()->getDisplayName();

    try {
      $request->delete();
      $this->messenger()->addStatus($this->t('Invitation to @recipient has been cancelled.', [
        '@recipient' => $recipientName,
      ]));
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to cancel invitation: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to cancel invitation. Please try again.'));
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

    // Load the member to get their name for the message.
    $memberStorage = $this->entityTypeManager->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $member */
    $member = $memberStorage->load($memberId);

    if (!$member) {
      $this->messenger()->addError($this->t('Member not found.'));
      return;
    }

    // Remove the member from the project.
    $members = $project->get('members')->getValue();
    $updatedMembers = array_filter($members, function ($item) use ($memberId) {
      return (int) ($item['target_id'] ?? 0) !== $memberId;
    });

    $project->set('members', array_values($updatedMembers));

    try {
      $project->save();
      $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);
      $this->messenger()->addStatus($this->t('@member has been removed from the project.', [
        '@member' => $member->getDisplayName(),
      ]));
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to remove member from project: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to remove member. Please try again.'));
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
    $this->validateMemberInvitations($form_state, $disallowedIds, $existingMemberIds);
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    parent::save($form, $form_state);

    // Ensure all members have the project's Keycloak group (by gid).
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;

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

    // Redirect to the components page.
    $form_state->setRedirect('entity.soda_scs_project.collection');
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
