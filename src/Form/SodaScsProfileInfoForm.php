<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Component\Utility\Html;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\soda_scs_manager\Validation\PersonalNameValidator;

/**
 * Edit email, first name and last name of the current user.
 */
final class SodaScsProfileInfoForm extends SodaScsProfileFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_profile_info_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $user = $this->loadUser();

    $form['username'] = [
      '#type' => 'item',
      '#title' => $this->t('Username'),
      '#markup' => Markup::create('<p class="profile__username">' . Html::escape($user->getAccountName()) . '</p>'),
    ];
    $form['mail'] = [
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $user->getEmail(),
      '#required' => TRUE,
    ];
    $form['first_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('First name'),
      '#default_value' => $user->get('first_name')->value ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['last_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Last name'),
      '#default_value' => $user->get('last_name')->value ?? '',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn', 'btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    foreach (['first_name', 'last_name'] as $fieldName) {
      $violation = PersonalNameValidator::validate((string) $form_state->getValue($fieldName));
      if ($violation !== NULL) {
        $form_state->setErrorByName($fieldName, PersonalNameValidator::violationMessage($violation, $form[$fieldName]['#title']));
      }
    }

    $mailTaken = $this->entityTypeManager->getStorage('user')->getQuery()
      ->accessCheck(FALSE)
      ->condition('mail', (string) $form_state->getValue('mail'))
      ->condition('uid', $this->currentUser()->id(), '<>')
      ->range(0, 1)
      ->execute();
    if ($mailTaken !== []) {
      $form_state->setErrorByName('mail', $this->t('This email address is already in use.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->loadUser();
    $mail = trim((string) $form_state->getValue('mail'));
    $firstName = trim((string) $form_state->getValue('first_name'));
    $lastName = trim((string) $form_state->getValue('last_name'));

    $user->setEmail($mail);
    $user->set('first_name', $firstName);
    $user->set('last_name', $lastName);
    $user->save();

    // Login re-imports names from Keycloak, so push the change there too.
    $ssoUuid = $this->projectHelpers->getUserSsoUuid($user);
    if ($ssoUuid !== NULL && !$this->keycloakHelpers->updateKeycloakUserProfile($ssoUuid, [
      'email' => $mail,
      'firstName' => $firstName,
      'lastName' => $lastName,
    ])) {
      $this->messenger()->addWarning($this->t('Your changes were saved here, but could not be synchronised with the login service.'));
      return;
    }

    $this->messenger()->addStatus($this->t('Your profile has been saved.'));
  }

}
