<?php

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class ComponentBundleController.
 */
class SodaScsComponentController extends ControllerBase {

  /**
   * The SODa SCS Manager component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * {@inheritDoc}
   */
  public function __construct(SodaScsComponentHelpers $sodaScsComponentHelpers, EntityTypeManagerInterface $entity_type_manager) {
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritDoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('soda_scs_manager.component.helpers'),
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
              'message' => $this->t("Filesystem health check failed for component @component. Message: @message", ['@component' => $component->id(), '@message' => $filesystemHealth['message']]),
              'success' => FALSE,
            ],
            'code' => $filesystemHealth['code'],
          ]);
        }
        return new JsonResponse(['status' => $filesystemHealth]);

        break;

      case 'soda_scs_sql_component':
        $sqlHealth = $this->sodaScsComponentHelpers->checkSqlHealth($component->id());
        if (!$sqlHealth) {
          return new JsonResponse(
            [
              'status' => [
                'message' => $this->t("MariaDB health check failed for component @component.", ['@component' => $component->id()]),
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
              'success' => FALSE,
            ],
            'code' => $triplestoreHealth['code'],
          ]);
        }
        return new JsonResponse(['status' => $triplestoreHealth]);

      case 'soda_scs_wisski_component':
        $wisskiHealth = $this->sodaScsComponentHelpers
          ->drupalHealthCheck($component->get('machineName')->value);
        if (!$wisskiHealth) {
          return new JsonResponse([
            'status' => [
              'message' => $this->t("WissKI health check failed for component @component.", ['@component' => $component->id()]),
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
              'message' => $this->t("Health check failed for component @component with message: @message", ['@component' => $component->id(), '@message' => 'Unknown component type.']),
              'success' => FALSE,
            ],
            'code' => 500,
          ],
          500,
        );
    }
  }

}
