<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\StackActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\Service\NextcloudProjectMountManager;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

/**
 * Handles the jupyter stack actions.
 */
class SodaScsJupyterStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The SCS stack helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The SCS jupyter actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsJupyterComponentActions;

  /**
   * Class constructor.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    #[Autowire(service: 'soda_scs_manager.stack.helpers')]
    SodaScsStackHelpers $sodaScsStackHelpers,
    TranslationInterface $stringTranslation,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_project_mount.manager')]
    protected NextcloudProjectMountManager $projectMountManager,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.docker_volumes_service.actions')]
    protected SodaScsServiceRequestInterface $dockerVolumesServiceActions,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a jupyter stack.
   *
   * The stack marks that a project uses JupyterHub: one per project, owned by
   * the project owner. The project gets one shared JupyterLab (hub user
   * project-<gid>) that binds the project mount of the Nextcloud service
   * account; all members work in it together.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The component.
   *
   * @return array
   *   The result.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsStackInterface $stack): array {
    $project = $this->projectApplicationHelpers->loadPartOfProject($stack);
    if ($project !== NULL && $this->projectApplicationHelpers->loadProjectApplication($project->id(), 'soda_scs_stack', 'soda_scs_jupyter_stack', $stack->id()) !== NULL) {
      return [
        'message' => $this->t('This project already has JupyterHub.'),
        'data' => [
          'stack' => NULL,
        ],
        'success' => FALSE,
        'error' => 'Project already has a Jupyter stack.',
      ];
    }

    try {
      if ($project !== NULL) {
        // Without the mount group the service account cannot see the folder.
        $groups = $this->projectHelpers->syncProjectTeamFolderGroups($project, TRUE);
        if (!$groups->success) {
          $this->messenger->addError($groups->message);
          return [
            'message' => $groups->message,
            'data' => [
              'stack' => NULL,
            ],
            'success' => FALSE,
            'error' => $groups->error,
          ];
        }
        $stack->set('owner', $project->getOwnerId());
      }
      $stack->save();
      if ($project !== NULL) {
        // The mount reconcile (cron, Drush) retries a failed mount.
        if (!$this->projectMountManager->ensureProjectMounted($project)) {
          $this->messenger->addWarning($this->t('The project folder is not ready for JupyterHub yet. It is mounted automatically on the next retry.'));
        }
        // drush scs:jupyter-backfill-project-servers retries a failed setup.
        $hubUser = $this->jupyterHelpers->ensureProjectServerUser($project);
        if (!$hubUser->success) {
          $this->messenger->addWarning($hubUser->message);
        }
      }
      return [
        'message' => $this->t('Created jupyter stack: %message', ['%message' => $stack->label()]),
        'data' => [
          'stack' => $stack,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Jupyter stack creation failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not create jupyter stack. See logs for more details."));
      return [
        'message' => $this->t('Could not create stack: %message', ['%message' => $e->getMessage()]),
        'data' => [
          'stack' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

  /**
   * Create a snapshot of a jupyter stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack.
   * @param string $snapshotMachineName
   *   The snapshot machine name.
   * @param int $timestamp
   *   The timestamp.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result.
   */
  public function createSnapshot(SodaScsStackInterface $stack, string $snapshotMachineName, int $timestamp): SodaScsResult {
    return SodaScsResult::success(
      data: [
        'jupyterComponentSnapshot' => [],
        'snapshotMachineName' => $snapshotMachineName,
        'timestamp' => $timestamp,
      ],
      message: 'Jupyter stack snapshot created.',
    );
  }

  /**
   * Read all Jupyter stacks.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result array.
   */
  public function getStacks($bundle, $options): array {
    return [];
  }

  /**
   * Read a jupyter stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function getStack($component): array {
    return [];
  }

  /**
   * Update a jupyter stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function updateStack($component): array {
    return [];
  }

  /**
   * Delete a jupyter stack.
   *
   * Stops the project's server, deletes its hub user and home volume,
   * unmounts the project folder and removes the service account's access.
   * The files in the Team Folder stay.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack entity.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *
   * @return array
   *   The result.
   */
  public function deleteStack(SodaScsStackInterface $stack): array {
    try {
      $project = $this->projectApplicationHelpers->loadPartOfProject($stack);
      if ($project !== NULL) {
        $hubUser = $this->jupyterHelpers->removeProjectServerUser($project);
        if (!$hubUser->success) {
          // Keep the stack so removing JupyterHub can be retried.
          $this->messenger->addError($hubUser->message);
          return [
            'message' => $hubUser->message,
            'data' => [
              'stack' => $stack,
            ],
            'success' => FALSE,
            'error' => $hubUser->error,
          ];
        }
        $this->projectMountManager->ensureProjectUnmounted($project);
      }
      $stack->delete();
      if ($project !== NULL) {
        $groups = $this->projectHelpers->syncProjectTeamFolderGroups($project, FALSE);
        if (!$groups->success) {
          $this->messenger->addWarning($groups->message);
        }
        $this->deleteProjectVolume($project);
      }
      return [
        'message' => 'Deleted jupyter stack.',
        'data' => [
          'stack' => $stack,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];

    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        'Jupyter component deletion failed: @message',
        ['@message' => $e->getMessage()],
        LogLevel::ERROR
      );
      $this->messenger->addError($this->t("Could not delete jupyter component. See logs for more details."));
      return [
        'message' => 'Could not delete jupyter component.',
        'data' => [
          'jupyterComponentDeleteResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

  /**
   * Restore a Jupyter stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Not implemented.',
      error: 'Jupyter stack restored from snapshot failed.',
    );
  }

  /**
   * Deletes the home volume of the project's server (best effort).
   *
   * The server is already stopped and DockerSpawner removed its container.
   */
  protected function deleteProjectVolume(SodaScsProjectInterface $project): void {
    $volume = SodaScsJupyterHelpers::projectVolumeName($project);
    $response = $this->dockerVolumesServiceActions->makeRequest(
      $this->dockerVolumesServiceActions->buildDeleteRequest(['machineName' => $volume]),
    );
    if (!$response['success'] && (int) ($response['statusCode'] ?? 0) !== 404) {
      $this->loggerFactory->get('soda_scs_manager')->warning('Could not delete Jupyter volume @volume: @error', [
        '@volume' => $volume,
        '@error' => $response['error'] ?? '',
      ]);
      $this->messenger->addWarning($this->t('The home directory of the project notebook server could not be deleted. See logs for details.'));
    }
  }

}
