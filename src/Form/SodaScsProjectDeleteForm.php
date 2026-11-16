<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\EventSubscriber\RedirectResponseSubscriber;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Provides a form for deleting Soda SCS Stack entities.
 */
class SodaScsProjectDeleteForm extends ContentEntityDeleteForm {

  /**
   * Seconds the project lock is held at most.
   *
   * The lock lasts across the batch requests and is released when the batch
   * finishes; an abandoned batch lets it expire after this time.
   */
  private const DEMO_LOCK_TIMEOUT_SECONDS = 3600.0;

  /**
   * Batch steps that remove applications before the batch gives up.
   *
   * Guards against an application that is reported as removed but stays.
   */
  private const MAX_APPLICATION_STEPS = 200;

  /**
   * Name of the project lock this form holds during its batch, if any.
   */
  protected ?string $heldLockName = NULL;

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
   * @param \Drupal\soda_scs_manager\Demo\SodaScsDemoManager $demoManager
   *   Tells demo projects apart from regular ones.
   * @param \Drupal\soda_scs_manager\Service\SodaScsProjectApplicationRemover $applicationRemover
   *   Deletes or detaches the WissKI stacks, databases and triplestores.
   * @param \Drupal\Core\Lock\LockBackendInterface $lock
   *   The persistent lock for the project lock that the demo queue job holds
   *   as well; it must last across the batch requests.
   * @param \Drupal\Core\EventSubscriber\RedirectResponseSubscriber $redirectResponseSubscriber
   *   Lets the failure redirect win over the destination of the delete link.
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
    protected SodaScsDemoManager $demoManager,
    protected SodaScsProjectApplicationRemover $applicationRemover,
    protected LockBackendInterface $lock,
    protected RedirectResponseSubscriber $redirectResponseSubscriber,
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
      $container->get('soda_scs_manager.demo.manager'),
      $container->get('soda_scs_manager.project_application.remover'),
      $container->get('lock.persistent'),
      $container->get('redirect_response_subscriber'),
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
    $description = $this->memberDescription($project) ?? parent::getDescription();
    return $this->t('@description Its WissKI instances, databases and triplestores are deleted as well. Applications of other members stay and only lose the link to this project.', [
      '@description' => $description,
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
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $project */
    $project = $this->entity;
    $removeMembers = (string) $form_state->getValue('remove_members') === '1';
    if (!$this->membersMayBeRemoved($project, $removeMembers)) {
      $form_state->setRebuild();
      return;
    }
    // The lock keeps a second delete of the same project out (two tabs would
    // delete the same stack at once), and for a demo project the demo job: it
    // may still save a WissKI stack after the lookup in the application
    // remover. The job then drops itself once the project is gone.
    $lockName = SodaScsDemoManager::PROJECT_LOCK_PREFIX . $project->id();
    if (!$this->lock->acquire($lockName, self::DEMO_LOCK_TIMEOUT_SECONDS)) {
      $this->messenger()->addError($this->t('The project @project is being set up or deleted right now. Try again when that is done.', ['@project' => $project->label()]));
      $form_state->setRebuild();
      return;
    }
    $this->heldLockName = $lockName;
    // A job attempt that ended just before the lock was taken may have saved
    // fields (e.g. webprotegeProjectId) the form's copy lacks.
    $current = $this->entityTypeManager->getStorage('soda_scs_project')->loadUnchanged($project->id());
    if (!$current instanceof SodaScsProjectInterface) {
      $this->releaseHeldLock();
      $this->messenger()->addStatus($this->t('Project @label has been deleted.', ['@label' => $project->label()]));
      $form_state->setRedirect('soda_scs_manager.dashboard');
      return;
    }
    $this->entity = $current;

    $form_state->setRedirect('soda_scs_manager.dashboard');
    $batch = (new BatchBuilder())
      ->setTitle($this->t('Deleting project @label', ['@label' => $this->entity->label()]))
      ->setInitMessage($this->t('Deleting the applications of the project.'))
      ->setProgressMessage('')
      ->setErrorMessage($this->t('The project could not be deleted.'))
      ->addOperation([$this, 'removeApplicationsStep'], [])
      ->addOperation([$this, 'deleteProjectStep'], [$removeMembers])
      ->setFinishCallback([$this, 'finishDeleteBatch']);
    $this->setBatch($batch->toArray());
  }

  /**
   * Batch step: deletes or detaches one application per request.
   *
   * @param array $context
   *   The batch context.
   */
  public function removeApplicationsStep(array &$context): void {
    $context['sandbox']['steps'] = ($context['sandbox']['steps'] ?? 0) + 1;
    if ($context['sandbox']['steps'] > self::MAX_APPLICATION_STEPS) {
      $this->logger('soda_scs_manager')->error('Gave up deleting the applications of project @project after @steps steps.', [
        '@project' => $this->entity->id(),
        '@steps' => self::MAX_APPLICATION_STEPS,
      ]);
      $context['results']['failure'] = $this->t('Could not delete the applications of project @project. The project was not deleted.', ['@project' => $this->entity->label()]);
      $context['finished'] = 1;
      return;
    }
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project */
    $project = $this->entity;
    $removed = $this->applicationRemover->removeNextApplication($project);
    if (!$removed->success) {
      $context['results']['failure'] = $removed->data['message'] ?? $removed->message;
      $context['finished'] = 1;
      return;
    }
    $application = $removed->data['application'] ?? NULL;
    if ($application === NULL) {
      $context['finished'] = 1;
      return;
    }
    // An application reported as removed that comes back would be deleted
    // again in every request.
    $removedKey = $removed->data['key'] ?? NULL;
    if ($removedKey !== NULL && in_array($removedKey, $context['sandbox']['removed'] ?? [], TRUE)) {
      $this->logger('soda_scs_manager')->error('Application @key of project @project is still there after it was removed.', [
        '@key' => $removedKey,
        '@project' => $this->entity->id(),
      ]);
      $context['results']['failure'] = $this->t('Could not delete the application @application of project @project. The project was not deleted.', [
        '@application' => $application,
        '@project' => $this->entity->label(),
      ]);
      $context['finished'] = 1;
      return;
    }
    $context['sandbox']['removed'][] = $removedKey;
    $context['message'] = $this->t('Removed @application.', ['@application' => $application]);
    // The number of applications left is not known; any value below 1 runs
    // this step again in the next request.
    $context['finished'] = 0.5;
  }

  /**
   * Batch step: deletes the project once its applications are gone.
   *
   * @param bool $removeMembers
   *   Whether the owner confirmed that the listed members are removed.
   * @param array $context
   *   The batch context.
   */
  public function deleteProjectStep(bool $removeMembers, array &$context): void {
    if (isset($context['results']['failure'])) {
      return;
    }
    // Members may have changed while the applications were removed.
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->loadUnchanged($this->entity->id());
    if (!$project instanceof SodaScsProjectInterface) {
      $context['results']['deleted'] = TRUE;
      return;
    }
    $context['results']['deleted'] = $this->deleteProject($project, $removeMembers);
  }

  /**
   * Batch finish callback: releases the demo lock and reports the result.
   *
   * @param bool $success
   *   FALSE when a batch request ended with an error.
   * @param array $results
   *   The results the steps collected.
   *
   * @return \Symfony\Component\HttpFoundation\RedirectResponse|null
   *   The project page when the project still exists; NULL for the
   *   dashboard set as the form's redirect.
   */
  public function finishDeleteBatch(bool $success, array $results): ?RedirectResponse {
    $this->releaseHeldLock();
    if (isset($results['failure'])) {
      $this->messenger()->addError($results['failure']);
    }
    elseif (!$success) {
      $this->messenger()->addError($this->t('The project @label could not be deleted. Some of its applications may already be deleted; try again.', ['@label' => $this->entity->label()]));
    }
    if (!empty($results['deleted'])) {
      return NULL;
    }
    return $this->projectPageRedirect();
  }

  /**
   * Redirect to the project page after a failed delete.
   */
  protected function projectPageRedirect(): ?RedirectResponse {
    // The delete link carries a destination (the dashboard or the page of the
    // card), which batch processing restores and which would win otherwise.
    $this->redirectResponseSubscriber->setIgnoreDestination();
    return new RedirectResponse($this->entity->toUrl()->toString());
  }

  /**
   * Hands the batch to the Batch API; the form submission then runs it.
   *
   * @param array $batch
   *   The batch definition.
   */
  protected function setBatch(array $batch): void {
    batch_set($batch);
  }

  /**
   * Releases the demo lock this form took, if any.
   */
  private function releaseHeldLock(): void {
    if ($this->heldLockName !== NULL) {
      $this->lock->release($this->heldLockName);
      $this->heldLockName = NULL;
    }
  }

  /**
   * Deletes the project with its applications, Team Folder and group.
   *
   * @return bool
   *   FALSE when the project stays; the reason is in the messenger.
   */
  private function deleteProject(SodaScsProjectInterface $project, bool $removeMembers): bool {
    $this->logger('soda_scs_manager')->notice('Deleting project: @label', ['@label' => $project->label()]);

    // Members are detached only after the applications are gone: a failed
    // WissKI or database delete must leave the project and its members.
    if (!$this->membersMayBeRemoved($project, $removeMembers)) {
      return FALSE;
    }
    // The batch removed the applications already; this catches one added
    // meanwhile and drops the components that were detached in earlier
    // requests from the project's list.
    $removed = $this->applicationRemover->removeApplications($project, TRUE);
    if (!$removed->success) {
      $this->messenger()->addError($removed->data['message'] ?? $removed->message);
      return FALSE;
    }
    if (!$this->detachMembersBeforeDelete($project)) {
      return FALSE;
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
    return TRUE;
  }

  /**
   * Drops listed members; membersMayBeRemoved() checked the confirmation.
   *
   * The owner stays.
   *
   * @return bool
   *   FALSE when deletion must stop.
   */
  private function detachMembersBeforeDelete(SodaScsProjectInterface $project): bool {
    foreach ($this->otherMembers($project) as $member) {
      $detached = $this->projectMembershipHelpers->detachMember($project, $member);
      if (!$detached->success) {
        $this->messenger()->addError($detached->message);
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * The confirmation text about listed members, if there are any.
   */
  private function memberDescription(SodaScsProjectInterface $project): ?TranslatableMarkup {
    $names = $this->otherMemberNames($project);
    if ($names === []) {
      return NULL;
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
   * Whether the deletion may go on with the listed members.
   *
   * Plain deletion is refused while other members remain, so their access is
   * not left behind on a deleted project; "remove members and delete" goes on.
   *
   * @return bool
   *   FALSE when deletion must stop.
   */
  private function membersMayBeRemoved(SodaScsProjectInterface $project, bool $removeMembers): bool {
    if ($removeMembers || $this->otherMembers($project) === []) {
      return TRUE;
    }
    $this->messenger()->addError($this->t('“@name” still has members (@members). Remove them before you can delete the project.', [
      '@members' => implode(', ', $this->otherMemberNames($project)),
      '@name' => $project->label(),
    ]));
    return FALSE;
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
