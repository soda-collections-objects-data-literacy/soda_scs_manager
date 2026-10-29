<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting Soda SCS Stack entities.
 */
class SodaScsProjectDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS Project Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers
   */
  protected SodaScsProjectHelpers $sodaScsProjectHelpers;

  /**
   * WebProtégé ontology project helper.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers
   */
  protected SodaScsWebprotegeHelpers $webprotegeHelpers;

  /**
   * Looks up applications that exist once per project.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers
   */
  protected SodaScsProjectApplicationHelpers $projectApplicationHelpers;

  /**
   * Constructs a new SodaScsStackDeleteForm.
   *
   * @param Drupal\Core\Entity\EntityRepositoryInterface $entity_repository
   *   The entity repository.
   * @param Drupal\Core\Entity\EntityTypeBundleInfoInterface $entity_type_bundle_info
   *   The entity type bundle info.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers $sodaScsProjectHelpers
   *   The Soda SCS Project Helpers service.
   * @param Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers $projectApplicationHelpers
   *   Looks up applications that exist once per project.
   * @param \Drupal\soda_scs_manager\Service\NextcloudProjectMountManager $projectMountManager
   *   Project Team Folder mounts of the Nextcloud service account.
   * @param \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface $jupyterStackActions
   *   Removes the project's JupyterHub (server, hub user, volume, mount).
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers $projectMembershipHelpers
   *   Removes listed members and their service access.
   */
  public function __construct(
    EntityRepositoryInterface $entity_repository,
    EntityTypeBundleInfoInterface $entity_type_bundle_info,
    SodaScsProjectHelpers $sodaScsProjectHelpers,
    SodaScsWebprotegeHelpers $webprotegeHelpers,
    TimeInterface $time,
    SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    protected NextcloudProjectMountManager $projectMountManager,
    protected SodaScsStackActionsInterface $jupyterStackActions,
    protected SodaScsProjectMembershipHelpers $projectMembershipHelpers,
  ) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityRepository = $entity_repository;
    $this->entityTypeBundleInfo = $entity_type_bundle_info;
    $this->sodaScsProjectHelpers = $sodaScsProjectHelpers;
    $this->webprotegeHelpers = $webprotegeHelpers;
    $this->time = $time;
    $this->projectApplicationHelpers = $projectApplicationHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('soda_scs_manager.project.helpers'),
      $container->get('soda_scs_manager.webprotege.helpers'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.project_application.helpers'),
      $container->get('soda_scs_manager.nextcloud_project_mount.manager'),
      $container->get('soda_scs_manager.jupyter_stack.actions'),
      $container->get('soda_scs_manager.project_membership.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_manager_project_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete project: @label?', ['@label' => $this->entity->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('soda_scs_manager.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $project = $this->entity;
    if (!$project instanceof SodaScsProjectInterface) {
      return parent::getDescription();
    }
    $names = $this->otherMemberNames($project);
    if ($names === []) {
      return parent::getDescription();
    }
    if ($this->getRequest()->query->get('remove_members') === '1') {
      return $this->t('This removes @members from “@name” and then deletes the project. This action cannot be undone.', [
        '@members' => implode(', ', $names),
        '@name' => $project->label(),
      ]);
    }
    return $this->t('“@name” still has members (@members). Remove them before you can delete the project.', [
      '@members' => implode(', ', $names),
      '@name' => $project->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $project = $this->entity;
    if (!$project instanceof SodaScsProjectInterface || $this->otherMembers($project) === []) {
      return $form;
    }

    // The dialog posts this query so the background submit detaches members.
    // Without it the plain delete button stays unavailable.
    if ($this->getRequest()->query->get('remove_members') === '1') {
      $form['remove_members'] = [
        '#type' => 'hidden',
        '#default_value' => '1',
      ];
      $form['actions']['submit']['#value'] = $this->t('Remove members and delete');
      return $form;
    }

    $form['actions']['submit']['#access'] = FALSE;
    $form['actions']['remove_and_delete'] = [
      '#type' => 'submit',
      '#value' => $this->t('Remove members and delete'),
      '#submit' => ['::removeMembersAndDelete'],
      '#button_type' => 'danger',
    ];
    return $form;
  }

  /**
   * Detaches remaining members, then runs the project deletion.
   */
  public function removeMembersAndDelete(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue('remove_members', '1');
    $this->submitForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting project: @label', ['@label' => $this->entity->label()]);

    // Construct properties.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;
    if (!$this->detachMembersBeforeDelete($project, $form_state)) {
      return;
    }

    // JupyterHub first: it stops the shared server and needs the Team Folder
    // to remove the project mount group.
    foreach ($this->projectApplicationHelpers->loadProjectApplications($project->id()) as $application) {
      if ($application->bundle() === 'soda_scs_jupyter_stack') {
        $this->jupyterStackActions->deleteStack($application);
      }
    }
    // Unmount before the Team Folder disappears under the mount.
    $this->projectMountManager->ensureProjectUnmounted($project);

    // Delete Nextcloud Team Folder first (lookup uses Drupal project id).
    $deleteFolderResult = $this->sodaScsProjectHelpers->deleteProjectTeamFolder($project);
    if (!$deleteFolderResult->success) {
      $this->messenger()->addWarning($this->t('Could not delete Nextcloud Team Folder for project @label: @error', [
        '@label' => $project->label(),
        '@error' => $deleteFolderResult->error,
      ]));
    }

    $trashWebprotegeResult = $this->webprotegeHelpers->trashOntologyProject($project);
    if (!$trashWebprotegeResult->success) {
      $this->messenger()->addWarning($this->t('Could not delete the WebProtégé project for @label: @error', [
        '@label' => $project->label(),
        '@error' => $trashWebprotegeResult->error,
      ]));
    }

    // The WebProtégé entity only exists for this project; its ontology project
    // is trashed above.
    foreach ($this->projectApplicationHelpers->loadProjectApplications($project->id()) as $application) {
      $application->delete();
    }

    // Delete the project group from Keycloak.
    $this->sodaScsProjectHelpers->deleteProjectGroup($project);

    // Delete the whole stack with database.
    $project->delete();

    $this->messenger()->addStatus($this->t('Project @label has been deleted.', ['@label' => $project->label()]));
    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

  /**
   * Drops listed members when that was confirmed. The owner stays.
   *
   * Plain deletion is refused while other members remain, so their access is
   * not left behind on a deleted project.
   *
   * @return bool
   *   FALSE when deletion must stop.
   */
  private function detachMembersBeforeDelete(SodaScsProjectInterface $project, FormStateInterface $form_state): bool {
    $members = $this->otherMembers($project);
    if ($members === []) {
      return TRUE;
    }
    if ((string) $form_state->getValue('remove_members') !== '1') {
      $this->messenger()->addError($this->t('“@name” still has members (@members). Remove them before you can delete the project.', [
        '@members' => implode(', ', $this->otherMemberNames($project)),
        '@name' => $project->label(),
      ]));
      $form_state->setRebuild();
      return FALSE;
    }

    foreach ($members as $member) {
      $detached = $this->projectMembershipHelpers->detachMember($project, $member);
      if (!$detached->success) {
        $this->messenger()->addError($detached->message);
        $form_state->setRebuild();
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Display names of listed members other than the owner.
   *
   * @return list<string>
   *   Names in field order.
   */
  private function otherMemberNames(SodaScsProjectInterface $project): array {
    $names = [];
    foreach ($this->otherMembers($project) as $member) {
      $names[] = $member->getDisplayName();
    }
    return $names;
  }

  /**
   * Listed members other than the project owner.
   *
   * @return list<\Drupal\user\UserInterface>
   *   Members who still have access through this project.
   */
  private function otherMembers(SodaScsProjectInterface $project): array {
    if (!$project->hasField('members') || $project->get('members')->isEmpty()) {
      return [];
    }
    $ownerId = (int) $project->getOwnerId();
    $members = [];
    foreach ($project->get('members')->referencedEntities() as $member) {
      if (!$member instanceof UserInterface || (int) $member->id() === $ownerId) {
        continue;
      }
      $members[] = $member;
    }
    return $members;
  }

}
