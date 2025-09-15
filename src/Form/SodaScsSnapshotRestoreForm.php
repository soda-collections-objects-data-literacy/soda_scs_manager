<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Utility\Error;
use Drupal\file\Entity\File;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions;
use Drupal\soda_scs_manager\SnapshotActions\SodaScsSnapshotActionsInterface;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * The Soda SCS Snapshot Actions.
   *
   * @var \Drupal\soda_scs_manager\SnapshotActions\SodaScsSnapshotActionsInterface
   */
  protected $sodaScsSnapshotActions;

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
   * The file system service.
   *
   * @var \Drupal\Core\File\FileSystemInterface
   */
  protected $fileSystem;

  /**
   * Constructs a new SodaScsSnapshotRestoreForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\File\FileSystemInterface $file_system
   *   The file system service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions
   *   The Soda SCS Docker Run Service Actions.
   * @param \Drupal\soda_scs_manager\SnapshotActions\SodaScsSnapshotActionsInterface $sodaScsSnapshotActions
   *   The Soda SCS Snapshot Actions.
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
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    LoggerChannelFactoryInterface $logger_factory,
    SodaScsDockerRunServiceActions $sodaScsDockerRunServiceActions,
    SodaScsSnapshotActionsInterface $sodaScsSnapshotActions,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    SodaScsComponentActionsInterface $sodaScsTripleStoreComponentActions,
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    SodaScsStackActionsInterface $sodaScsWisskiStackActions,
  ) {

    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->loggerFactory = $logger_factory;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsSnapshotActions = $sodaScsSnapshotActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTripleStoreComponentActions = $sodaScsTripleStoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->sodaScsWisskiStackActions = $sodaScsWisskiStackActions;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('logger.factory'),
      $container->get('soda_scs_manager.docker_run_service.actions'),
      $container->get('soda_scs_manager.snapshot.actions'),
      $container->get('soda_scs_manager.snapshot.helpers'),
      $container->get('soda_scs_manager.sql_component.actions'),
      $container->get('soda_scs_manager.triplestore_component.actions'),
      $container->get('soda_scs_manager.wisski_component.actions'),
      $container->get('soda_scs_manager.wisski_stack.actions'),
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
    $filePath = $this->fileSystem->realpath($fileUri);

    if (!$filePath || !file_exists($filePath)) {
      $this->messenger()->addError($this->t('Snapshot file does not exist on the filesystem.'));
      return;
    }

    // Verify checksum if available.
    $checksumFile = File::load($this->snapshot->get('checksumFile')->target_id);
    if ($checksumFile) {
      $checksumUri = $checksumFile->getFileUri();
      $checksumPath = $this->fileSystem->realpath($checksumUri);

      if ($checksumPath && file_exists($checksumPath)) {
        $expectedChecksum = trim(file_get_contents($checksumPath));
        $actualChecksum = hash_file('sha256', $filePath);

        if (strpos($expectedChecksum, $actualChecksum) === FALSE) {
          $this->messenger()->addError($this->t('Snapshot restoration failed. See logs for more details.'));
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Checksum mismatch'),
            $this->t('Snapshot restore failed: Checksum verification failed for snapshot @id. The file may be corrupted.', ['@id' => $this->snapshot->id()]),
            [],
            LogLevel::ERROR
          );
          return;
        }
      }
    }

    // Determine the target entity (component or stack).
    $targetEntity = NULL;
    $entityType = NULL;

    if (!$this->snapshot->get('snapshotOfComponent')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfComponent')->entity;
      $entityType = 'component';
    }
    elseif (!$this->snapshot->get('snapshotOfStack')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfStack')->entity;
      $entityType = 'stack';
    }

    if (!$targetEntity) {
      $this->messenger()->addError($this->t('Snapshot restoration failed. See logs for more details.'));
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        new \Exception('Target entity not found'),
        $this->t('Snapshot restore failed: Target entity not found for snapshot @id.', ['@id' => $this->snapshot->id()]),
        [],
        LogLevel::ERROR
      );
      return;
    }

    // Perform the restore based on entity type and bundle.
    try {
      if ($entityType === 'component') {
        $restoreResult = $this->sodaScsSnapshotActions->restoreComponent($targetEntity, $filePath);
      }
      else {
        $restoreResult = $this->sodaScsSnapshotActions->restoreStack($targetEntity, $filePath);
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
    $targetEntity = NULL;
    $entityLabel = '';

    if (!$this->snapshot->get('snapshotOfComponent')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfComponent')->entity;
      $entityLabel = $targetEntity ? $targetEntity->label() : 'Unknown Component';
    }
    elseif (!$this->snapshot->get('snapshotOfStack')->isEmpty()) {
      $targetEntity = $this->snapshot->get('snapshotOfStack')->entity;
      $entityLabel = $targetEntity ? $targetEntity->label() : 'Unknown Stack';
    }

    return $this->t('This action will restore the snapshot data of the (bundled) application <b>@entity_label</b>, permanently overwriting its current state.', [
      '@entity_label' => $entityLabel,
    ]);
  }

}
