<?php

namespace Drupal\soda_scs_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Url;

/**
 * Provides a sidebar navigation block with icons.
 *
 * @Block(
 *   id = "soda_scs_manager_navigation_block",
 *   admin_label = @Translation("SODa SCS Manager Sidebar Navigation"),
 *   category = @Translation("SODa SCS Manager"),
 * )
 */
class SodaScsNavigationBlock extends BlockBase {

  /**
   * {@inheritdoc}
   */
  public function build() {
    $items = $this->getNavigationItems();

    return [
      '#theme' => 'soda_scs_manager__sidebar_navigation',
      '#items' => $items,
      '#attributes' => [
        'class' => ['soda-scs-manager--sidebar--navigation'],
      ],
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling'],
      ],
      '#contextual_links' => [],
    ];
  }

  /**
   * Gets the navigation items with their icons and URLs.
   *
   * @return array
   *   The navigation items.
   */
  protected function getNavigationItems() {
    $items = [
      [
        'title' => $this->t('Dashboard'),
        'url' => Url::fromRoute('soda_scs_manager.start_page'),
        'icon' => 'dashboard',
        'classes' => 'text-stone-200',
        'svg' => '<svg width="24" height="24" class="w-6 h-6 mx-auto" fill="currentColor" class="text-stone-200" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                   <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"></path>
                 </svg>',
      ],
      [
        'title' => $this->t('Dashboard'),
        'url' => Url::fromRoute('soda_scs_manager.dashboard'),
        'icon' => 'dashboard',
        'classes' => 'text-cyan-500',
        'svg' => '<svg width="24" height="24" class="w-6 h-6 mx-auto" fill="currentColor" class="text-cyan-500" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                   <path fill-rule="evenodd" d="M3 5a2 2 0 012-2h10a2 2 0 012 2v8a2 2 0 01-2 2h-2.22l.123.489.804.804A1 1 0 0113 18H7a1 1 0 01-.707-1.707l.804-.804L7.22 15H5a2 2 0 01-2-2V5zm5.771 7H5V5h10v7H8.771z" clip-rule="evenodd"></path>
                 </svg>',
      ],
      [
        'title' => $this->t('Catalogue'),
        'url' => Url::fromRoute('soda_scs_manager.catalogue'),
        'icon' => 'catalogue',
        'classes' => 'text-pink-500',
        'svg' => '<svg width="24" height="24" class="w-6 h-6 mx-auto" fill="currentColor" class="text-pink-500" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                   <path d="M4 3a2 2 0 100 4h12a2 2 0 100-4H4z"></path>
                   <path fill-rule="evenodd" d="M3 8h14v7a2 2 0 01-2 2H5a2 2 0 01-2-2V8zm5 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z" clip-rule="evenodd"></path>
                 </svg>',
      ],
      [
        'title' => $this->t('Settings'),
        'url' => Url::fromRoute('soda_scs_manager.settings'),
        'icon' => 'settings',
        'classes' => 'text-violet-500',
        'svg' => '<svg width="24" height="24" class="w-6 h-6 mx-auto" fill="currentColor" class="text-violet-500" viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg">
                   <path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"></path>
                 </svg>',
      ],
    ];

    return $items;
  }

}
