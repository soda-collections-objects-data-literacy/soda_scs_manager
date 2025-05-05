<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Utility\Error;
use Psr\Log\LogLevel;

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
   * The Soda SCS WissKI Component Actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected $sodaScsWisskiComponentActions;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * Constructs a new SodaScsSnapshotConfirmForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsWisskiComponentActions
   *   The Soda SCS Component Actions.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
    $this->loggerFactory = $logger_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('soda_scs_manager.wisski_component.actions'),
      $container->get('logger.factory')
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

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $values = $form_state->getValues();

    switch ($this->entity->bundle()) {
      case 'soda_scs_wisski_component':
        $createSnapshotResult = $this->sodaScsWisskiComponentActions->createSnapshot($this->entity);
        break;
      case 'soda_scs_sql_component':
        #$createSnapshotResult = $this->sodaScsSqlComponentActions->createSnapshot($this->entity);
        break;
    }

    #$createSnapshotResult = $this->s
    #odaScsWisskiComponentActions->createSnapshot($this->entity);

    if (!$createSnapshotResult['success']) {
      $this->messenger()->addError($this->t('Failed to create snapshot. See logs for more details.'));
      $error = $createSnapshotResult['error'];
      Error::logException(
        $this->loggerFactory->get('soda_scs_manager'),
        new \Exception($error),
        'Failed to create snapshot: @message',
        ['@message' => $error],
        LogLevel::ERROR
      );
      return;
    }

    // Create the snapshot entity.
    $snapshot = SodaScsSnapshot::create([
      'label' => $values['label'],
      'owner' => \Drupal::currentUser()->id(),
    ]);

    if ($this->entityType === 'soda_scs_stack') {
      $snapshot->set('snapshotOfStack', $this->entity->id());
    }
    else {
      $snapshot->set('snapshotOfComponent', $this->entity->id());
    }

    // Generate checksum.
    $checksum = md5(serialize($this->entity->toArray()));
    $snapshot->set('checksum', $checksum);

    $snapshot->save();

    $this->messenger()->addMessage($this->t('Created new snapshot %label.', [
      '%label' => $snapshot->label(),
    ]));

    // Set the redirect URL correctly
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
      return \Drupal\Core\Url::fromRoute('entity.soda_scs_stack.canonical', [
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
    $query = \Drupal::entityQuery('soda_scs_snapshot')
      ->condition('machineName', $value)
      ->accessCheck(TRUE);
    return !empty($query->execute());
  }

}
