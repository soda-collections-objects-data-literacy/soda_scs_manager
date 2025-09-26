<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Commands;

use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers;
use Drush\Commands\DrushCommands;
use Drush\Exceptions\UserAbortException;
use Drupal\Core\StringTranslation\ByteSizeMarkup;

/**
 * Drush commands for snapshot integrity management.
 */
class SodaScsSnapshotCommands extends DrushCommands {

  /**
   * The snapshot integrity helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers
   */
  protected SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers;

  /**
   * Constructs a new SodaScsSnapshotCommands.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers
   *   The snapshot integrity helpers.
   */
  public function __construct(SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers) {
    $this->snapshotIntegrityHelpers = $snapshotIntegrityHelpers;
  }

  /**
   * Find dangling snapshot entities.
   *
   * @command soda-scs:snapshots:find-dangling
   * @aliases soda-scs-snap-dangling
   * @usage soda-scs:snapshots:find-dangling
   *   Find all dangling snapshot entities.
   */
  public function findDanglingSnapshots() {
    $this->output()->writeln('<info>Searching for dangling snapshots...</info>');

    $danglingSnapshots = $this->snapshotIntegrityHelpers->findDanglingSnapshots();

    if (empty($danglingSnapshots)) {
      $this->output()->writeln('<success>No dangling snapshots found.</success>');
      return;
    }

    $this->output()->writeln('<warning>Found ' . count($danglingSnapshots) . ' dangling snapshots:</warning>');

    foreach ($danglingSnapshots as $snapshot) {
      $this->output()->writeln(sprintf(
        '  ID: %d, Label: %s, Issues: %s',
        $snapshot['id'],
        $snapshot['label'],
        implode(', ', $snapshot['issues'])
      ));
    }
  }

  /**
   * Find problematic pseudo snapshots.
   *
   * @command soda-scs:snapshots:find-pseudo
   * @aliases soda-scs-snap-pseudo
   * @usage soda-scs:snapshots:find-pseudo
   *   Find all problematic pseudo snapshots.
   */
  public function findPseudoSnapshots() {
    $this->output()->writeln('<info>Searching for problematic pseudo snapshots...</info>');

    $pseudoSnapshots = $this->snapshotIntegrityHelpers->findProblematicPseudoSnapshots();

    if (empty($pseudoSnapshots)) {
      $this->output()->writeln('<success>No problematic pseudo snapshots found.</success>');
      return;
    }

    $this->output()->writeln('<warning>Found ' . count($pseudoSnapshots) . ' problematic pseudo snapshots:</warning>');

    foreach ($pseudoSnapshots as $snapshot) {
      $this->output()->writeln(sprintf(
        '  ID: %d, Label: %s, Machine Name: %s, Issues: %s',
        $snapshot['id'],
        $snapshot['label'],
        $snapshot['machine_name'],
        implode(', ', $snapshot['issues'])
      ));
    }
  }

  /**
   * Find orphaned snapshot files.
   *
   * @command soda-scs:snapshots:find-orphaned-files
   * @aliases soda-scs-snap-orphaned
   * @usage soda-scs:snapshots:find-orphaned-files
   *   Find all orphaned snapshot files.
   */
  public function findOrphanedFiles() {
    $this->output()->writeln('<info>Searching for orphaned snapshot files...</info>');

    $orphanedFiles = $this->snapshotIntegrityHelpers->findOrphanedSnapshotFiles();

    if (empty($orphanedFiles)) {
      $this->output()->writeln('<success>No orphaned snapshot files found.</success>');
      return;
    }

    $this->output()->writeln('<warning>Found ' . count($orphanedFiles) . ' orphaned snapshot files:</warning>');

    foreach ($orphanedFiles as $file) {
      $this->output()->writeln(sprintf(
        '  ID: %d, File: %s, Size: %s, Issues: %s',
        $file['id'],
        $file['uri'],
        $file['filesize'] ? ByteSizeMarkup::create(($file['filesize'])) : 'Unknown',
        implode(', ', $file['issues'])
      ));
    }
  }

