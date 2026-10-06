<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormStateInterface;

/**
 * Change the password of the current user.
 */
final class SodaScsProfileSecurityForm extends SodaScsProfileFormBase {

  private const MIN_PASSWORD_LENGTH = 8;

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_manager_profile_security_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['password'] = [
      '#type' => 'password',
      '#title' => $this->t('New password'),
      '#required' => TRUE,
      '#attributes' => ['autocomplete' => 'new-password'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Change password'),
      '#button_type' => 'primary',
      '#attributes' => ['class' => ['btn', 'btn--primary']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (mb_strlen((string) $form_state->getValue('password')) < self::MIN_PASSWORD_LENGTH) {
      $form_state->setErrorByName('password', $this->t('The password must be at least @count characters long.', [
        '@count' => self::MIN_PASSWORD_LENGTH,
      ]));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $user = $this->loadUser();
    $password = (string) $form_state->getValue('password');

    // The password lives in Keycloak for single-sign-on accounts.
    $ssoUuid = $this->projectHelpers->getUserSsoUuid($user);
    if ($ssoUuid !== NULL) {
      if (!$this->keycloakHelpers->resetKeycloakUserPassword($ssoUuid, $password)) {
        $this->messenger()->addError($this->t('The password could not be changed. It may not meet the password policy.'));
        return;
      }
    }
    else {
      $user->setPassword($password);
      $user->save();
    }

    $this->messenger()->addStatus($this->t('Your password has been changed.'));
  }

}
