<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityDisplayRepositoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Theme\Registry;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoStatusBuilder;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Form\SodaScsProjectAddMemberForm;
use Drupal\soda_scs_manager\Form\SodaScsProjectInvitationActionForm;
use Drupal\soda_scs_manager\Form\SodaScsProjectNoteForm;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Service\SodaScsApplicationCardBuilder;
use Drupal\soda_scs_manager\Service\SodaScsNextcloudPreview;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * View builder for SODa SCS Project entities.
 */
final class SodaScsProjectViewBuilder extends EntityViewBuilder
{

  use StringTranslationTrait;

  /**
   * Constructs a SodaScsProjectViewBuilder.
   */
  public function __construct(
    EntityTypeInterface $entity_type,
    EntityRepositoryInterface $entity_repository,
    LanguageManagerInterface $language_manager,
    Registry $theme_registry,
    EntityDisplayRepositoryInterface $entity_display_repository,
    protected AccountProxyInterface $currentUser,
    protected ConfigFactoryInterface $configFactory,
    protected EntityTypeManagerInterface $entityTypeManager,
    ModuleHandlerInterface $module_handler,
    protected SodaScsApplicationCardBuilder $applicationCardBuilder,
    protected FormBuilderInterface $formBuilder,
    protected SodaScsNextcloudPreview $nextcloudPreview,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    protected SodaScsDemoStatusBuilder $demoStatusBuilder,
  ) {
    parent::__construct($entity_type, $entity_repository, $language_manager, $theme_registry, $entity_display_repository);
    $this->moduleHandler = $module_handler;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): static
  {
    return new static(
      $entity_type,
      $container->get('entity.repository'),
      $container->get('language_manager'),
      $container->get('theme.registry'),
      $container->get('entity_display.repository'),
      $container->get('current_user'),
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('soda_scs_manager.application_card_builder'),
      $container->get('form_builder'),
      $container->get('soda_scs_manager.nextcloud.preview'),
      $container->get('soda_scs_manager.project_membership.helpers'),
      $container->get('soda_scs_manager.project_application.helpers'),
      $container->get('soda_scs_manager.demo.status_builder'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(array $build): array
  {
    $build = parent::build($build);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $build['#soda_scs_project'];

    foreach (
      [
        'connectedComponents',
        'created',
        'description',
        'groupId',
        'keycloakUuid',
        'label',
        'members',
        'note',
        'owner',
        'rights',
        'updated',
      ] as $fieldName
    ) {
      if (isset($build[$fieldName])) {
        $build[$fieldName]['#access'] = FALSE;
      }
    }

    $ownerId = (int) ($project->getOwnerId() ?? 0);
    $isOwner = $ownerId > 0 && $ownerId === (int) $this->currentUser->id();

    $createUrls = [
      'mariadb' => Url::fromRoute('entity.soda_scs_component.add_form', [
        'bundle' => 'soda_scs_sql_component',
      ])->toString(),
      'wisski' => Url::fromRoute('entity.soda_scs_stack.add_form', [
        'bundle' => 'soda_scs_wisski_stack',
      ])->toString(),
      'openGdb' => Url::fromRoute('entity.soda_scs_component.add_form', [
        'bundle' => 'soda_scs_triplestore_component',
      ])->toString(),
      'jupyter' => Url::fromRoute('entity.soda_scs_stack.add_form', [
        'bundle' => 'soda_scs_jupyter_stack',
      ])->toString(),
      'webprotege' => Url::fromRoute('entity.soda_scs_component.add_form', [
        'bundle' => 'soda_scs_webprotege_component',
      ])->toString(),
    ];

    $modulePath = $this->moduleHandler->getModule('soda_scs_manager')->getPath();
    $assetBase = '/' . $modulePath . '/assets/images/';

    $build['#theme'] = 'soda_scs_project';
    $build['#label'] = $project->label();
    $build['#project_id'] = (int) $project->id();
    $build['#is_owner'] = $isOwner;
    $build['#owner'] = $project->get('owner')->view('default');
    $build['#members'] = $this->buildMembersDisplay($project);
    $build['#note'] = $project->get('note')->isEmpty() ? NULL : $project->get('note')->view('default');
    $build['#note_edit_form'] = $this->formBuilder->getForm(SodaScsProjectNoteForm::class, $project);
    $applicationCards = $this->applicationCardBuilder->buildForProject($project);
    $build['#application_cards'] = $applicationCards;
    $build['#filter_tags'] = $this->applicationCardBuilder->collectFilterTags($applicationCards);
    $build['#files'] = $this->buildFilesDisplay($project);
    $build['#demo_status'] = $this->demoStatusBuilder->build($this->currentUser, $project, SodaScsDemoStatusBuilder::VARIANT_PANEL);
    $build['#add_application_card'] = $isOwner ? [
      '#theme' => 'soda_scs_manager__add_application_card',
      '#asset_base' => $assetBase,
      '#project_id' => (int) $project->id(),
      '#popup_url_mariadb' => $createUrls['mariadb'],
      '#popup_url_wisski' => $createUrls['wisski'],
      '#popup_url_open_gdb' => $createUrls['openGdb'],
      '#popup_url_jupyter' => $createUrls['jupyter'],
      '#popup_url_webprotege' => $createUrls['webprotege'],
      '#has_jupyter' => $this->projectApplicationHelpers->projectHasApplication($project->id(), 'soda_scs_stack', 'soda_scs_jupyter_stack'),
      '#has_webprotege' => $this->projectApplicationHelpers->projectHasApplication($project->id(), 'soda_scs_component', 'soda_scs_webprotege_component'),
      '#cache' => [
        'max-age' => 0,
      ],
    ] : NULL;
    $build['#attributes'] = ['class' => ['container', 'scs-manager--project-view']];
    $build['#cache'] = [
      'tags' => $project->getCacheTags(),
      'contexts' => ['user', 'languages:language_interface'],
      'max-age' => 0,
    ];

    $libraries = [
      'soda_scs_manager/globalStyling',
      'soda_scs_manager/dashboardHealthStatus',
      'soda_scs_manager/tagFilter',
      'scs_custom_theme/global-scripts',
    ];
    $drupalSettings = [
      'dashboardAdminMail' => (string) ($this->configFactory->get('system.site')->get('mail') ?? ''),
    ];
    if ($isOwner) {
      $libraries[] = 'soda_scs_manager/addApplicationPopup';
      $drupalSettings['addApplicationCreateUrls'] = $createUrls;
    }
    if ($this->projectMembershipHelpers->canAddMembers($project, $this->currentUser)) {
      $libraries[] = 'soda_scs_manager/projectMembers';
    }

    $build['#attached']['library'] = $libraries;
    $build['#attached']['drupalSettings']['sodaScsManager'] = $drupalSettings;

    return $build;
  }

  /**
   * Builds the Files section (project Team Folder activity for the viewer).
   *
   * @return array<string, mixed>|null
   *   Render array, or NULL when the current account cannot be loaded.
   */
  protected function buildFilesDisplay(SodaScsProjectInterface $project): ?array
  {
    $user = $this->entityTypeManager->getStorage('user')->load($this->currentUser->id());
    if (!$user instanceof UserInterface) {
      return NULL;
    }

    return $this->nextcloudPreview->buildProjectFilesSection($user, $project);
  }

  /**
   * Builds the members field for the project info section.
   *
   * @return array<string, mixed>
   *   Members field render array.
   */
  protected function buildMembersDisplay(SodaScsProjectInterface $project): array
  {
    $ownerId = (int) ($project->getOwnerId() ?? 0);
    $items = [];
    $owner = [];

    // The owner is not part of the 'members' field, so it is loaded separately.
    $ownerAccount = $project->getOwner();
    if ($ownerAccount instanceof UserInterface) {
      $owner = $this->buildMemberProfile($ownerAccount);
    }

    if (!$project->get('members')->isEmpty()) {
      foreach ($project->get('members')->referencedEntities() as $member) {
        if (!$member instanceof UserInterface) {
          continue;
        }
        $memberId = (int) $member->id();

        if ($memberId === $ownerId) {
          continue;
        }

        if ($memberId <= 0) {
          continue;
        }

        $removeUrl = NULL;
        $removeForm = NULL;
        if ($this->projectMembershipHelpers->canRemoveMember($project, $this->currentUser, $member)) {
          $isSelf = $memberId === (int) $this->currentUser->id();
          $routeName = $isSelf
            ? 'entity.soda_scs_project.leave_form'
            : 'entity.soda_scs_project.remove_member_form';
          $routeParameters = ['soda_scs_project' => $project->id()];
          if (!$isSelf) {
            $routeParameters['user'] = $memberId;
          }
          $removeUrl = Url::fromRoute($routeName, $routeParameters)->toString();
          $removeForm = $this->formBuilder->getForm(\Drupal\soda_scs_manager\Form\SodaScsProjectRemoveMemberForm::class, $project, $member);
        }

        $items[] = $this->buildMemberProfile($member) + [
          'remove_url' => $removeUrl,
          'remove_form' => $removeForm,
          'remove_label' => $memberId === (int) $this->currentUser->id()
            ? $this->t('Leave project')
            : $this->t('Remove @member', ['@member' => $member->getDisplayName()]),
        ];
      }
    }

    $canAddMembers = $this->projectMembershipHelpers->canAddMembers($project, $this->currentUser);
    $addForm = NULL;
    $invitations = [];
    if ($canAddMembers) {
      $addForm = $this->formBuilder->getForm(SodaScsProjectAddMemberForm::class, $project);
      $invitations = $this->buildOpenInvitations($project);
    }

    return [
      '#theme' => 'soda_scs_manager__project_members',
      '#members' => $items,
      '#owner' => $owner,
      '#can_add_members' => $canAddMembers,
      '#add_form' => $addForm,
      '#invitations' => $invitations,
      '#cache' => [
        'contexts' => ['user', 'languages:language_interface'],
        'tags' => $project->getCacheTags(),
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Pending and declined invitations for the team editor.
   *
   * @return list<array<string, mixed>>
   *   Invitation rows for the edit overlay.
   */
  protected function buildOpenInvitations(SodaScsProjectInterface $project): array {
    $rows = [];
    foreach ($this->projectMembershipHelpers->loadOpenInvitations($project) as $invitation) {
      $recipient = $invitation->get('recipient')->entity;
      if (!$recipient instanceof UserInterface) {
        continue;
      }

      $isDeclined = $invitation->hasStatus(SodaScsProjectMembershipInterface::STATUS_REJECTED);
      $row = $this->buildMemberProfile($recipient);
      $row['status'] = $invitation->getStatus();
      $row['status_label'] = $isDeclined ? $this->t('Declined') : $this->t('Pending');
      $row['withdraw_form'] = NULL;
      $row['delete_form'] = NULL;
      $row['resend_form'] = NULL;

      if ($isDeclined) {
        SodaScsProjectInvitationActionForm::queueFormId($invitation->id(), 'resend');
        $row['resend_form'] = $this->formBuilder->getForm(SodaScsProjectInvitationActionForm::class, $project, $invitation, 'resend');
        SodaScsProjectInvitationActionForm::queueFormId($invitation->id(), 'delete');
        $row['delete_form'] = $this->formBuilder->getForm(SodaScsProjectInvitationActionForm::class, $project, $invitation, 'delete');
        $row['delete_label'] = $this->t('Delete declined invitation for @user', [
          '@user' => $recipient->getDisplayName(),
        ]);
      }
      else {
        SodaScsProjectInvitationActionForm::queueFormId($invitation->id(), 'withdraw');
        $row['withdraw_form'] = $this->formBuilder->getForm(SodaScsProjectInvitationActionForm::class, $project, $invitation, 'withdraw');
        $row['withdraw_label'] = $this->t('Withdraw invitation for @user', [
          '@user' => $recipient->getDisplayName(),
        ]);
      }

      $rows[] = $row;
    }

    return $rows;
  }

  /**
   * Builds a profile array (name, username, mail, picture, …) for a member.
   *
   * @return array<string, mixed>
   *   Member profile data.
   */
  protected function buildMemberProfile(UserInterface $member): array
  {
    $memberId = (int) $member->id();
    $firstName = $member->hasField('first_name') ? trim((string) $member->get('first_name')->value) : '';
    $lastName = $member->hasField('last_name') ? trim((string) $member->get('last_name')->value) : '';
    $fullName = trim($firstName . ' ' . $lastName);
    $isCurrentUser = $memberId === (int) $this->currentUser->id();
    $displayName = $fullName !== '' ? $fullName : $member->getDisplayName();

    $initials = '';
    if ($firstName !== '') {
      $initials .= mb_substr($firstName, 0, 1);
    }
    if ($lastName !== '') {
      $initials .= mb_substr($lastName, 0, 1);
    }
    if ($initials === '') {
      $initials = mb_substr($displayName, 0, 1);
    }

    return [
      'id' => $memberId,
      'first_name' => $firstName,
      'last_name' => $lastName,
      'name' => $isCurrentUser ? (string) $this->t('You') : $displayName,
      'initials' => mb_strtoupper($initials),
      'username' => $member->getAccountName(),
      'mail' => $member->getEmail(),
      'picture' => $member->hasField('user_picture') && !$member->get('user_picture')->isEmpty()
        ? $member->get('user_picture')->view(['label' => 'hidden'])
        : [],
      'is_current_user' => $isCurrentUser,
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode): array
  {
    $build = parent::getBuildDefaults($entity, $view_mode);
    $build['#theme'] = 'soda_scs_project';

    return $build;
  }
}
