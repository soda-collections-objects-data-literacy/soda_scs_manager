<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for Soda SCS component routes.
 */
class SodaScsComponentController extends ControllerBase {

  /**
   * The SODa SCS Manager component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * The SODa SCS Manager Drupal helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsDrupalHelpers
   */
  protected $sodaScsDrupalHelpers;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    SodaScsDrupalHelpers $sodaScsDrupalHelpers,
    EntityTypeManagerInterface $entity_type_manager,
  ) {
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsDrupalHelpers = $sodaScsDrupalHelpers;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.component.helpers'),
      $container->get('soda_scs_manager.drupal.helpers'),
      $container->get('entity_type.manager'),
    );
  }

  /**
   * Check the status of a component.
   */
  public function componentStatus($component_id) {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
    $component = $this->entityTypeManager
      ->getStorage('soda_scs_component')
      ->load($component_id);

    if (!$component) {
      return new JsonResponse(['status' => 'not_found'], 404);
    }

    $bundle = $component->get('bundle')->value;
    switch ($bundle) {
      case 'soda_scs_filesystem_component':
        $filesystemHealth = $this->sodaScsComponentHelpers
          ->checkFilesystemHealth($component->get('machineName')->value);
        if (!$filesystemHealth) {
          return new JsonResponse([
            'status' => [
              'message' => $this->t("Filesystem health check failed for component @component. Message: @message", [
                '@component' => $component->id(),
                '@message' => $filesystemHealth['message'],
              ]),
              'success' => FALSE,
              'status' => $filesystemHealth['status'],
            ],
            'code' => $filesystemHealth['code'],
          ]);
        }
        return new JsonResponse(['status' => $filesystemHealth]);

      case 'soda_scs_sql_component':

        $sqlHealth = $this->sodaScsComponentHelpers->checkSqlHealth((int) $component->id());
        if (!$sqlHealth) {
          return new JsonResponse(
            [
              'status' => [
                'message' => $this->t("MariaDB health check failed for component @component.", ['@component' => $component->id()]),
                'status' => $sqlHealth['status'],
                'success' => FALSE,
              ],
              'code' => $sqlHealth['code'],
            ],
          );
        }
        return new JsonResponse(['status' => $sqlHealth]);

      case 'soda_scs_triplestore_component':
        $triplestoreHealth = $this->sodaScsComponentHelpers
          ->checkTriplestoreHealth($component->get('machineName')->value, $component->get('machineName')->value);
        if (!$triplestoreHealth) {
          return new JsonResponse([
            'status' => [
              'message' => $this->t("Triplestore health check failed for component @component.", ['@component' => $component->id()]),
              'status' => $triplestoreHealth['status'],
              'success' => FALSE,
            ],
            'code' => $triplestoreHealth['code'],
          ]);
        }
        return new JsonResponse(['status' => $triplestoreHealth]);

      case 'soda_scs_wisski_component':
        $wisskiHealth = $this->sodaScsComponentHelpers
          ->drupalHealthCheck($component);
        if (!$wisskiHealth) {
          return new JsonResponse([
            'status' => [
              'message' => $this->t("WissKI health check failed for component @component.", ['@component' => $component->id()]),
              'status' => $wisskiHealth['status'],
              'success' => FALSE,
            ],
            'code' => $wisskiHealth['code'],
          ]);
        }
        return new JsonResponse(['status' => $wisskiHealth]);

      default:
        return new JsonResponse(
          [
            'status' => [
              'message' => $this->t("Health check failed for component @component with message: @message", [
                '@component' => $component->id(),
                '@message' => 'Unknown component type.',
              ]),
              'status' => 'unknown',
              'success' => FALSE,
            ],
            'code' => 500,
          ],
          500,
        );
    }
  }

  /**
   * Page of installed Drupal packages.
   *
   * Sends a docker run request to the Drupal container to get the installed
   * packages via composer list command.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS component.
   *
   * @return array
   *   The render array.
   */
  public function installedDrupalPackages(SodaScsComponentInterface $soda_scs_component): array {
    $drupalPackages = $this->sodaScsDrupalHelpers->getInstalledDrupalPackages($soda_scs_component);

    $build = [
      // @todo Implement proper cache, when infos had changed.
      '#cache' => [
        'max-age' => 0,
      ],
    ];

    if (!$drupalPackages->success) {
      $build['message'] = [
        '#type'   => 'markup',
        '#markup' => $this->t('Failed to get installed Drupal packages: @message', [
          '@message' => (string) $drupalPackages->message,
        ]),
      ];

      $build['details'] = [
        '#type'  => 'details',
        '#title' => $this->t('Error details'),
        '#open'  => FALSE,
        'error'  => [
          '#type'   => 'markup',
          '#markup' => '<pre>' . Html::escape((string) ($drupalPackages->error ?? '')) . '</pre>',
        ],
      ];

      return $build;
    }

    $packages = $drupalPackages->data['packages'] ?? [];
    $rows = [];
    foreach ($packages as $package) {
      if (!is_array($package)) {
        continue;
      }
      $rows[] = [
        ['data' => (string) ($package['name'] ?? ''), 'class' => ['soda-scs-manager--package-name']],
        ['data' => (string) ($package['version'] ?? '')],
        ['data' => (string) ($package['description'] ?? '')],
      ];
    }

    $build['table'] = [
      '#type'   => 'table',
      '#header' => [
        $this->t('Package'),
        $this->t('Version'),
        $this->t('Description'),
      ],
      '#rows'   => $rows,
      '#empty'  => $this->t('No packages found.'),
    ];

    return $build;
  }

  /**
   * JSON endpoint for installed Drupal packages.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $soda_scs_component
   *   The SODa SCS component.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response.
   */
  public function installedDrupalPackagesJson(SodaScsComponentInterface $soda_scs_component): JsonResponse {
    $drupalPackages = $this->getInstalledDrupalPackagesResult($soda_scs_component);
    if (!$drupalPackages->success) {
      return new JsonResponse(['status' => $drupalPackages->error], 500);
    }
    return new JsonResponse(['status' => $drupalPackages->data]);
  }

}
