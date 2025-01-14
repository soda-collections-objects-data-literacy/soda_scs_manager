<?php

namespace Drupal\soda_scs_manager\ViewBuilder;

use Drupal\Core\Entity\EntityViewBuilder;

/**
 * The View Builder for the SodaScsComponent entity.
 */
class SodaScsComponentViewBuilder extends EntityViewBuilder {


  public function build(array $build) {
    $serviceKeyEntity = $build['#soda_scs_component']->get('serviceKey')->entity;

    if (!empty($serviceKeyEntity)) {
      $servicePassword = $serviceKeyEntity->get('servicePassword')->value;
      $build['user'] = [
        '#type' => 'item',
        '#title' => $this->t('Service User:'),
        '#markup' => '<span>' . $serviceKeyEntity->getOwner()->getDisplayName() . '</span>',
        '#weight' => 100,
      ];
      $build['password'] = [
        '#type' => 'item',
        '#title' => $this->t('Service Password:'),
        '#markup' => '<span class="soda-scs-manager--service-password">' . $servicePassword . '</span>',
        '#weight' => 100,
      ];
    }
    $build['#attached']['library'][] = 'soda_scs_manager/security';
    $build = parent::build($build);

    return $build;
  }

}
