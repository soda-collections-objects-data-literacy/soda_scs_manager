<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ComponentActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * Orchestrates all CRUD actions to SODa SCS components.
 *
 * A component is a single application.
 * It could be:
 *   - an instance of a bare WissKI system
 *     without a database and a triplestore,
 *   - a database in MariaDB
 *   - a repository in OpenGDB
 *   - an account of Nextcloud, Jupyterhub or WebProtégé.
 *   - a folder with user permissions on the filesystem.
 */
#[Autowire(service: 'soda_scs_manager.component.actions')]
class SodaScsComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The SCS filesystem actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsFilesystemComponentActions;

  /**
   * The SCS sql actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

  /**
   * The SCS triplestore actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

  /**
   * The SCS wisski actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWisskiComponentActions;

  /**
   * The SCS webprotege actions service.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWebprotegeComponentActions;

  /**
   * Class constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.filesystem_component.actions')]
    SodaScsComponentActionsInterface $sodaScsFilesystemComponentActions,
    #[Autowire(service: 'soda_scs_manager.sql_component.actions')]
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    #[Autowire(service: 'soda_scs_manager.triplestore_component.actions')]
    SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions,
    #[Autowire(service: 'soda_scs_manager.wisski_component.actions')]
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    #[Autowire(service: 'soda_scs_manager.webprotege_component.actions')]
    SodaScsComponentActionsInterface $sodaScsWebprotegeComponentActions,
    TranslationInterface $stringTranslation,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsFilesystemComponentActions = $sodaScsFilesystemComponentActions;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->sodaScsWebprotegeComponentActions = $sodaScsWebprotegeComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Creates a Component.
   *
   * Delegates the creation to the appropriate component actions service.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $entity
   *   The SODa SCS Component entity.
   *
   * @return array
   *   The result of the request.
   */
  public function createComponent(SodaScsComponentInterface $entity): array {
    switch ($entity->bundle()) {
      case 'soda_scs_filesystem_component':
        return $this->sodaScsFilesystemComponentActions->createComponent($entity);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->createComponent($entity);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->createComponent($entity);

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->createComponent($entity);

      case 'soda_scs_webprotege_component':
        return $this->sodaScsWebprotegeComponentActions->createComponent($entity);

      default:
        return [];
    }
  }

  /**
   * Get all SODa SCS components.
   *
   * @return array
   *   The result of the request.
   */
  public function getComponents(): array {
    return [];

  }

  /**
   * Read a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function getComponent($component): array {
    return [
      'message' => '',
      'data' => [],
      'error' => 'Not yet implemented.',
      'success' => FALSE,
    ];
  }

  /**
   * Updates a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   */
  public function updateComponent($component): array {
    switch ($component->bundle()) {
      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->updateComponent($component);

      case 'soda_scs_webprotege_component':
        return $this->sodaScsWebprotegeComponentActions->updateComponent($component);

      default:
        return [];
    }
  }

  /**
   * Deletes a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result of the request.
   *
   * @todo Check if referenced components are deleted as well.
   */
  public function deleteComponent(SodaScsComponentInterface $component): array {
    // @todo slim down if there is no more logic
    switch ($component->bundle()) {
      case 'soda_scs_filesystem_component':
        return $this->sodaScsFilesystemComponentActions->deleteComponent($component);

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->deleteComponent($component);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->deleteComponent($component);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->deleteComponent($component);

      case 'soda_scs_webprotege_component':
        return $this->sodaScsWebprotegeComponentActions->deleteComponent($component);

      default:
        return [
          'message' => $this->t('Could not delete component of type @bundle.', ['@bundle' => $component->bundle()]),
          'data' => [],
          'success' => FALSE,
          'error' => 'Component type not supported for deletion.',
        ];
    }
  }

  /**
   * Create a snapshot of a component.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   * @param string $snapshotMachineName
   *   The machine name of the snapshot.
   * @param int $timestamp
   *   The timestamp of the snapshot.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result of the request.
   */
  public function createSnapshot(SodaScsComponentInterface $component, string $snapshotMachineName, int $timestamp): SodaScsResult {
    switch ($component->bundle()) {
      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->createSnapshot($component, $snapshotMachineName, $timestamp);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->createSnapshot($component, $snapshotMachineName, $timestamp);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->createSnapshot($component, $snapshotMachineName, $timestamp);

      default:
        return SodaScsResult::failure(
          message: 'Component type not supported for snapshot creation.',
          error: 'Component type not supported for snapshot creation.',
        );
    }
  }

  /**
   * Restore Component from Snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsSnapshotInterface $snapshot
   *   The SODa SCS Snapshot.
   * @param string $tempDir
   *   The path to the temporary directory,
   *   if the files are unpacked in case of stack restoration.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Result information with restored component.
   */
  public function restoreFromSnapshot(SodaScsSnapshotInterface $snapshot, ?string $tempDir): SodaScsResult {
    $component = $snapshot->get('snapshotOfComponent')->entity;
    switch ($component->bundle()) {
      case 'soda_scs_filesystem_component':
        return $this->sodaScsFilesystemComponentActions->restoreFromSnapshot($snapshot, $tempDir);

      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions->restoreFromSnapshot($snapshot, $tempDir);

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions->restoreFromSnapshot($snapshot, $tempDir);

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions->restoreFromSnapshot($snapshot, $tempDir);

      default:
        return SodaScsResult::failure(
          message: 'Component type not supported for restoration.',
          error: 'Component type not supported for restoration.',
        );
    }
  }

}
