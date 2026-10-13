<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Lock\LockBackendInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectDbAccessHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectNameHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Creates a project with its Keycloak group, DB access and Team Folder.
 *
 * Shared by the project create form and the co-working intro so both enforce
 * the same unique-name rule and roll back the same way.
 */
class SodaScsProjectProvisioner {

  use StringTranslationTrait;

  /**
   * Seconds to hold the per-name lock while a project is created.
   */
  protected const LOCK_TIMEOUT = 60.0;

  /**
   * Constructs the provisioner.
   */
  public function __construct(
    #[Autowire(service: 'lock')]
    protected LockBackendInterface $lock,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.project_db_access.helpers')]
    protected SodaScsProjectDbAccessHelpers $projectDbAccessHelpers,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.project_name.helpers')]
    protected SodaScsProjectNameHelpers $projectNameHelpers,
    TranslationInterface $stringTranslation,
  ) {
    $this->setStringTranslation($stringTranslation);
  }

  /**
   * Saves a new project and provisions its remote resources.
   *
   * Without a Keycloak group the project is unusable, and a Team Folder name
   * that Nextcloud already uses would mount someone else's files. Both cases
   * delete the project again. Other failures (member sync, Nextcloud down)
   * keep the project and are reported as warnings; the backfill command can
   * repair them later.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The unsaved project with label and owner set.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success, data holds 'project' and a list of translated 'warnings'.
   */
  public function provision(SodaScsProjectInterface $project): SodaScsResult {
    $label = trim((string) $project->label());
    $check = $this->projectNameHelpers->checkLabel($label);
    if ($check['error'] !== NULL) {
      return $this->nameRejected($check['error']);
    }

    $lockName = 'soda_scs_manager.project_name:' . $check['machineName'];
    if (!$this->lock->acquire($lockName, self::LOCK_TIMEOUT)) {
      return SodaScsResult::failure(
        'Project name lock is held for ' . $check['machineName'],
        (string) $this->t('A project with this name is being created right now. Please try again in a moment.'),
        log: FALSE,
      );
    }

    try {
      // A concurrent request may have saved the same name before we got the
      // lock.
      if ($this->projectNameHelpers->isMachineNameTaken($check['machineName'])) {
        return $this->nameRejected(SodaScsProjectNameHelpers::ERROR_TAKEN);
      }
      $project->save();
      return $this->provisionRemoteResources($project);
    }
    finally {
      $this->lock->release($lockName);
    }
  }

  /**
   * Result for a label that fails the unique-name rule.
   */
  protected function nameRejected(string $error): SodaScsResult {
    return SodaScsResult::failure(
      'Project name rejected: ' . $error,
      (string) $this->projectNameHelpers->errorMessage($error),
      log: FALSE,
    );
  }

  /**
   * Creates the Keycloak group, syncs members and creates the Team Folder.
   */
  protected function provisionRemoteResources(SodaScsProjectInterface $project): SodaScsResult {
    $label = (string) $project->label();
    $warnings = [];

    try {
      $groupResult = $this->projectHelpers->createProjectGroup($project);
    }
    catch (\Throwable $e) {
      $groupResult = SodaScsResult::failure($e->getMessage(), '', log: FALSE);
    }
    if (!$groupResult->success) {
      $this->logger->error('Keycloak group creation failed for project @project (@id): @error', [
        '@project' => $label,
        '@id' => $project->id(),
        '@error' => (string) $groupResult->error,
      ]);
      $this->rollBack($project, FALSE);
      return SodaScsResult::failure(
        (string) $groupResult->error,
        (string) $this->t('Could not create the project @project because its permission group could not be created. Please try again later.', [
          '@project' => $label,
        ]),
        log: FALSE,
      );
    }

    $keycloakUuid = $groupResult->data['keycloakGroupData']->uuid ?? NULL;
    if (is_string($keycloakUuid) && $keycloakUuid !== '') {
      $project->set('keycloakUuid', $keycloakUuid);
      $project->save();
    }

    $syncResult = $this->projectHelpers->syncKeycloakGroupMembers($project);
    if ($syncResult->success) {
      $this->projectDbAccessHelpers->syncProjectMembersDbAccess($project);
    }
    else {
      $this->logger->error('Failed to sync keycloak group members for project @project: @error', [
        '@project' => $label,
        '@error' => (string) $syncResult->error,
      ]);
      $warnings[] = (string) $this->t('Failed to sync keycloak group members for project @project: See logs for details.', [
        '@project' => $label,
      ]);
    }

    $folderResult = $this->projectHelpers->createProjectTeamFolder($project);
    if (!$folderResult->success && !empty($folderResult->data['conflict'])) {
      $this->rollBack($project, TRUE);
      return SodaScsResult::failure(
        'Project name taken in Nextcloud: ' . $folderResult->error,
        (string) $this->t('The name @project is already used by a folder in Nextcloud. Please choose a different name.', [
          '@project' => $label,
        ]),
        log: FALSE,
      );
    }
    if (!$folderResult->success) {
      $this->logger->error('Failed to create Nextcloud Team Folder for project @project: @error', [
        '@project' => $label,
        '@error' => (string) $folderResult->error,
      ]);
      $warnings[] = (string) $this->t('Failed to create Nextcloud Team Folder for project @project: @error', [
        '@project' => $label,
        '@error' => (string) $folderResult->error,
      ]);
    }

    return SodaScsResult::success(
      data: [
        'project' => $project,
        'warnings' => $warnings,
      ],
      message: (string) $this->t('Project @project has been created.', [
        '@project' => $label,
      ]),
    );
  }

  /**
   * Deletes a half-created project (best effort).
   */
  protected function rollBack(SodaScsProjectInterface $project, bool $deleteKeycloakGroup): void {
    try {
      if ($deleteKeycloakGroup) {
        $this->projectHelpers->deleteProjectGroup($project);
      }
      $project->delete();
    }
    catch (\Throwable $e) {
      $this->logger->error('Rollback of project @project (@id) failed: @error', [
        '@project' => (string) $project->label(),
        '@id' => $project->id(),
        '@error' => $e->getMessage(),
      ]);
    }
  }

}
