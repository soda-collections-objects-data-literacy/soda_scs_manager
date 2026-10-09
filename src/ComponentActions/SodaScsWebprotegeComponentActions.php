<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectApplicationHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * WebProtégé component actions.
 *
 * A WebProtégé component stands for the ontology project of one SCS project.
 * It is owned by the project owner; members get EDIT on the ontology project
 * through the project membership helpers.
 */
final class SodaScsWebprotegeComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * Class constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->logger = $loggerFactory->get('soda_scs_manager');
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create WebProtégé Component.
   *
   * Requires a project, allows one WebProtégé component per project, and
   * creates (or reuses) the project's WebProtégé ontology project.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS entity.
   *
   * @return array
   *   The created component.
   */
  public function createComponent(SodaScsComponentInterface $entity): array {
    $project = $this->projectApplicationHelpers->loadPartOfProject($entity);
    if (!$project instanceof SodaScsProjectInterface) {
      return [
        'message' => $this->t('WebProtégé can only be added to a project.'),
        'data' => [],
        'success' => FALSE,
        'error' => 'WebProtégé component without project.',
        'statusCode' => 400,
      ];
    }

    if ($this->projectApplicationHelpers->loadProjectApplication($project->id(), 'soda_scs_component', 'soda_scs_webprotege_component', $entity->id()) !== NULL) {
      return [
        'message' => $this->t('This project already has WebProtégé.'),
        'data' => [],
        'success' => FALSE,
        'error' => 'Project already has a WebProtégé component.',
        'statusCode' => 409,
      ];
    }

    try {
      $entity->set('owner', $project->getOwnerId());
      $entity->save();

      $ontologyResult = $this->webprotegeHelpers->createOntologyProject($project);
      if (!$ontologyResult->success) {
        $entity->delete();
        return [
          'message' => $ontologyResult->message,
          'data' => [],
          'success' => FALSE,
          'error' => $ontologyResult->error,
          'statusCode' => 502,
        ];
      }

      return [
        'message' => $this->t('Created WebProtégé component.'),
        'data' => [
          'component' => $entity,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 201,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to create WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to create WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Snapshots are not supported for WebProtégé components.',
      error: 'Not supported.',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    try {
      $projectId = $this->projectApplicationHelpers->loadPartOfProject($component)?->id();
      $component->delete();
      if ($projectId !== NULL) {
        $this->trashOntologyProject($projectId);
      }
      return [
        'message' => $this->t('Deleted WebProtégé component.'),
        'data' => [
          'componentId' => $component->id(),
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to delete WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to delete WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * Moves the project's ontology project to trash and unlinks it.
   *
   * On failure the link is kept, so adding WebProtégé again reuses it.
   */
  private function trashOntologyProject(int|string $projectId): void {
    // Reload: deleting the component already saved the project.
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load($projectId);
    if (!$project instanceof SodaScsProjectInterface) {
      return;
    }

    $trashResult = $this->webprotegeHelpers->trashOntologyProject($project);
    if (!$trashResult->success) {
      $this->messenger->addWarning($trashResult->message);
      return;
    }

    if (!empty($trashResult->data['removed'])) {
      $project->set('webprotegeProjectId', NULL);
      $project->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getComponent(SodaScsComponentInterface $props): array {
    return [
      'message' => $this->t('Retrieved WebProtégé component.'),
      'data' => [
        'component' => $props,
      ],
      'success' => TRUE,
      'error' => NULL,
      'statusCode' => 200,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getComponents(): array {
    try {
      $storage = $this->entityTypeManager->getStorage('soda_scs_component');
      $ids = $storage->getQuery()
        ->accessCheck(FALSE)
        ->condition('type', 'soda_scs_webprotege_component')
        ->execute();
      $components = $ids ? $storage->loadMultiple($ids) : [];
      return [
        'message' => $this->t('Retrieved WebProtégé components.'),
        'data' => [
          'components' => $components,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to list WebProtégé components: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to retrieve WebProtégé components.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

  /**
   * {@inheritdoc}
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDir): SodaScsResult {
    return SodaScsResult::failure(
      message: 'Restore from snapshot is not supported for WebProtégé components.',
      error: 'Not supported.',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function updateComponent(SodaScsComponentInterface $component): array {
    try {
      $component->save();
      return [
        'message' => $this->t('Updated WebProtégé component.'),
        'data' => [
          'component' => $component,
        ],
        'success' => TRUE,
        'error' => NULL,
        'statusCode' => 200,
      ];
    }
    catch (\Throwable $e) {
      $this->logger->error('Failed to update WebProtégé component: @error', ['@error' => $e->getMessage()]);
      return [
        'message' => $this->t('Failed to update WebProtégé component.'),
        'data' => [],
        'success' => FALSE,
        'error' => $e->getMessage(),
        'statusCode' => 500,
      ];
    }
  }

}
