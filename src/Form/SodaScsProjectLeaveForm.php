<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityConfirmFormBase;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for leaving a Soda SCS Project.
 */
final class SodaScsProjectLeaveForm extends ContentEntityConfirmFormBase {

  /**
   * The Soda SCS Project Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * The current user service.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected AccountProxyInterface $currentUser;

  /**
   * Constructs a new SodaScsProjectLeaveForm.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $sodaScsProjectHelpers
   *   The Soda SCS Project Helpers service.
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user service.
   */
  public function __construct(
    EntityRepositoryInterface $entityRepository,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    TimeInterface $time,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    AccountProxyInterface $currentUser,
  ) {
    parent::__construct($entityRepository, $entityTypeBundleInfo, $time);
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->currentUser = $currentUser;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.project.helpers'),
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

    // Verify the user is a member (not owner).
    $isOwner = (int) $project->getOwnerId() === $currentUserId;
    if ($isOwner) {
      $this->messenger()->addError($this->t('Project owners cannot leave their own project. Please transfer ownership or delete the project instead.'));
      return;
    }

    // Check if user is actually a member.
    $isMember = FALSE;
    if ($project->hasField('members') && !$project->get('members')->isEmpty()) {
      foreach ($project->get('members')->getValue() as $memberItem) {
        if ((int) ($memberItem['target_id'] ?? 0) === $currentUserId) {
          $isMember = TRUE;
          break;
        }
      }
    }

    if (!$isMember) {
      $this->messenger()->addError($this->t('You are not a member of this project.'));
      return;
    }

    // Remove the current user from the members field.
    $members = $project->get('members')->getValue();
    $updatedMembers = array_filter($members, function ($item) use ($currentUserId) {
      return (int) ($item['target_id'] ?? 0) !== $currentUserId;
    });

    $project->set('members', array_values($updatedMembers));

    try {
      $project->save();
      $this->sodaScsProjectHelpers->syncKeycloakGroupMembers($project);
      $this->messenger()->addStatus($this->t('You have left the project @project.', [
        '@project' => $project->label(),
      ]));
    }
    catch (\Exception $e) {
      \Drupal::logger('soda_scs_manager')->error('Failed to leave project: @message', [
        '@message' => $e->getMessage(),
      ]);
      $this->messenger()->addError($this->t('Failed to leave the project. Please try again.'));
      return;
    }

    // Redirect to the project list.
    $form_state->setRedirect('entity.soda_scs_project.collection');
  }

}

