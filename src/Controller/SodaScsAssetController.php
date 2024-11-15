<?php

namespace Drupal\soda_scs_manager\Controller;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Load assets outside the public filesystem.
 */
class SodaScsAssetController {

  /**
   * Load an image asset.
   *
   * @param string $asset
   *   The asset to load.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The response object.
   */
  public function loadImage($asset) {
    $module_handler = \Drupal::service('module_handler');
    $module_path = $module_handler->getModule('soda_scs_manager')->getPath();
    $file = "$module_path/assets/images/$asset";
    return new BinaryFileResponse($file);
  }

  /**
   * Load a spec file.
   *
   * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
   *   The response object.
   */
  public function loadSpec() {
    $module_handler = \Drupal::service('module_handler');
    $module_path = $module_handler->getModule('soda_scs_manager')->getPath();
    $file = "$module_path/spec/soda-scs-api-spec.yaml";
    return new BinaryFileResponse($file);
  }

}
