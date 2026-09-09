<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for leaving a Soda SCS Project.
 */
final class SodaScsProjectLeaveForm extends ContentEntityConfirmFormBase {

  /**
   * Constructs a new SodaScsProjectLeaveForm.
   */
  public function __construct(
    EntityRepositoryInterface $entityRepository,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    TimeInterface $time,
    EntityTypeManagerInterface $entityTypeManager,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
    protected AccountProxyInterface $currentUser,
  ) {
    parent::__construct($entityRepository, $entityTypeBundleInfo, $time);
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.project_membership.helpers'),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_project_leave_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    return $this->t('Are you sure you want to leave project: @label?', [
      '@label' => $project->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('You will no longer have access to this project after leaving. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    return new Url('entity.soda_scs_project.canonical', [
      'soda_scs_project' => $project->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    $currentUserId = (int) $this->currentUser->id();

    if ((int) $project->getOwnerId() === $currentUserId) {
      $this->messenger()->addError($this->t('Project owners cannot leave their own project. Please transfer ownership or delete the project instead.'));
      return;
    }

    /** @var \Drupal\user\UserInterface|null $currentUserEntity */
    $currentUserEntity = $this->entityTypeManager->getStorage('user')->load($currentUserId);
    if (!$currentUserEntity instanceof UserInterface) {
      $this->messenger()->addError($this->t('Unable to load the current user account.'));
      return;
    }

    $result = $this->projectMembershipHelpers->removeMember($project, $currentUserEntity, $this->currentUser);
    if ($result->success) {
      $this->messenger()->addStatus($result->message);
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }

    $this->messenger()->addError($result->message);
  }

}
