<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\file\Entity\File;


/**
 * Class ComponentBundleController.
 */
class SodaScsComponentBundleController extends ControllerBase
{

  /**
   * Title callback
   *
   * @return string
   */
  public function title(): string
  {
    return $this->t('Component Bundles');
  }
}
