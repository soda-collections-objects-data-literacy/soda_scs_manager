<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form for deleting a snapshot entity.
 */
class SodaScsSnapshotDeleteForm extends ContentEntityDeleteForm {

  /**
   * The Soda SCS Snapshot Helpers service.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers
   */
  protected SodaScsSnapshotHelpers $sodaScsSnapshotHelpers;

  /**
   * Constructs a new SodaScsSnapshotDeleteForm.
   *
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   The entity repository.
   * @param \Drupal\Core\Entity\EntityTypeBundleInfoInterface $entityTypeBundleInfo
   *   The entity type bundle info.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers $sodaScsSnapshotHelpers
   *   The Soda SCS Snapshot Helpers service.
   */
  public function __construct(
    EntityRepositoryInterface $entityRepository,
    EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    TimeInterface $time,
    SodaScsSnapshotHelpers $sodaScsSnapshotHelpers,
  ) {
    parent::__construct($entityRepository, $entityTypeBundleInfo, $time);
    $this->sodaScsSnapshotHelpers = $sodaScsSnapshotHelpers;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('soda_scs_manager.snapshot.helpers'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->getEntity();
    return $this->t('Are you sure you want to delete snapshot %label?', [
      '%label' => $snapshot->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('entity.soda_scs_snapshot.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->getEntity();
    $label = $snapshot->label();
    $snapshot->delete();

    // Delete the snapshot directory.
    $snapshotDirectory = $snapshot->get('dir')->value;
    $this->sodaScsSnapshotHelpers->deleteSnapshotDirectory($snapshotDirectory);

    $this->messenger()->addMessage($this->t('Snapshot %label has been deleted.', [
      '%label' => $label,
    ]));

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
