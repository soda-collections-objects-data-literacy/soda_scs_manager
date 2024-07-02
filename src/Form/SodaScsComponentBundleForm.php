<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Drupal\file\Upload\FormFileUploadHandler;
use Drupal\Core\Url;

/**
 * Class ScsComponentBundleForm.
 */
class SodaScsComponentBundleForm extends EntityForm {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\soda_scs_manager\Entity\SodaScsComponentBundle
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
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
    

    $form['imageSet'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Icon'),
    ];

    $form['imageSet']['imageUpload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Image Upload'),
      '#upload_location' => 'public://soda_scs_manager/bundle_images',
      '#upload_validators' => [
        'file_validate_extensions' => ['png jpg jpeg svg'],
      ],
    ];

    $form['imageSet']['imageUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Image URL'),
      '#default_value' => $entity->getImageUrl(),
      '#disabled' => TRUE,
    ];

    $form['optionsSet'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Options'),
    ];

    $form['optionsSet']['options'] = [
      '#type' => 'radios',
      '#title' => $this->t('Options'),
      '#options' => [
        'upload' => $this->t('Options Upload'),
        'url' => $this->t('Options URL'),
      ],
      '#default_value' => 'upload',
    ];

    $form['optionsSet']['optionsUpload'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Options Upload'),
      '#upload_location' => 'public://soda_scs_manager/bundle_options',
      '#description' => $this->t("Upload a json file with the options for the SCS Component."),
      '#upload_validators' => [
        'file_validate_extensions' => ['json'],
      ],
      '#states' => [
        'disabled' => [
          ':input[name="options"]' => ['value' => 'url'],
        ],
      ],
    ];

    $form['optionsSet']['optionsUrl'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Options URL'),
      '#default_value' => $entity->getOptionsUrl(),
      '#description' => $this->t("Options path for the SODa SCS Component as json or swagger url."),
      '#states' => [
        'disabled' => [
          ':input[name="options"]' => ['value' => 'upload'],
        ],
      ],
    ];


    if ($entity->getOptionsUrl()) {
      $optionsUrl = $entity->getOptionsUrl();
      if (strpos($optionsUrl, '.json') !== false) {
      $swaggerSpecUrl = \Drupal::service('file_url_generator')->generateAbsoluteString($optionsUrl);
      } else {
      $swaggerSpecUrl = $optionsUrl;
      }
    
    }
    $form['swagger'] =  [
      '#markup' => '<div id="swagger-ui">Swagger UI</div>',
      '#attached' => [
        'library' => [
          'soda_scs_manager/swagger_ui',
        ],
        'drupalSettings' => [
          'swaggerSpecUrl' => $swaggerSpecUrl,
        ],
      ],
    ];

    dpm($form);

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    $entity->setDescription($form_state->getValue('description'));
    
    foreach(['imageUpload', 'optionsUpload'] as $fileField) {      
      $fileFormValue = $form_state->getValue($fileField);
      if (empty($fileFormValue)) {
        continue;
      }
      $file = File::load(reset($fileFormValue));
      if ($file) {
        $file->setPermanent();
        $file->save();
  
        $file_uri = $file->getFileUri();
  
        // Store the URL in the text field.
        $form_state->setValue($fileField, $file_uri);
        switch ($fileField) {
          case 'imageUpload':
            $entity->setImageUrl($file_uri);
            break;
          case 'optionsUpload':
            $entity->setOptionsUrl($file_uri);
            break;
        }
      }
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

    $form_state->setRedirect('entity.soda_scs_component_bundle.collection');
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $triggering_element = $form_state->getTriggeringElement();
  
    // Check if the triggering element is a Swagger UI button.
    // Replace 'swagger_ui_button' with the actual name of your Swagger UI button.
    if ($triggering_element['#name'] === 'swagger_ui_button') {
      $form_state->setResponse(new RedirectResponse('/')); // Redirect to home page.
    }
  }

}