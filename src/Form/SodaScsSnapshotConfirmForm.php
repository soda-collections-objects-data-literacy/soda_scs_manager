<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\soda_scs_manager\Entity\SodaScsSnapshot;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

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
   * Constructs a new SodaScsSnapshotConfirmForm.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
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
  public function buildForm(array $form, FormStateInterface $form_state, $soda_scs_stack = NULL, $soda_scs_component = NULL) {
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

    // Create the snapshot entity.
    $snapshot = SodaScsSnapshot::create([
      'label' => $values['label'],
      'description' => $values['description'],
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

    $form_state->setRedirectUrl($this->getCancelUrl());
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
