<?php

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityViewBuilder;

/**
 * The View Builder for the SodaScsComponent entity.
 */
class SodaScsStackViewBuilder extends EntityViewBuilder {

  /**
   * {@inheritdoc}
   */
  public function build(array $build) {
    $build = parent::build($build);

    // Hide the flavours field if it exists in the build array.
    if (isset($build['flavours'])) {
      $build['flavours']['#access'] = FALSE;
    }

    return $build;
  }

}
