<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for the ScsComponent entity edit form.
 */
class SodaScsComponentEditForm extends ContentEntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The bundle.
   *
   * @var string
   */
  protected $bundle;

  /**
   * The entity type ID.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_component_edit_form';
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
    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $this->entity;
    $form = parent::buildForm($form, $form_state);

    // Hide the flavours field.
    $form['flavours']['#access'] = FALSE;

    // Make the machine name field read-only.
    if (isset($form['machineName'])) {
      // Hide the original widget and show plain text instead.
      $form['machineName']['#access'] = FALSE;
      $form['machineName_display'] = [
        '#type' => 'item',
        '#title' => $this->t('Machine Name'),
        '#markup' => $component->get('machineName')->value,
        '#weight' => $form['machineName']['#weight'] ?? 0,
      ];
    }

    if (isset($form['owner'])) {
      // Get the current owner label.
      $owner_name = $this->t('Unknown');

      // Get the field definitions to check if owner field exists.
      $field_definitions = $component->getFieldDefinitions();
      if (isset($field_definitions['owner'])) {
        // Get owner entity reference from the entity.
        $owner_items = $component->get('owner');
        if (!$owner_items->isEmpty()) {
          $owner_entity = $owner_items->entity;
          if ($owner_entity) {
            $owner_name = '<a href="' . $owner_entity->toUrl()->toString() . '">' . $owner_entity->label() . '</a>';
          }
        }

        // Hide the original widget and show plain text instead.
        $form['owner']['#access'] = FALSE;
        $form['owner_display'] = [
          '#type' => 'item',
          '#title' => $this->t('Owner'),
          '#markup' => $owner_name,
          '#weight' => $form['owner']['#weight'] ?? 0,
        ];
      }
    }

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form['actions']['cancel'] = [
      '#type' => 'submit',
      '#value' => $this->t('Cancel'),
      '#weight' => 10,
      '#submit' => ['::cancelForm'],
      '#attributes' => [
        'class' => ['button', 'button--secondary', 'button--cancel'],
      ],
    ];
    $form['actions']['delete'] = [];

    return $form;
  }

  /**
   * {@inheritdoc}
   *
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    parent::save($form, $form_state);

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.dashboard');
  }

}
