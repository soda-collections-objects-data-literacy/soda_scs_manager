<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;

/**
 * Helper class for Soda SCS actions.
 */
#[Autowire(service: 'soda_scs_manager.actions.helpers')]
class SodaScsActionsHelper {

  /**
   * The SCS sql component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsSqlComponentActions;

  /**
   * The SCS triplestore component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

  /**
   * The SCS wisski component actions.
   *
   * @var \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface
   */
  protected SodaScsComponentActionsInterface $sodaScsWisskiComponentActions;

  /**
   * SodaScsActionsHelper constructor.
   *
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsSqlComponentActions
   *   The SCS sql component actions.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions
   *   The SCS triplestore component actions.
   * @param \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface $sodaScsWisskiComponentActions
   *   The SCS wisski component actions.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.sql_component.actions')]
    SodaScsComponentActionsInterface $sodaScsSqlComponentActions,
    #[Autowire(service: 'soda_scs_manager.triplestore_component.actions')]
    SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions,
    #[Autowire(service: 'soda_scs_manager.wisski_component.actions')]
    SodaScsComponentActionsInterface $sodaScsWisskiComponentActions,
  ) {
    $this->sodaScsSqlComponentActions = $sodaScsSqlComponentActions;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->sodaScsWisskiComponentActions = $sodaScsWisskiComponentActions;
  }

  /**
   * Get the appropriate component actions service for a bundle.
   *
   * @param string $bundle
   *   The component bundle.
   *
   * @return \Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface|null
   *   The component actions service or NULL if not found.
   */
  public function getComponentActionsForBundle(string $bundle): ?SodaScsComponentActionsInterface {
    switch ($bundle) {
      case 'soda_scs_sql_component':
        return $this->sodaScsSqlComponentActions;

      case 'soda_scs_triplestore_component':
        return $this->sodaScsTriplestoreComponentActions;

      case 'soda_scs_wisski_component':
        return $this->sodaScsWisskiComponentActions;

      default:
        return NULL;
    }
  }

}
