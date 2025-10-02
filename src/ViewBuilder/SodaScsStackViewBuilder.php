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
    $build['#attached']['library'][] = 'soda_scs_manager/entityHelpers';
    $build['#attached']['drupalSettings']['entityInfo']['healthUrl'] = '/soda-scs-manager/health/stack/' . $build['#soda_scs_stack']->id();

    return $build;
  }

  /**
   * {@inheritdoc}
   */
  protected function getBuildDefaults(EntityInterface $entity, $view_mode) {
    $build = parent::getBuildDefaults($entity, $view_mode);

    $build['#theme'] = 'soda_scs_entity';
    $build['#soda_scs_stack'] = $entity;
    $build['#entity_type'] = 'stack';
    $build['#view_mode'] = $view_mode;

    return $build;
  }

}
