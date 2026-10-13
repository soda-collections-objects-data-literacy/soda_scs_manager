<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
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
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, SodaScsProjectHelpers $sodaScsProjectHelpers, SodaScsWebprotegeHelpers $webprotegeHelpers, TimeInterface $time, SodaScsProjectApplicationHelpers $projectApplicationHelpers, protected NextcloudProjectMountManager $projectMountManager, protected SodaScsStackActionsInterface $jupyterStackActions) {
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
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Add custom logic before deletion.
    \Drupal::logger('soda_scs_manager')->notice('Deleting project: @label', ['@label' => $this->entity->label()]);

    // Construct properties.
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProject $entity */
    $project = $this->entity;

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

}
