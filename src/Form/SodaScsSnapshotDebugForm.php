<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Debug form for snapshot integrity issues.
 */
class SodaScsSnapshotDebugForm extends FormBase {

  /**
   * The snapshot integrity helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers
   */
  protected SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers;

  /**
   * Constructs a new SodaScsSnapshotDebugForm.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers
   *   The snapshot integrity helpers.
   */
  public function __construct(SodaScsSnapshotIntegrityHelpers $snapshotIntegrityHelpers) {
    $this->snapshotIntegrityHelpers = $snapshotIntegrityHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.snapshot.integrity.helpers')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_snapshot_debug_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['actions_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Snapshot Debugging Actions'),
    ];

    $form['actions_fieldset']['find_dangling'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find Dangling Snapshots'),
      '#submit' => ['::findDanglingSnapshots'],
    ];

    $form['actions_fieldset']['find_pseudo'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find Problematic Pseudo Snapshots'),
      '#submit' => ['::findPseudoSnapshots'],
    ];

    $form['actions_fieldset']['find_orphaned_files'] = [
      '#type' => 'submit',
      '#value' => $this->t('Find Orphaned Snapshot Files'),
      '#submit' => ['::findOrphanedFiles'],
    ];

    $form['actions_fieldset']['check_containers'] = [
      '#type' => 'submit',
      '#value' => $this->t('Check Snapshot Containers'),
      '#submit' => ['::checkContainers'],
    ];

    $form['cleanup_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Cleanup Operations'),
      '#description' => $this->t('Use these tools to clean up identified problems. Always run in dry-run mode first.'),
    ];

    $form['cleanup_fieldset']['comprehensive_cleanup'] = [
      '#type' => 'submit',
      '#value' => $this->t('Comprehensive Cleanup (Dry Run)'),
      '#submit' => ['::comprehensiveCleanup'],
      '#attributes' => ['class' => ['button--primary']],
    ];

    $form['cleanup_fieldset']['comprehensive_cleanup_real'] = [
      '#type' => 'submit',
      '#value' => $this->t('⚠️ Comprehensive Cleanup (REAL - NO UNDO!)'),
      '#submit' => ['::comprehensiveCleanupReal'],
      '#attributes' => [
        'class' => ['button--danger'],
        'onclick' => 'return confirm("Are you sure? This will permanently delete identified problematic snapshots and files. This action cannot be undone!");',
      ],
    ];

    $form['investigate_fieldset'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Investigate Specific Snapshot'),
    ];

    $form['investigate_fieldset']['snapshot_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Snapshot ID'),
      '#description' => $this->t('Enter the ID of the snapshot to investigate (e.g., 178)'),
      '#min' => 1,
    ];

    $form['investigate_fieldset']['investigate'] = [
      '#type' => 'submit',
      '#value' => $this->t('Investigate Snapshot'),
      '#submit' => ['::investigateSnapshot'],
    ];

    // Display results if available.
    $results = $form_state->get('results');
    if ($results) {
      $form['results'] = [
        '#type' => 'details',
        '#title' => $this->t('Results'),
        '#open' => TRUE,
        '#markup' => '<pre>' . htmlspecialchars(print_r($results, TRUE)) . '</pre>',
      ];
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Default submit handler - not used as we have custom submit handlers.
  }

  /**
   * Submit handler to find dangling snapshots.
   */
  public function findDanglingSnapshots(array &$form, FormStateInterface $form_state) {
    $danglingSnapshots = $this->snapshotIntegrityHelpers->findDanglingSnapshots();

    if (empty($danglingSnapshots)) {
      $this->messenger()->addMessage($this->t('No dangling snapshots found.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Found @count potentially dangling snapshots.', [
        '@count' => count($danglingSnapshots),
      ]));
      $form_state->set('results', $danglingSnapshots);
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler to find problematic pseudo snapshots.
   */
  public function findPseudoSnapshots(array &$form, FormStateInterface $form_state) {
    $problematicSnapshots = $this->snapshotIntegrityHelpers->findProblematicPseudoSnapshots();

    if (empty($problematicSnapshots)) {
      $this->messenger()->addMessage($this->t('No problematic pseudo snapshots found.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Found @count problematic pseudo snapshots.', [
        '@count' => count($problematicSnapshots),
      ]));
      $form_state->set('results', $problematicSnapshots);
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler to find orphaned files.
   */
  public function findOrphanedFiles(array &$form, FormStateInterface $form_state) {
    $orphanedFiles = $this->snapshotIntegrityHelpers->findOrphanedSnapshotFiles();

    if (empty($orphanedFiles)) {
      $this->messenger()->addMessage($this->t('No orphaned snapshot files found.'));
    }
    else {
      $this->messenger()->addWarning($this->t('Found @count orphaned snapshot files.', [
        '@count' => count($orphanedFiles),
      ]));
      $form_state->set('results', $orphanedFiles);
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler to check containers.
   */
  public function checkContainers(array &$form, FormStateInterface $form_state) {
    $containerInfo = $this->snapshotIntegrityHelpers->findSnapshotContainers();

    $this->messenger()->addMessage($this->t('Container check information provided.'));
    $form_state->set('results', $containerInfo);

    $form_state->setRebuild();
  }

  /**
   * Submit handler to investigate a specific snapshot.
   */
  public function investigateSnapshot(array &$form, FormStateInterface $form_state) {
    $snapshotId = (int) $form_state->getValue('snapshot_id');

    if (!$snapshotId) {
      $this->messenger()->addError($this->t('Please enter a valid snapshot ID.'));
      return;
    }

    $investigation = $this->snapshotIntegrityHelpers->investigateSnapshot($snapshotId);

    if ($investigation) {
      $this->messenger()->addMessage($this->t('Investigation results for snapshot @id:', [
        '@id' => $snapshotId,
      ]));
      $form_state->set('results', $investigation);
    }
    else {
      $this->messenger()->addError($this->t('Failed to investigate snapshot @id.', [
        '@id' => $snapshotId,
      ]));
    }

    $form_state->setRebuild();
  }

  /**
   * Submit handler for comprehensive cleanup (dry run).
   */
  public function comprehensiveCleanup(array &$form, FormStateInterface $form_state) {
    $results = $this->snapshotIntegrityHelpers->comprehensiveCleanup(TRUE);

    $this->messenger()->addMessage($this->t('Comprehensive cleanup analysis completed.'));
    $form_state->set('results', $results);

    $form_state->setRebuild();
  }

  /**
   * Submit handler for comprehensive cleanup (real).
   */
  public function comprehensiveCleanupReal(array &$form, FormStateInterface $form_state) {
    $results = $this->snapshotIntegrityHelpers->comprehensiveCleanup(FALSE);

    $this->messenger()->addMessage($this->t('Comprehensive cleanup completed.'));
    $form_state->set('results', $results);

    $form_state->setRebuild();
  }

}
