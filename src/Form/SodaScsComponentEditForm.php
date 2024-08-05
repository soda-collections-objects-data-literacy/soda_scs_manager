<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\Entity\EntityFormDisplay;

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
  public function form_id(): string {
    return 'soda_scs_component_edit_form';
  }


  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form = parent::buildForm($form, $form_state);
    $entity = $this->entity;
    $bundle = $entity->bundle();
    $entityTypeId = $entity->getEntityTypeId();

    $form['#attached']['library'][] = 'soda_scs_manager/globalStyling';
    $form ['actions']['delete'] = [];

    return $form;
  }

  /**
   * {@inheritdoc}
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  public function save(array $form, FormStateInterface $form_state): void {
    parent::save($form, $form_state);

    // Redirect to the components page.
    $form_state->setRedirect('soda_scs_manager.desk');
  }
}
