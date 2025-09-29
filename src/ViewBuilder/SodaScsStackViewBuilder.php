<?php

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityViewBuilder;
use Drupal\Core\Entity\EntityInterface;

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

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    $build['#theme'] = 'soda_scs_stack';
    // Make entity and view_mode available to template suggestions.
    $build['#soda_scs_stack'] = $entity;
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
