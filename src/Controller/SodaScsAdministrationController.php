<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;

/**
 * The SODa SCS Manager administration controller.
 */
final class SodaScsAdministrationController extends ControllerBase {

  /**
   * Builds the administration page.
   *
   * @return array
   *   The page build array.
   */
  public function administrationPage(): array {
    $modulePath = \Drupal::service('extension.list.module')->getPath('soda_scs_manager');
    $adminSections = [
      [
        'title' => $this->t('Components'),
        'description' => $this->t('Manage single application components like databases, inter-app folders, etc.'),
        'icon' => 'database-admin',
        'icon_path' => '/' . $modulePath . '/assets/images/database-admin.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-database',
        'url' => Url::fromRoute('entity.soda_scs_component.collection'),
        'permission' => 'soda scs manager user',
        'color' => 'emerald',
      ],
      [
        'title' => $this->t('Stacks'),
        'description' => $this->t('Manage application suites and environments like JupyterLab, Nextcloud, etc.'),
        'icon' => 'layers',
        'icon_path' => '/' . $modulePath . '/assets/images/layers.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-layers',
        'url' => Url::fromRoute('entity.soda_scs_stack.collection'),
        'permission' => 'soda scs manager user',
        'color' => 'blue',
      ],
      [
        'title' => $this->t('Projects'),
        'description' => $this->t('Manage projects for collaboration and resource sharing.'),
        'icon' => 'folder',
        'icon_path' => '/' . $modulePath . '/assets/images/folder.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-folder',
        'url' => Url::fromRoute('entity.soda_scs_project.collection'),
        'permission' => 'soda scs manager user',
        'color' => 'amber',
      ],
      [
        'title' => $this->t('Snapshots'),
        'description' => $this->t('Manage snapshots for backup and restore operations.'),
        'icon' => 'camera',
        'icon_path' => '/' . $modulePath . '/assets/images/camera.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-camera',
        'url' => Url::fromRoute('entity.soda_scs_snapshot.collection'),
        'permission' => 'soda scs manager user',
        'color' => 'purple',
      ],
      [
        'title' => $this->t('Service Keys'),
        'description' => $this->t('Manage service keys for applications and external services.'),
        'icon' => 'key',
        'icon_path' => '/' . $modulePath . '/assets/images/key.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-key',
        'url' => Url::fromRoute('entity.soda_scs_service_key.collection'),
        'permission' => 'soda scs manager admin',
        'color' => 'rose',
      ],
      [
        'title' => $this->t('Settings'),
        'description' => $this->t('Configure SODa SCS Manager settings and preferences.'),
        'icon' => 'cog',
        'icon_path' => '/' . $modulePath . '/assets/images/cog.svg',
        'icon_class' => 'soda-scs-manager--admin-icon-cog',
        'url' => Url::fromRoute('soda_scs_manager.settings'),
        'permission' => 'soda scs manager admin',
        'color' => 'slate',
      ],
    ];

    // Filter sections based on user permissions.
    $currentUser = $this->currentUser();
    $cards = [];
    foreach ($adminSections as $section) {
      if ($currentUser->hasPermission($section['permission'])) {
        $cards[] = [
          '#theme' => 'soda_scs_manager__admin_card',
          '#title' => $section['title'],
          '#description' => $section['description'],
          '#icon' => $section['icon'],
          '#icon_path' => $section['icon_path'],
          '#icon_class' => $section['icon_class'],
          '#url' => $section['url'],
          '#color' => $section['color'],
        ];
      }
    }

    $build = [
      '#theme' => 'soda_scs_manager__administration',
      '#attributes' => ['class' => ['container', 'mx-auto']],
      '#cards' => $cards,
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
          'soda_scs_manager/administration',
        ],
      ],
      '#cache' => [
        'contexts' => ['user.permissions'],
      ],
    ];

    return $build;
  }

}

