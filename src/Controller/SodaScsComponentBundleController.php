<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Class ComponentBundleController.
 */
class SodaScsComponentBundleController extends ControllerBase {

  /**
   * Title callback.
   *
   * @return string
   *   The title.
   */
  public function title(): string {
    return $this->t('Component Bundles');
  }

}
