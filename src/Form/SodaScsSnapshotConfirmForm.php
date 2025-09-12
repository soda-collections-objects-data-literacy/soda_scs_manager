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
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Provides a confirmation form for creating snapshots.
 */
class SodaScsSnapshotConfirmForm extends ConfirmFormBase {

  /**
   * The entity to create snapshot from.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsStack|\Drupal\soda_scs_manager\Entity\SodaScsComponent
   */
  protected $entity;

  /**
   * The entity type (stack or component).
   *
   * @var string
   */
  protected $entityType;

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
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * Constructs a new SodaScsSnapshotConfirmForm.
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
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
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
    AccountProxyInterface $current_user,
    LanguageManagerInterface $language_manager,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTripleStoreComponentActions = $sodaScsTripleStoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->sodaScsWisskiStackActions = $sodaScsWisskiStackActions;
    $this->loggerFactory = $logger_factory;
    $this->currentUser = $current_user;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.docker_run_service.actions'),
      $container->get('soda_scs_manager.snapshot.helpers'),
      $container->get('soda_scs_manager.sql_component.actions'),
      $container->get('soda_scs_manager.triplestore_component.actions'),
      $container->get('soda_scs_manager.wisski_component.actions'),
      $container->get('soda_scs_manager.wisski_stack.actions'),
      $container->get('logger.factory'),
      $container->get('current_user'),
      $container->get('language_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'soda_scs_snapshot_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $bundle = NULL, $soda_scs_stack = NULL, $soda_scs_component = NULL) {
    $this->entity = $soda_scs_stack ?? $soda_scs_component;
    $this->entityType = $soda_scs_stack ? 'soda_scs_stack' : 'soda_scs_component';

    if (!$this->entity) {
      throw new \Exception('Entity not found');
    }

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Snapshot Label'),
      '#description' => $this->t('Enter a label for this snapshot.'),
      '#required' => TRUE,
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#description' => $this->t('Enter a description for this snapshot.'),
      '#required' => FALSE,
    ];

    $form = parent::buildForm($form, $form_state);

    // Add throbber overlay class to the submit button.
    if (isset($form['actions']['submit'])) {
      $form['actions']['submit']['#attributes']['class'][] = 'soda-scs-component--component--form-submit';
    }

    // Attach the throbber overlay library.
    $form['#attached']['library'][] = 'soda_scs_manager/throbberOverlay';

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();
    $snapshotMachineName = $this->sodaScsSnapshotHelpers->cleanMachineName($values['label']);

    // Create the snapshot entity.
    $snapshot = SodaScsSnapshot::create([
      'label' => $values['label'],
      'owner' => $this->currentUser->id(),
      'langcode' => $this->languageManager->getCurrentLanguage()->getId(),
    ]);

    $snapshot->save();

    switch ($this->entity->bundle()) {
      case 'soda_scs_sql_component':
        $createSnapshotResult = $this->sodaScsSqlComponentActions->createSnapshot($this->entity, $snapshotMachineName, time());
        break;

      case 'soda_scs_triplestore_component':
        $createSnapshotResult = $this->sodaScsTripleStoreComponentActions->createSnapshot($this->entity, $snapshotMachineName, time());
        break;

      case 'soda_scs_wisski_component':
        $createSnapshotResult = $this->sodaScsWisskiComponentActions->createSnapshot($this->entity, $snapshotMachineName, time());
        break;

      case 'soda_scs_wisski_stack':
        $createSnapshotResult = $this->sodaScsWisskiStackActions->createSnapshot($this->entity, $snapshotMachineName, time());
        break;

      default:
        $this->messenger()->addError($this->t('Failed to create snapshot. Unknown component type.'));
        return;
    }

