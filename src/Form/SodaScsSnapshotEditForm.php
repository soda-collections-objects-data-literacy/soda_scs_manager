<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\user\EntityOwnerTrait;

/**
 * Form controller for the snapshot entity create/edit forms.
 */
class SodaScsSnapshotEditForm extends ContentEntityForm {

  use StringTranslationTrait;
  use EntityOwnerTrait;

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
          $entity = \Drupal::entityTypeManager()->getStorage($element['widget'][0]['target_id']['#target_type'])->load($entity->id());
          $form[$key]['#type'] = 'item';
          $form[$key]['#title'] = $form[$key]['widget']['#title'];
          $form[$key]['#description'] = $form[$key]['widget']['#description'];
          $form[$key]['#markup'] = $entity->toLink()->toString();
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
    $this->entity->save();
    $form_state->setRedirectUrl($this->entity->toUrl('entity.soda_scs_snapshot.collection'));
  }

}
