<?php

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Form\UserLoginForm;

/**
 * Extends drupal core UserLoginForm, disabling it.
 *
 * Class SodaScsDisableDrupalLoginForm
 */
class SodaScsDisableDrupalLoginForm extends UserLoginForm {

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

  // By not calling the parent buildForm function (rather than just using
  // #access as FALSE) the block still shows up, and external authentication
  // modules such as OpenID Connect can still find what they need to be added.

    return $form;
  }
}