    if (!$createSnapshotResult->success) {
      $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
      $error = $createSnapshotResult->error;
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        new \Exception($error),
        $this->t('Failed to create snapshot. @error', ['@error' => $error]),
        [],
        LogLevel::ERROR
      );
      return;
    }

    // Check if the snapshotcontainers are still running.
    // @todo Abstract this to public function.
    $containerIsRunning = TRUE;
    $attempts = 0;
    $maxAttempts = $this->sodaScsSnapshotHelpers->adjustMaxAttempts();
    if ($maxAttempts === FALSE) {
      Error::logException(
      $this->loggerFactory->get('soda_scs_manager'),
      new \Exception(''),
      $this->t('The PHP request timeout is less than the required sleep interval of 5 seconds. Please increase your max_execution_time setting.'),
      [],
      LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Could not create snapshot. See logs for more details.'));
      return;
    }
    while ($containerIsRunning && $attempts < $maxAttempts) {
      foreach ($createSnapshotResult->data as $componentBundle => $componentData) {
        $containerId = $componentData['containerId'];
        // Inspect the container to check if it is running.
        $containerInspectRequestParams = [
          'routeParams' => [
            'containerId' => $containerId,
          ],
        ];
        $containerInspectRequest = $this->sodaScsDockerRunServiceActions->buildInspectRequest($containerInspectRequestParams);
        $containerInspectResponse = $this->sodaScsDockerRunServiceActions->makeRequest($containerInspectRequest);

        if ($containerInspectResponse['success'] === FALSE) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('Container inspection failed'),
            'Failed to create snapshot. Could not inspect container: ' . $containerInspectResponse['error'],
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
          return;
        }

        // @todo Handle error code 404, because the container
        // is not running/ already removed OR handle deletion
        // over status of container.
        $responseCode = $containerInspectResponse['data']['portainerResponse']->getStatusCode();
        if ($responseCode !== 200) {
          Error::logException(
            $this->loggerFactory->get('soda_scs_manager'),
            new \Exception('HTTP error'),
            'Failed to create snapshot. Response code is not 200, but: ' . $responseCode,
            [],
            LogLevel::ERROR
          );
          $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
          return;
        }
        // Get the container status.
        $containerStatus = json_decode($containerInspectResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
        $containerIsRunning = $containerStatus['State']['Running'];
        if ($containerIsRunning) {
          // If the container is running, wait for 5 seconds and try again.
          sleep(5);
          $attempts++;
        }
        else {
          // Delete the temporarily created backup container
          // if it is not running, but have no error.
          if ($containerStatus['State']['ExitCode'] !== 0) {
            Error::logException(
              $this->loggerFactory->get('soda_scs_manager'),
              new \Exception('Temporarily created backup container exited unexpectedly.'),
              'Failed to create snapshot. Temporarily created backup container exited with exit code: ' . $containerStatus['State']['ExitCode'],
              [],
              LogLevel::ERROR
            );
            $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
            return;
          }
          $deleteContainerRequestParams = [
            'routeParams' => [
              'containerId' => $containerId,
            ],
          ];

          $deleteContainerRequest = $this->sodaScsDockerRunServiceActions->buildRemoveRequest($deleteContainerRequestParams);
          $deleteContainerResponse = $this->sodaScsDockerRunServiceActions->makeRequest($deleteContainerRequest);
          if (!$deleteContainerResponse['success']) {
            Error::logException(
              $this->loggerFactory->get('soda_scs_manager'),
              new \Exception('Temporarily created backup container deletion failed'),
              'Failed to create snapshot. Could not delete temporarily created backup container: ' . $deleteContainerResponse['error'],
              [],
              LogLevel::ERROR
            );
          }
          $containerData[$componentBundle] = [
            'containerId' => $containerId,
            'containerStatus' => $containerStatus,
          ];
        }
      }
    }

    if ($attempts === $maxAttempts) {
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        new \Exception('Container timeout'),
        'Failed to create snapshot. Maximum number of attempts to check if the container is running reached. Container is still running.',
        [],
        LogLevel::ERROR
      );
      $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
      return;
    }

    // Create the bag of files.
    $createBagResult = $this->sodaScsSnapshotHelpers->createBagOfFiles(
      $createSnapshotResult->data,
      $snapshot
    );

    // @todo Check if the bag container is still running. Wait for it to be removed.
    $file = File::create([
      'uri' => 'private://' . $createBagResult->data['metadata']['relativeTarFilePath'],
      'uid' => $this->currentUser->id(),
      'status' => 1,
    ]);
    $file->save();

    $checksumFile = File::create([
      'uri' => 'private://' . $createBagResult->data['metadata']['relativeSha256FilePath'],
      'uid' => $this->currentUser->id(),
      'status' => 1,
    ]);
    $checksumFile->save();

    // Create the snapshot entity.
    $snapshot->set('changed', time())
      ->set('created', time())
      ->set('file', $file->id())
      ->set('checksumFile', $checksumFile->id());

    if ($this->entityType === 'soda_scs_stack') {
      $snapshot->set('snapshotOfStack', $this->entity->id());
    }
    else {
      $snapshot->set('snapshotOfComponent', $this->entity->id());
    }

    $snapshot->save();

    // Add the snapshot to the referenced entities,
    // preserving existing references.
    $existingSnapshots = $this->entity->get('snapshots')->getValue();
    $snapshotReferences = [];
    foreach ($existingSnapshots as $item) {
      if (isset($item['target_id'])) {
        $snapshotReferences[] = $item['target_id'];
      }
    }
    $snapshotReferences[] = $snapshot->id();
    $this->entity->set('snapshots', $snapshotReferences);
    $this->entity->save();

    $this->messenger()->addMessage($this->t('Created new snapshot %label.', [
      '%label' => $snapshot->label(),
    ]));

    // Set the redirect URL correctly.
    $cancelUrl = $this->getCancelUrl();
    $form_state->setRedirectUrl($cancelUrl);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Create snapshot of @type @label?', [
      '@type' => str_replace('soda_scs_', '', $this->entityType),
      '@label' => $this->entity->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    if ($this->entityType === 'soda_scs_stack') {
      return Url::fromRoute('entity.soda_scs_stack.canonical', [
        'bundle' => $this->entity->bundle(),
        'soda_scs_stack' => $this->entity->id(),
      ]);
    }
    return $this->entity->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This action will create a new snapshot of the current state of this @type.', [
      '@type' => $this->entity->getEntityType()->getLabel(),
    ]);
  }

  /**
   * Check if a snapshot with this machine name already exists.
   *
   * @param string $value
   *   The machine name to check.
   *
   * @return bool
   *   TRUE if exists, FALSE otherwise.
   */
  public function exists($value) {
    $query = $this->entityTypeManager->getStorage('soda_scs_snapshot')->getQuery()
      ->condition('machineName', $value)
      ->accessCheck(TRUE);
    return !empty($query->execute());
  }

}
