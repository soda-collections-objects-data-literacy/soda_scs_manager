<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Helper class for snapshot integrity checks and cleanup.
 */
class SodaScsSnapshotIntegrityHelpers {

  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

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
   * Constructs a new SodaScsSnapshotIntegrityHelpers.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $loggerFactory
   *   The logger factory.
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger service.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
  }

  /**
   * Find all dangling snapshot entities.
   *
   * @return array
   *   Array of dangling snapshot information.
   */
  public function findDanglingSnapshots(): array {
    $danglingSnapshots = [];
    $logger = $this->loggerFactory->get('soda_scs_manager');

    try {
      // Get all snapshots.
      $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');
      $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
      $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');

      $query = $snapshotStorage->getQuery()
        ->accessCheck(FALSE);
      $snapshotIds = $query->execute();

      if (empty($snapshotIds)) {
        return [];
      }

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot[] $snapshots */
      $snapshots = $snapshotStorage->loadMultiple($snapshotIds);

      foreach ($snapshots as $snapshot) {
        $snapshotInfo = [
          'id' => $snapshot->id(),
          'label' => $snapshot->getLabel(),
          'created' => $snapshot->getCreatedTime(),
          'issues' => [],
          'referenced_by' => [],
          'references' => [],
        ];

        // Check what this snapshot references.
        if (!$snapshot->get('snapshotOfStack')->isEmpty()) {
          $stackId = $snapshot->get('snapshotOfStack')->target_id;
          $snapshotInfo['references']['stack'] = $stackId;

          $stack = $stackStorage->load($stackId);
          if (!$stack) {
            $snapshotInfo['issues'][] = 'References non-existent stack: ' . $stackId;
          }
        }

        if (!$snapshot->get('snapshotOfComponent')->isEmpty()) {
          $componentId = $snapshot->get('snapshotOfComponent')->target_id;
          $snapshotInfo['references']['component'] = $componentId;

          $component = $componentStorage->load($componentId);
          if (!$component) {
            $snapshotInfo['issues'][] = 'References non-existent component: ' . $componentId;
          }
        }

        // Check if any stacks reference this snapshot.
        $stackQuery = $stackStorage->getQuery()
          ->condition('snapshots', $snapshot->id())
          ->accessCheck(FALSE);
        $referencingStacks = $stackQuery->execute();

        if (!empty($referencingStacks)) {
          $snapshotInfo['referenced_by']['stacks'] = $referencingStacks;
        }

        // Check if any components reference this snapshot.
        $componentQuery = $componentStorage->getQuery()
          ->condition('snapshots', $snapshot->id())
          ->accessCheck(FALSE);
        $referencingComponents = $componentQuery->execute();

        if (!empty($referencingComponents)) {
          $snapshotInfo['referenced_by']['components'] = $referencingComponents;
        }

        // Determine if this is a dangling snapshot.
        $isDangling = FALSE;

        // No entity references this snapshot.
        if (empty($snapshotInfo['referenced_by'])) {
          $snapshotInfo['issues'][] = 'Not referenced by any stack or component';
          $isDangling = TRUE;
        }

        // Snapshot references non-existent entities.
        if (!empty($snapshotInfo['issues']) &&
            (strpos(implode('', $snapshotInfo['issues']), 'non-existent') !== FALSE)) {
          $isDangling = TRUE;
        }

        // Check for circular or broken references.
        if (!empty($snapshotInfo['referenced_by']) && !empty($snapshotInfo['references'])) {
          foreach ($snapshotInfo['referenced_by'] as $type => $ids) {
            foreach ($ids as $id) {
              if ($type === 'stacks' && isset($snapshotInfo['references']['stack'])) {
                if ($id != $snapshotInfo['references']['stack']) {
                  $snapshotInfo['issues'][] = 'Referenced by stack ' . $id . ' but snapshot references different stack ' . $snapshotInfo['references']['stack'];
                }
              }
              if ($type === 'components' && isset($snapshotInfo['references']['component'])) {
                if ($id != $snapshotInfo['references']['component']) {
                  $snapshotInfo['issues'][] = 'Referenced by component ' . $id . ' but snapshot references different component ' . $snapshotInfo['references']['component'];
                }
              }
            }
          }
        }

        if ($isDangling || !empty($snapshotInfo['issues'])) {
          $danglingSnapshots[] = $snapshotInfo;
        }
      }

    }
    catch (\Exception $e) {
      $logger->error('Error finding dangling snapshots: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $danglingSnapshots;
  }

  /**
   * Get detailed information about a specific snapshot.
   *
   * @param int $snapshotId
   *   The snapshot ID to investigate.
   *
   * @return array|null
   *   Detailed snapshot information or NULL if not found.
   */
  public function investigateSnapshot(int $snapshotId): ?array {
    try {
      $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');

      // Try to load with access checks disabled.
      $query = $snapshotStorage->getQuery()
        ->condition('id', $snapshotId)
        ->accessCheck(FALSE);
      $results = $query->execute();

      if (empty($results)) {
        return [
          'status' => 'not_found',
          'message' => 'Snapshot does not exist in database',
        ];
      }
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
      $snapshot = $snapshotStorage->load($snapshotId);
      if (!$snapshot) {
        return [
          'status' => 'access_denied',
          'message' => 'Snapshot exists but cannot be loaded (access denied)',
        ];
      }

      $info = [
        'status' => 'found',
        'id' => $snapshot->id(),
        'label' => $snapshot->getLabel(),
        'owner' => $snapshot->getOwnerId(),
        'created' => $snapshot->getCreatedTime(),
        'langcode' => $snapshot->get('langcode')->value,
        'references_stack' => !$snapshot->get('snapshotOfStack')->isEmpty() ? $snapshot->get('snapshotOfStack')->target_id : NULL,
        'references_component' => !$snapshot->get('snapshotOfComponent')->isEmpty() ? $snapshot->get('snapshotOfComponent')->target_id : NULL,
        'file_id' => !$snapshot->get('file')->isEmpty() ? $snapshot->get('file')->target_id : NULL,
        'checksum_file_id' => !$snapshot->get('checksumFile')->isEmpty() ? $snapshot->get('checksumFile')->target_id : NULL,
        'directory' => $snapshot->get('dir')->value,
      ];

      // Check referenced entities.
      if ($info['references_stack']) {
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsStack $stack */
        $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->load($info['references_stack']);
        $info['stack_exists'] = $stack !== NULL;
        if ($stack) {
          $info['stack_label'] = $stack->getLabel();
        }
      }

      if ($info['references_component']) {
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
        $component = $this->entityTypeManager->getStorage('soda_scs_component')->load($info['references_component']);
        $info['component_exists'] = $component !== NULL;
        if ($component) {
          $info['component_label'] = $component->getLabel();
        }
      }

      // Check file entities.
      if ($info['file_id']) {
        /** @var \Drupal\file\Entity\File $file */
        $file = $this->entityTypeManager->getStorage('file')->load($info['file_id']);
        $info['file_exists'] = $file !== NULL;
      }

      if ($info['checksum_file_id']) {
        /** @var \Drupal\file\Entity\File $checksumFile */
        $checksumFile = $this->entityTypeManager->getStorage('file')->load($info['checksum_file_id']);
        $info['checksum_file_exists'] = $checksumFile !== NULL;
      }

      return $info;

    }
    catch (\Exception $e) {
      return [
        'status' => 'error',
        'message' => 'Error investigating snapshot: ' . $e->getMessage(),
      ];
    }
  }

  /**
   * Find pseudo snapshots that may be causing issues.
   *
   * @return array
   *   Array of pseudo snapshot information.
   */
  public function findProblematicPseudoSnapshots(): array {
    $problematicSnapshots = [];
    $logger = $this->loggerFactory->get('soda_scs_manager');

    try {
      $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');
      $fileStorage = $this->entityTypeManager->getStorage('file');

      // Find snapshots that look like pseudo snapshots.
      $query = $snapshotStorage->getQuery()
        ->accessCheck(FALSE);
      $snapshotIds = $query->execute();

      if (empty($snapshotIds)) {
        return [];
      }

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot[] $snapshots */
      $snapshots = $snapshotStorage->loadMultiple($snapshotIds);

      foreach ($snapshots as $snapshot) {
        $issues = [];
        $isPseudo = FALSE;

        // Check for pseudo snapshot indicators.
        $label = $snapshot->getLabel();
        $machineName = $snapshot->get('machineName')->value ?? '';

        if (strpos($label, 'Pseudo snapshot') !== FALSE ||
            strpos($machineName, 'pseudosnapshot-') !== FALSE) {
          $isPseudo = TRUE;
          $issues[] = 'Identified as pseudo snapshot';
        }

        // Check for temporary files.
        if (!$snapshot->get('file')->isEmpty()) {
          $fileId = $snapshot->get('file')->target_id;
          /** @var \Drupal\file\Entity\File $file */
          $file = $fileStorage->load($fileId);
          if ($file) {
            $fileUri = $file->getFileUri();
            if (strpos($fileUri, 'temporary://') !== FALSE) {
              $issues[] = 'Uses temporary file: ' . $fileUri;
              $isPseudo = TRUE;
            }
            // Check if file actually exists.
            if (!file_exists($file->getFileUri())) {
              $issues[] = 'File does not exist: ' . $fileUri;
            }
          }
          else {
            $issues[] = 'File entity does not exist (ID: ' . $fileId . ')';
          }
        }

        // Check for missing required fields that real snapshots should have.
        if (empty($snapshot->get('dir')->value)) {
          $issues[] = 'Missing snapshot directory';
        }

        if ($snapshot->get('checksumFile')->isEmpty()) {
          $issues[] = 'Missing checksum file';
        }

        // Check if this snapshot is referenced by entities but shouldn't be.
        if ($isPseudo) {
          /** @var \Drupal\soda_scs_manager\Entity\SodaScsStack $stack */
          $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
          /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponent $component */
          $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');

          $stackQuery = $stackStorage->getQuery()
            ->condition('snapshots', $snapshot->id())
            ->accessCheck(FALSE);
          $referencingStacks = $stackQuery->execute();

          $componentQuery = $componentStorage->getQuery()
            ->condition('snapshots', $snapshot->id())
            ->accessCheck(FALSE);
          $referencingComponents = $componentQuery->execute();

          if (!empty($referencingStacks) || !empty($referencingComponents)) {
            $issues[] = 'Pseudo snapshot is referenced by entities (should not be)';
          }
        }

        if ($isPseudo || !empty($issues)) {
          $problematicSnapshots[] = [
            'id' => $snapshot->id(),
            'label' => $label,
            'machine_name' => $machineName,
            'created' => $snapshot->getCreatedTime(),
            'is_pseudo' => $isPseudo,
            'issues' => $issues,
            'file_id' => !$snapshot->get('file')->isEmpty() ? $snapshot->get('file')->target_id : NULL,
            'checksum_file_id' => !$snapshot->get('checksumFile')->isEmpty() ? $snapshot->get('checksumFile')->target_id : NULL,
            'references_component' => !$snapshot->get('snapshotOfComponent')->isEmpty() ? $snapshot->get('snapshotOfComponent')->target_id : NULL,
            'references_stack' => !$snapshot->get('snapshotOfStack')->isEmpty() ? $snapshot->get('snapshotOfStack')->target_id : NULL,
          ];
        }
      }

    }
    catch (\Exception $e) {
      $logger->error('Error finding problematic pseudo snapshots: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $problematicSnapshots;
  }

  /**
   * Find orphaned file entities related to snapshots.
   *
   * @return array
   *   Array of orphaned file information.
   */
  public function findOrphanedSnapshotFiles(): array {
    $orphanedFiles = [];
    $logger = $this->loggerFactory->get('soda_scs_manager');

    try {
      $fileStorage = $this->entityTypeManager->getStorage('file');
      $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');

      // Find all files that might be snapshot-related.
      $query = $fileStorage->getQuery()
        ->accessCheck(FALSE);
      $fileIds = $query->execute();

      if (empty($fileIds)) {
        return [];
      }

      /** @var \Drupal\file\Entity\File[] $files */
      $files = $fileStorage->loadMultiple($fileIds);

      foreach ($files as $file) {
        $fileUri = $file->getFileUri();
        $issues = [];
        $isSnapshotRelated = FALSE;

        // Check if this looks like a snapshot file.
        if (strpos($fileUri, 'temporary://') !== FALSE ||
            strpos($fileUri, '.tar.gz') !== FALSE ||
            strpos($fileUri, '.sha256') !== FALSE ||
            strpos($fileUri, 'private://snapshots/') !== FALSE) {
          $isSnapshotRelated = TRUE;
        }

        if ($isSnapshotRelated) {
          // Check if file actually exists.
          if (!file_exists($fileUri)) {
            $issues[] = 'File does not exist on filesystem';
          }

          // Check if any snapshots reference this file.
          $fileQuery = $snapshotStorage->getQuery()
            ->condition('file', $file->id())
            ->accessCheck(FALSE);
          $fileReferences = $fileQuery->execute();

          $checksumQuery = $snapshotStorage->getQuery()
            ->condition('checksumFile', $file->id())
            ->accessCheck(FALSE);
          $checksumReferences = $checksumQuery->execute();

          if (empty($fileReferences) && empty($checksumReferences)) {
            $issues[] = 'Not referenced by any snapshot entity';
          }

          if (!empty($issues)) {
            $orphanedFiles[] = [
              'id' => $file->id(),
              'uri' => $fileUri,
              'filename' => $file->getFilename(),
              'filesize' => $file->getSize(),
              'created' => $file->getCreatedTime(),
              'issues' => $issues,
              'file_references' => $fileReferences,
              'checksum_references' => $checksumReferences,
            ];
          }
        }
      }

    }
    catch (\Exception $e) {
      $logger->error('Error finding orphaned snapshot files: @message', [
        '@message' => $e->getMessage(),
      ]);
    }

    return $orphanedFiles;
  }

  /**
   * Find containers that may be left running from snapshot operations.
   *
   * @return array
   *   Array of potentially problematic containers.
   */
  public function findSnapshotContainers(): array {
    // This would require integration with Docker/Portainer API.
    // For now, return placeholder indicating manual check needed.
    return [
      'note' => 'Container detection requires manual inspection of Docker/Portainer',
      'suggested_command' => 'docker ps -a | grep "snapshot--"',
      'description' => 'Look for containers with names containing "snapshot--" that may be stuck',
    ];
  }

  /**
   * Clean up orphaned snapshots.
   *
   * @param array $snapshotIds
   *   Array of snapshot IDs to clean up.
   * @param bool $dryRun
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Cleanup results.
   */
  public function cleanupOrphanedSnapshots(array $snapshotIds, bool $dryRun = TRUE): array {
    $results = [
      'deleted' => [],
      'failed' => [],
      'total' => count($snapshotIds),
      'dry_run' => $dryRun,
    ];

    $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');
    $logger = $this->loggerFactory->get('soda_scs_manager');

    foreach ($snapshotIds as $snapshotId) {
      try {
        /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
        $snapshot = $snapshotStorage->load($snapshotId);
        if (!$snapshot) {
          $results['failed'][] = [
            'id' => $snapshotId,
            'reason' => 'Snapshot not found',
          ];
          continue;
        }

        if (!$dryRun) {
          $label = $snapshot->getLabel();
          $snapshot->delete();
          $results['deleted'][] = [
            'id' => $snapshotId,
            'label' => $label,
          ];
          $logger->info('Deleted orphaned snapshot @id (@label)', [
            '@id' => $snapshotId,
            '@label' => $label,
          ]);
        }
        else {
          $results['deleted'][] = [
            'id' => $snapshotId,
            'label' => $snapshot->getLabel(),
            'note' => 'Would be deleted (dry run)',
          ];
        }

      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'id' => $snapshotId,
          'reason' => $e->getMessage(),
        ];
        $logger->error('Failed to delete snapshot @id: @message', [
          '@id' => $snapshotId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $results;
  }

  /**
   * Clean up orphaned files.
   *
   * @param array $fileIds
   *   Array of file IDs to clean up.
   * @param bool $dryRun
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Cleanup results.
   */
  public function cleanupOrphanedFiles(array $fileIds, bool $dryRun = TRUE): array {
    $results = [
      'deleted' => [],
      'failed' => [],
      'total' => count($fileIds),
      'dry_run' => $dryRun,
    ];

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $logger = $this->loggerFactory->get('soda_scs_manager');

    foreach ($fileIds as $fileId) {
      try {
        /** @var \Drupal\file\Entity\File $file */
        $file = $fileStorage->load($fileId);
        if (!$file) {
          $results['failed'][] = [
            'id' => $fileId,
            'reason' => 'File not found',
          ];
          continue;
        }

        if (!$dryRun) {
          $filename = $file->getFilename();
          $file->delete();
          $results['deleted'][] = [
            'id' => $fileId,
            'filename' => $filename,
          ];
          $logger->info('Deleted orphaned file @id (@filename)', [
            '@id' => $fileId,
            '@filename' => $filename,
          ]);
        }
        else {
          $results['deleted'][] = [
            'id' => $fileId,
            'filename' => $file->getFilename(),
            'note' => 'Would be deleted (dry run)',
          ];
        }

      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'id' => $fileId,
          'reason' => $e->getMessage(),
        ];
        $logger->error('Failed to delete file @id: @message', [
          '@id' => $fileId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $results;
  }

  /**
   * Comprehensive cleanup of all snapshot-related issues.
   *
   * @param bool $dryRun
   *   If TRUE, only report what would be cleaned up.
   *
   * @return array
   *   Comprehensive cleanup results.
   */
  public function comprehensiveCleanup(bool $dryRun = TRUE): array {
    $results = [
      'dry_run' => $dryRun,
      'dangling_snapshots' => [],
      'pseudo_snapshots' => [],
      'orphaned_files' => [],
      'summary' => [],
    ];

    // Find and clean dangling snapshots.
    $danglingSnapshots = $this->findDanglingSnapshots();
    if (!empty($danglingSnapshots)) {
      $danglingIds = array_column($danglingSnapshots, 'id');
      $results['dangling_snapshots'] = $this->cleanupOrphanedSnapshots($danglingIds, $dryRun);
    }

    // Find and clean pseudo snapshots.
    $pseudoSnapshots = $this->findProblematicPseudoSnapshots();
    if (!empty($pseudoSnapshots)) {
      $pseudoIds = array_column($pseudoSnapshots, 'id');
      $results['pseudo_snapshots'] = $this->cleanupOrphanedSnapshots($pseudoIds, $dryRun);
    }

    // Find and clean orphaned files.
    $orphanedFiles = $this->findOrphanedSnapshotFiles();
    if (!empty($orphanedFiles)) {
      $orphanedFileIds = array_column($orphanedFiles, 'id');
      $results['orphaned_files'] = $this->cleanupOrphanedFiles($orphanedFileIds, $dryRun);
    }

    // Create summary.
    $totalSnapshots = ($results['dangling_snapshots']['total'] ?? 0) + ($results['pseudo_snapshots']['total'] ?? 0);
    $totalFiles = $results['orphaned_files']['total'] ?? 0;

    $results['summary'] = [
      'total_snapshots_processed' => $totalSnapshots,
      'total_files_processed' => $totalFiles,
      'action' => $dryRun ? 'Analysis completed' : 'Cleanup completed',
    ];

    return $results;
  }

  /**
   * Safely clean up dangling/pseudo snapshots after a new snapshot is created.
   *
   * Deletes only clearly broken snapshots that are not referenced by any
   * component or stack, while protecting the current user's recent snapshots
   * and the snapshot just created.
   *
   * Rules:
   * - Never touch the just-created snapshot (exclude by ID).
   * - Skip snapshots owned by the current user that are newer than the grace
   *   period.
   * - Only delete snapshots that meet ALL of the following:
   *   - Not referenced by any stack or component.
   *   - Either identified as a pseudo snapshot OR references non-existent
   *     entities OR has missing critical fields (file/dir/checksum).
   *
   * @param int $currentUserId
   *   The current user's ID.
   * @param int $newSnapshotId
   *   The snapshot ID that was just created.
   * @param int $gracePeriodSeconds
   *   Time window to protect the current user's snapshots
   *   (default 600 seconds).
   * @param bool $dryRun
   *   If TRUE, only report what would be deleted.
   *
   * @return array
   *   Results array with deleted/failed/total.
   */
  public function safeCleanupAfterSnapshotCreation(
    int $currentUserId,
    int $newSnapshotId,
    int $gracePeriodSeconds = 600,
    bool $dryRun = FALSE,
  ): array {
    $snapshotStorage = $this->entityTypeManager->getStorage('soda_scs_snapshot');
    $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $componentStorage = $this->entityTypeManager->getStorage('soda_scs_component');

    $now = time();
    $candidates = [];

    // Gather candidates from dangling and pseudo checks.
    $dangling = $this->findDanglingSnapshots();
    $pseudo = $this->findProblematicPseudoSnapshots();

    foreach ([$dangling, $pseudo] as $list) {
      foreach ($list as $item) {
        // Use array as set keyed by ID.
        $candidates[(int) $item['id']] = $item;
      }
    }

    $results = [
      'deleted' => [],
      'failed' => [],
      'total' => count($candidates),
      'dry_run' => $dryRun,
    ];

    if (empty($candidates)) {
      return $results;
    }

    foreach (array_keys($candidates) as $candidateId) {
      try {
        // Always protect the newly created snapshot.
        if ($candidateId === $newSnapshotId) {
          continue;
        }

        /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot|null $snapshot */
        $snapshot = $snapshotStorage->load($candidateId);
        if (!$snapshot) {
          // Already gone.
          continue;
        }

        // Protect the current user's very recent snapshots.
        $ownerId = (int) $snapshot->get('owner')->target_id;
        if ($ownerId === $currentUserId) {
          $age = $now - (int) $snapshot->getCreatedTime();
          if ($age < $gracePeriodSeconds) {
            continue;
          }
        }

        // Must not be referenced by any stack/component.
        $referencedByStacks = $stackStorage->getQuery()
          ->condition('snapshots', $snapshot->id())
          ->accessCheck(FALSE)
          ->execute();
        if (!empty($referencedByStacks)) {
          continue;
        }

        $referencedByComponents = $componentStorage->getQuery()
          ->condition('snapshots', $snapshot->id())
          ->accessCheck(FALSE)
          ->execute();
        if (!empty($referencedByComponents)) {
          continue;
        }

        // Determine if clearly broken.
        $isPseudo = FALSE;
        $label = (string) $snapshot->getLabel();
        $machineName = (string) ($snapshot->get('machineName')->value ?? '');
        if (str_contains($label, 'Pseudo snapshot') || str_starts_with($machineName, 'pseudosnapshot-')) {
          $isPseudo = TRUE;
        }

        $referencesBroken = FALSE;
        if (!$snapshot->get('snapshotOfStack')->isEmpty()) {
          $stackId = (int) $snapshot->get('snapshotOfStack')->target_id;
          if (!$stackStorage->load($stackId)) {
            $referencesBroken = TRUE;
          }
        }
        if (!$snapshot->get('snapshotOfComponent')->isEmpty()) {
          $componentId = (int) $snapshot->get('snapshotOfComponent')->target_id;
          if (!$componentStorage->load($componentId)) {
            $referencesBroken = TRUE;
          }
        }

        $missingCriticalFields = FALSE;
        if (empty($snapshot->get('dir')->value) || $snapshot->get('file')->isEmpty() || $snapshot->get('checksumFile')->isEmpty()) {
          $missingCriticalFields = TRUE;
        }

        // Only proceed if snapshot is clearly broken.
        if (!($isPseudo || $referencesBroken || $missingCriticalFields)) {
          continue;
        }

        if ($dryRun) {
          $note = 'Would be deleted (safe cleanup dry run)';
          if ($isPseudo) {
            $note .= ' [pseudo]';
          }
          if ($referencesBroken) {
            $note .= ' [broken-references]';
          }
          if ($missingCriticalFields) {
            $note .= ' [missing-fields]';
          }
          $results['deleted'][] = [
            'id' => $snapshot->id(),
            'label' => $snapshot->getLabel(),
            'note' => $note,
          ];
          continue;
        }

        $labelForLog = $snapshot->getLabel();
        $snapshot->delete();
        $results['deleted'][] = [
          'id' => $candidateId,
          'label' => $labelForLog,
        ];

      }
      catch (\Exception $e) {
        $results['failed'][] = [
          'id' => $candidateId,
          'reason' => $e->getMessage(),
        ];
        $this->loggerFactory->get('soda_scs_manager')->error('Safe cleanup failed for snapshot @id: @message', [
          '@id' => $candidateId,
          '@message' => $e->getMessage(),
        ]);
      }
    }

    return $results;
  }

}