  /**
   * Investigate a specific snapshot.
   *
   * @param int $snapshotId
   *   The snapshot ID to investigate.
   *
   * @command soda-scs:snapshots:investigate
   * @aliases soda-scs-snap-investigate
   * @usage soda-scs:snapshots:investigate 178
   *   Investigate snapshot with ID 178.
   */
  public function investigateSnapshot(int $snapshotId) {
    $this->output()->writeln('<info>Investigating snapshot ' . $snapshotId . '...</info>');

    $investigation = $this->snapshotIntegrityHelpers->investigateSnapshot($snapshotId);

    if (!$investigation) {
      $this->output()->writeln('<error>Failed to investigate snapshot.</error>');
      return;
    }

    $this->output()->writeln('<success>Investigation results:</success>');
    foreach ($investigation as $key => $value) {
      if (is_array($value)) {
        $value = json_encode($value);
      }
      $this->output()->writeln('  ' . $key . ': ' . $value);
    }
  }

  /**
   * Run comprehensive cleanup analysis.
   *
   * @command soda-scs:snapshots:cleanup-analysis
   * @aliases soda-scs-snap-analysis
   * @usage soda-scs:snapshots:cleanup-analysis
   *   Run comprehensive cleanup analysis (dry run).
   */
  public function cleanupAnalysis() {
    $this->output()->writeln('<info>Running comprehensive cleanup analysis...</info>');

    $results = $this->snapshotIntegrityHelpers->comprehensiveCleanup(TRUE);

    $this->output()->writeln('<success>Analysis completed:</success>');
    $this->output()->writeln('  Snapshots to process: ' . $results['summary']['total_snapshots_processed']);
    $this->output()->writeln('  Files to process: ' . $results['summary']['total_files_processed']);

    if (!empty($results['dangling_snapshots']['deleted'])) {
      $this->output()->writeln('  Dangling snapshots to delete: ' . count($results['dangling_snapshots']['deleted']));
    }

    if (!empty($results['pseudo_snapshots']['deleted'])) {
      $this->output()->writeln('  Pseudo snapshots to delete: ' . count($results['pseudo_snapshots']['deleted']));
    }

    if (!empty($results['orphaned_files']['deleted'])) {
      $this->output()->writeln('  Orphaned files to delete: ' . count($results['orphaned_files']['deleted']));
    }
  }

  /**
   * Run comprehensive cleanup (DESTRUCTIVE).
   *
   * @command soda-scs:snapshots:cleanup
   * @aliases soda-scs-snap-cleanup
   * @usage soda-scs:snapshots:cleanup
   *   Run comprehensive cleanup (DESTRUCTIVE - permanently deletes entities).
   */
  public function comprehensiveCleanup() {
    $this->output()->writeln('<error>⚠️  WARNING: This will permanently delete problematic snapshots and files!</error>');
    $this->output()->writeln('<error>This action cannot be undone.</error>');

    if (!$this->io()->confirm('Are you sure you want to continue?', FALSE)) {
      throw new UserAbortException();
    }

    $this->output()->writeln('<info>Running comprehensive cleanup...</info>');

    $results = $this->snapshotIntegrityHelpers->comprehensiveCleanup(FALSE);

    $this->output()->writeln('<success>Cleanup completed:</success>');
    $this->output()->writeln('  Snapshots processed: ' . $results['summary']['total_snapshots_processed']);
    $this->output()->writeln('  Files processed: ' . $results['summary']['total_files_processed']);

    if (!empty($results['dangling_snapshots']['deleted'])) {
      $this->output()->writeln('  Dangling snapshots deleted: ' . count($results['dangling_snapshots']['deleted']));
    }

    if (!empty($results['pseudo_snapshots']['deleted'])) {
      $this->output()->writeln('  Pseudo snapshots deleted: ' . count($results['pseudo_snapshots']['deleted']));
    }

    if (!empty($results['orphaned_files']['deleted'])) {
      $this->output()->writeln('  Orphaned files deleted: ' . count($results['orphaned_files']['deleted']));
    }

    if (!empty($results['dangling_snapshots']['failed']) ||
        !empty($results['pseudo_snapshots']['failed']) ||
        !empty($results['orphaned_files']['failed'])) {
      $this->output()->writeln('<warning>Some items failed to delete. Check logs for details.</warning>');
    }
  }

}
