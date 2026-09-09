<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirm removing a listed member from a project.
 */
final class SodaScsProjectRemoveMemberForm extends ConfirmFormBase {

  /**
   * The project.
   */
  private ?SodaScsProjectInterface $project = NULL;

  /**
   * The member to remove.
   */
  private ?UserInterface $member = NULL;

  /**
   * Constructs the form.
   */
  public function __construct(
    protected AccountProxyInterface $currentUser,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('current_user'),
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_remove_member_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    $memberName = $this->member instanceof UserInterface
      ? $this->member->getDisplayName()
      : (string) $this->t('this user');
    $projectLabel = $this->project instanceof SodaScsProjectInterface
      ? $this->project->label()
      : (string) $this->t('this project');

    if ($this->member instanceof UserInterface && (int) $this->currentUser->id() === (int) $this->member->id()) {
      return $this->t('Are you sure you want to leave project: @label?', [
        '@label' => $projectLabel,
      ]);
    }

    return $this->t('Remove @member from project @project?', [
      '@member' => $memberName,
      '@project' => $projectLabel,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    if ($this->member instanceof UserInterface && (int) $this->currentUser->id() === (int) $this->member->id()) {
      return $this->t('You will no longer have access to this project after leaving. This action cannot be undone.');
    }

    return $this->t('They will lose access to this project. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    if ($this->member instanceof UserInterface && (int) $this->currentUser->id() === (int) $this->member->id()) {
      return $this->t('Leave project');
    }

    return $this->t('Remove');
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
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsProjectInterface $soda_scs_project = NULL, ?UserInterface $user = NULL): array {
    $this->project = $soda_scs_project;
    $this->member = $user;

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->project instanceof SodaScsProjectInterface || !$this->member instanceof UserInterface) {
      $this->messenger()->addError($this->t('The member could not be removed.'));
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }

    $result = $this->projectMembershipHelpers->removeMember(
      $this->project,
      $this->member,
      $this->currentUser,
    );

    if ($result->success) {
      $this->messenger()->addStatus($result->message);
    }
    else {
      $this->messenger()->addError($result->message);
    }

    $isSelf = (int) $this->currentUser->id() === (int) $this->member->id();
    if ($isSelf) {
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
