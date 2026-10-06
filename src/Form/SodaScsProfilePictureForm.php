<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\file\FileInterface;

/**
 * Upload or remove the profile picture.
 */
final class SodaScsProfilePictureForm extends SodaScsProfileFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_profile_picture_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->loadUser();

    $form['picture'] = [
      '#type' => 'managed_file',
      '#title' => $this->t('Profile picture'),
      '#upload_location' => 'public://pictures/',
      '#upload_validators' => [
        'FileExtension' => ['extensions' => 'png gif jpg jpeg webp'],
        'FileSizeLimit' => ['fileLimit' => 2 * 1024 * 1024],
      ],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save picture'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn', 'btn--primary']],
    ];
    if (!$user->get('user_picture')->isEmpty()) {
      $form['actions']['remove'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove picture'),
        '#submit' => ['::removePicture'],
        '#limit_validation_errors' => [],
        '#attributes' => ['class' => ['btn', 'btn--secondary']],
      ];
    }
    $form['actions']['cancel'] = [
      '#type' => 'button',
      '#value' => $this->t('Cancel'),
      '#attributes' => ['class' => ['project-note-form__cancel', 'btn', 'btn--secondary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $fids = $form_state->getValue('picture');
    $file = !empty($fids[0]) ? $this->entityTypeManager->getStorage('file')->load($fids[0]) : NULL;
    if (!$file instanceof FileInterface) {
      $this->messenger()->addWarning($this->t('Please choose a picture to upload.'));
      return;
    }

    $file->setPermanent();
    $file->save();

    $user = $this->loadUser();
    $user->set('user_picture', $file->id());
    $user->save();
    $this->messenger()->addStatus($this->t('Your profile picture has been saved.'));
  }

  /**
   * Removes the current profile picture.
   */
  public function removePicture(array &$form, FormStateInterface $form_state): void {
    $user = $this->loadUser();
    $user->set('user_picture', NULL);
    $user->save();
    $this->messenger()->addStatus($this->t('Your profile picture has been removed.'));
  }

}
