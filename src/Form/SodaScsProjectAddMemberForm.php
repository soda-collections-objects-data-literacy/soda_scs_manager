<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Traits\SodaScsProjectMemberInvitationTrait;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Compact form to invite a project member by email or username.
 */
final class SodaScsProjectAddMemberForm extends FormBase {

  use SodaScsProjectMemberInvitationTrait;

  /**
   * The project being edited.
   */
  private ?SodaScsProjectInterface $project = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('current_user'),
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_add_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsProjectInterface $soda_scs_project = NULL): array {
    $this->project = $soda_scs_project;
    if (!$this->project instanceof SodaScsProjectInterface) {
      return [];
    }
    if (!$this->projectMembershipHelpers->canAddMembers($this->project, $this->currentUser)) {
      return [];
    }

    $form['#attributes']['class'][] = 'soda-scs-manager--add-member-form-inner';
    $form['#attributes']['autocomplete'] = 'off';

    $this->buildMemberInvitationElement($form, $form_state);

    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Add'),
        '#attributes' => [
          'class' => ['button', 'button--primary', 'button--small'],
        ],
      ],
    ];

    return $form;
  }

  /**
   * Single email/username field without entity autocomplete.
   */
  protected function buildMemberInvitationElement(array &$form, FormStateInterface $form_state): void {
    $form['memberInvitations'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Email or username'),
      '#title_display' => 'invisible',
      '#required' => TRUE,
      '#maxlength' => 254,
      '#size' => 28,
      '#default_value' => $form_state->getValue('memberInvitations') ?? '',
      '#attributes' => [
        'autocomplete' => 'off',
        'autocorrect' => 'off',
        'autocapitalize' => 'off',
        'spellcheck' => 'false',
        'placeholder' => $this->t('Email or username'),
        'class' => ['soda-scs-manager--add-member-input'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);
    if (!$this->project instanceof SodaScsProjectInterface) {
      $form_state->setErrorByName('memberInvitations', $this->t('Project is missing.'));
      return;
    }

    $disallowedIds = [(int) $this->currentUser->id()];
    $existingMemberIds = [];
    foreach ($this->project->get('members')->getValue() as $item) {
      $existingMemberIds[] = (int) ($item['target_id'] ?? 0);
    }
    if ($this->project->getOwnerId()) {
      $existingMemberIds[] = (int) $this->project->getOwnerId();
    }
    $this->validateMemberInvitations(
      $form_state,
      $disallowedIds,
      array_values(array_unique(array_filter($existingMemberIds))),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->project instanceof SodaScsProjectInterface) {
      return;
    }
    if (!$this->projectMembershipHelpers->canAddMembers($this->project, $this->currentUser)) {
      $this->messenger()->addError($this->t('You are not allowed to add members to this project.'));
      return;
    }

    $requester = $this->loadCurrentUserEntity();
    if ($requester === NULL) {
      return;
    }

    $this->dispatchMemberInvitations(
      $form_state,
      $this->project,
      $requester,
      $this->projectMembershipHelpers,
      $this->messenger(),
    );

    $form_state->setRedirectUrl(Url::fromRoute('entity.soda_scs_project.canonical', [
      'soda_scs_project' => $this->project->id(),
    ]));
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
