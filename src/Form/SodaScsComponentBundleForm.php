<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\file\Upload\FormFileUploadHandler;

/**
 * Class ScsComponentBundleForm.
 */
class SodaScsComponentBundleForm extends EntityForm
{

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundle
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state)
  {
    $form = parent::buildForm($form, $form_state);

    $entity = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#description' => $this->t("Label for the ScsComponent bundle."),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => '\Drupal\soda_scs_manager\Entity\SodaScsComponentBundle::load',
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $entity->getDescription(),
      '#description' => $this->t("Description for the SODa SCS Component bundle."),
      '#required' => FALSE,
    ];

    $form['image_set'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Icon'),
    ];

    $form['image_set']['image_upload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Image Upload'),
      '#upload_location' => 'public://soda_scs_manager/images',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg svg'],
      ],
    ];

    $form['image_set']['image_url'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image URL'),
      '#default_value' => $entity->getImageUrl(),
      '#disabled' => TRUE,
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state)
  {
    parent::save($form, $form_state);
    /**
    $entity = $this->entity;
    $entity->setDescription($form_state->getValue('description'));
    $fileFormValue = $form_state->getValue('image_upload');
    $file = File::load(reset($fileFormValue));
    if ($file) {
        $file->setPermanent();
        $file->save();

        $file_uri = $file->getFileUri();

        // Store the URL in the text field.
        $form_state->setValue('image_upload', $file_uri);
            $entity->setImageUrl($file_uri);

    }

    $status = $entity->save();

    if ($status) {
      $this->messenger()->addMessage($this->t('Saved the %label ScsComponent bundle.', [
        '%label' => $entity->label(),
      ]));
    }
    else {
      $this->messenger()->addMessage($this->t('The %label ScsComponent bundle was not saved.', [
        '%label' => $entity->label(),
      ]));
    }

    $form_state->setRedirect('soda_scs_manager.desk');
     **/
  }

  public function submitForm(array &$form, FormStateInterface $form_state)
  {
    parent::submitForm($form, $form_state);
  }
}
