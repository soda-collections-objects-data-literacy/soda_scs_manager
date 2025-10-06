<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\EntityOwnerTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form controller for the snapshot entity create/edit forms.
 */
class SodaScsSnapshotEditForm extends ContentEntityForm {

  use StringTranslationTrait;
  use EntityOwnerTrait;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityRepositoryInterface $entity_repository, EntityTypeBundleInfoInterface $entity_type_bundle_info, TimeInterface $time, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($entity_repository, $entity_type_bundle_info, $time);
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.repository'),
      $container->get('entity_type.bundle.info'),
      $container->get('datetime.time'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_snapshot_edit_form';
  }

  /**
   * Cancel form submission handler.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public function cancelForm(array &$form, FormStateInterface $form_state) {
    $form_state->setRedirect('entity.soda_scs_snapshot.collection');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->entity;

    // Convert entity reference fields to links.
    foreach ($form as $key => $element) {
      if (is_array($element) &&
        isset($element['widget']) &&
        in_array(
          $element['widget']['#field_name'], [
            'owner',
            'snapshotOfComponent',
            'snapshotOfStack',
          ]
        )) {
        /** @var \Drupal\Core\Entity\EntityInterface $entity */
        $entity = $element['widget'][0]['target_id']['#default_value'];
        $form[$key]['widget']['#access'] = FALSE;
        if ($entity) {
          $entity = $this->entityTypeManager->getStorage($element['widget'][0]['target_id']['#target_type'])->load($entity->id());
          $form[$key]['#type'] = 'item';
          $form[$key]['#title'] = $form[$key]['widget']['#title'];
          $form[$key]['#description'] = $form[$key]['widget']['#description'];
          $form[$key]['#markup'] = $entity->toLink()->toString();
        }
      }
      if ($key === 'file') {
        $form[$key]['widget']['#access'] = FALSE;
        if (!$snapshot->get('file')->isEmpty()) {
          $file = $snapshot->getFile();
          if ($file) {
            $form[$key]['#type'] = 'item';
            $form[$key]['#title'] = $form[$key]['widget'][0]['#title'];
            $form[$key]['#description'] = $form[$key]['widget'][0]['#description'];
            $form[$key]['#markup'] = $file->getFileUri();
          }
        }
      }
    }

    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#submit' => ['::cancelForm'],
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'button--cancel'],
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsSnapshot $snapshot */
    $snapshot = $this->entity;

    // Ensure the file is marked as permanent if it exists.
    if (!$snapshot->get('file')->isEmpty()) {
      $file = $snapshot->getFile();
      if ($file) {
        $file->setPermanent();
        $file->save();
      }
    }

    $snapshot->save();
    $form_state->setRedirectUrl($snapshot->toUrl('collection'));
  }

}
