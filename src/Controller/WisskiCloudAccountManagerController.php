<?php

namespace Drupal\wisski_cloud_account_manager\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * The Wisski Cloud account manager info controller.
 */
class WisskiCloudAccountManagerController extends ControllerBase {

  /**
   * Info page for terms and conditions.
   *
   * @return array
   *   The page build array.
   */
  public function termsAndConditions(): array {
    $build = [
      '#markup' => $this->t('Hello World!'),
    ];
    return $build;
  }

}
