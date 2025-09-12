<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Utility\Error;
use Drupal\file\Entity\File;
use Psr\Log\LogLevel;

/**
 * Provides a confirmation form for restoring snapshots.
 */
class SodaScsSnapshotRestoreForm extends ConfirmFormBase {

  /**
   * The snapshot entity to restore from.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot
   */
  protected $snapshot;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The Soda SCS Docker Run Service Actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions
   */
  protected $sodaScsDockerRunServiceActions;

  /**
   * The Soda SCS Snapshot Helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected $sodaScsSnapshotHelpers;

  /**
   * The Soda SCS SQL Component Actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected $sodaScsSqlComponentActions;

  /**
   * The Soda SCS Triple Store Component Actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected $sodaScsTripleStoreComponentActions;

  /**
   * The Soda SCS WissKI Component Actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected $sodaScsWisskiComponentActions;

  /**
   * The Soda SCS WissKI Stack Actions.
   *
   * @var \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface
   */
  protected $sodaScsWisskiStackActions;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new SodaScsSnapshotRestoreForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions
   *   The Soda SCS Docker Run Service Actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers $sodaScsSnapshotHelpers
   *   The Soda SCS Snapshot Helpers.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsSqlComponentActions
   *   The Soda SCS SQL Component Actions.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsTripleStoreComponentActions
   *   The Soda SCS Triple Store Component Actions.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsWisskiComponentActions
   *   The Soda SCS WissKI Component Actions.
   * @param \Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface $sodaScsWisskiStackActions
   *   The Soda SCS WissKI Stack Actions.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    SodaScsComponentActionsInterface $sodaScsTripleStoreComponentActions,
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    SodaScsStackActionsInterface $sodaScsWisskiStackActions,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTripleStoreComponentActions = $sodaScsTripleStoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->sodaScsWisskiStackActions = $sodaScsWisskiStackActions;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.docker_run_service.actions'),
      $container->get('soda_scs_manager.snapshot.helpers'),
      $container->get('soda_scs_manager.sql_component.actions'),
      $container->get('soda_scs_manager.triplestore_component.actions'),
      $container->get('soda_scs_manager.wisski_component.actions'),
      $container->get('soda_scs_manager.wisski_stack.actions'),
      $container->get('logger.factory')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_snapshot_restore_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?SodaScsSnapshot $soda_scs_snapshot = NULL) {
    $this->snapshot = $soda_scs_snapshot;

    if (!$this->snapshot) {
      throw new \Exception('Snapshot not found');
    }

    // Verify that the snapshot has completed status.
    if ($this->snapshot->get('status')->value !== 'completed') {
      $this->messenger()->addError($this->t('Cannot restore from snapshot with status: @status', [
        '@status' => $this->snapshot->get('status')->value,
      ]));
      $form['warning'] = [
        '#markup' => '<p><strong>' . $this->t('This snapshot cannot be restored because it has not completed successfully.') . '</strong></p>',
      ];
      return $form;
    }

    // Show warning about data loss.
    $form['warning'] = [
      '#markup' => '<div class="messages messages--warning"><h4>' . $this->t('Warning: Data Loss Risk') . '</h4><p>' .
        $this->t('Restoring this snapshot will <strong>permanently overwrite</strong> the current data in the target component or stack. This action cannot be undone.') . '</p></div>',
    ];

    // Add checkbox to confirm understanding.
    $form['confirm_data_loss'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('I understand that this action will permanently overwrite existing data'),
      '#required' => TRUE,
      '#description' => $this->t('You must acknowledge the risk of data loss to proceed.'),
    ];

    $form = parent::buildForm($form, $form_state);

    // Add throbber overlay class to the submit button.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
      $form['actions']['submit']['#value'] = $this->t('Restore Snapshot');
    }

    // Attach the throbber overlay library.
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Verify the snapshot file exists.
    $snapshotFile = $this->snapshot->getFile();
    if (!$snapshotFile) {
      $this->messenger()->addError($this->t('Snapshot file not found.'));
      return;
    }

    // Get the file URI and convert to system path.
    $fileUri = $snapshotFile->getFileUri();
    $filePath = \Drupal::service('file_system')->realpath($fileUri);

    if (!$filePath || !file_exists($filePath)) {
      $this->messenger()->addError($this->t('Snapshot file does not exist on the filesystem.'));
      return;
    }

    // Verify checksum if available.
    $checksumFile = File::load($this->snapshot->get('checksumFile')->target_id);
    if ($checksumFile) {
      $checksumUri = $checksumFile->getFileUri();
      $checksumPath = \Drupal::service('file_system')->realpath($checksumUri);

      if ($checksumPath && file_exists($checksumPath)) {
        $expectedChecksum = trim(file_get_contents($checksumPath));
        $actualChecksum = hash_file('sha256', $filePath);

        if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
          $this->messenger()->addError($this->t('Snapshot file checksum verification failed. The file may be corrupted.'));
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Checksum mismatch'),
            $this->t('Snapshot restore failed: Checksum verification failed for snapshot @id', ['@id' => $this->snapshot->id()]),
            [],
            LogLevel::ERROR
          );
          return;
        }
      }
    }

    // Determine the target entity (component or stack).
    $targetEntity = null;
    $entityType = null;

    if (!$this->snapshot->get('snapshotOfComponent')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfComponent')->entity;
      $entityType = 'component';
    }
    elseif (!$this->snapshot->get('snapshotOfStack')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfStack')->entity;
      $entityType = 'stack';
    }

    if (!$targetEntity) {
      $this->messenger()->addError($this->t('Cannot determine target entity for restore.'));
      return;
    }

    // Perform the restore based on entity type and bundle.
    try {
      if ($entityType === 'component') {
        $restoreResult = $this->restoreComponent($targetEntity, $filePath);
      }
      else {
        $restoreResult = $this->restoreStack($targetEntity, $filePath);
      }

      if ($restoreResult['success']) {
        $this->messenger()->addMessage($this->t('Successfully restored snapshot %label to %entity_label.', [
          '%label' => $this->snapshot->label(),
          '%entity_label' => $targetEntity->label(),
        ]));
      }
      else {
        $this->messenger()->addError($this->t('Failed to restore snapshot. @error', [
          '@error' => $restoreResult['error'] ?? 'Unknown error',
        ]));
      }
    }
    catch (\Exception $e) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        $e,
        $this->t('Snapshot restore failed: @message', ['@message' => $e->getMessage()]),
        [],
        LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Failed to restore snapshot. See logs for more details.'));
    }

    // Set the redirect URL to the target entity.
    $form_state->setRedirectUrl($targetEntity->toUrl());
  }

  /**
   * Restore a component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  protected function restoreComponent($component, $snapshotFilePath) {
    // This is a placeholder implementation. The actual restore logic would need to:
    // 1. Extract the snapshot file (tar.gz)
    // 2. Based on component bundle, restore the appropriate data
    // 3. For SQL components: restore database
    // 4. For WissKI components: restore files
    // 5. For triplestore components: restore RDF data

    switch ($component->bundle()) {
      case 'soda_scs_sql_component':
        return $this->restoreSqlComponent($component, $snapshotFilePath);

      case 'soda_scs_triplestore_component':
        return $this->restoreTriplestoreComponent($component, $snapshotFilePath);

      case 'soda_scs_wisski_component':
        return $this->restoreWisskiComponent($component, $snapshotFilePath);

      default:
        return [
          'success' => FALSE,
          'error' => 'Unsupported component type: ' . $component->bundle(),
        ];
    }
  }

  /**
   * Restore a stack from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The stack to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  protected function restoreStack($stack, $snapshotFilePath) {
    // Placeholder implementation for stack restore.
    // This would need to handle multi-component stack restoration.

    return [
      'success' => FALSE,
      'error' => 'Stack restore functionality not yet implemented.',
    ];
  }

  /**
   * Restore an SQL component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SQL component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  protected function restoreSqlComponent($component, $snapshotFilePath) {
    // Placeholder implementation.
    // Actual implementation would need to:
    // 1. Extract the tar.gz file to get the SQL dump
    // 2. Use Docker exec to restore the database in the SQL container

    return [
      'success' => FALSE,
      'error' => 'SQL component restore functionality not yet implemented.',
    ];
  }

  /**
   * Restore a triplestore component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The triplestore component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  protected function restoreTriplestoreComponent($component, $snapshotFilePath) {
    // Placeholder implementation.
    // Actual implementation would need to:
    // 1. Extract the tar.gz file to get the N-Quads file
    // 2. Use OpenGDB API to clear and reload the triplestore

    return [
      'success' => FALSE,
      'error' => 'Triplestore component restore functionality not yet implemented.',
    ];
  }

  /**
   * Restore a WissKI component from snapshot.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The WissKI component to restore to.
   * @param string $snapshotFilePath
   *   The path to the snapshot file.
   *
   * @return array
   *   Result array with success status and any error messages.
   */
  protected function restoreWisskiComponent($component, $snapshotFilePath) {
    // Placeholder implementation.
    // Actual implementation would need to:
    // 1. Extract the tar.gz file to get the Drupal files
    // 2. Use Docker to restore files to the WissKI volume

    return [
      'success' => FALSE,
      'error' => 'WissKI component restore functionality not yet implemented.',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Restore snapshot @label?', [
      '@label' => $this->snapshot->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->snapshot->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $targetEntity = null;
    $entityLabel = '';

    if (!$this->snapshot->get('snapshotOfComponent')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfComponent')->entity;
      $entityLabel = $targetEntity ? $targetEntity->label() : 'Unknown Component';
    }
    elseif (!$this->snapshot->get('snapshotOfStack')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfStack')->entity;
      $entityLabel = $targetEntity ? $targetEntity->label() : 'Unknown Stack';
    }

    return $this->t('This action will restore the snapshot data to @entity_label, permanently overwriting its current state.', [
      '@entity_label' => $entityLabel,
    ]);
  }

}
