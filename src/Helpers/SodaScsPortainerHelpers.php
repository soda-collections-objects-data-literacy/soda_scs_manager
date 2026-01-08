<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;

/**
 * Helper functions for SCS portainer operations.
 */
class SodaScsPortainerHelpers {

  /**
   * The Soda SCS docker run service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsDockerRunServiceActions
   */
  protected SodaScsRunRequestInterface $sodaScsDockerRunServiceActions;

  /**
   * The Soda SCS docker volumes service actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface
   */
  protected SodaScsServiceRequestInterface $sodaScsDockerVolumesServiceActions;

  /**
   * SodaScsPortainerHelpers constructor.
   *
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsRunRequestInterface $sodaScsDockerRunServiceActions
   *   The Soda SCS docker run service actions.
   * @param \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface $sodaScsDockerVolumesServiceActions
   *   The Soda SCS docker volumes service actions.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.docker_run_service.actions')]
    SodaScsRunRequestInterface $sodaScsDockerRunServiceActions,
    #[Autowire(service: 'soda_scs_manager.docker_volumes_service.actions')]
    SodaScsServiceRequestInterface $sodaScsDockerVolumesServiceActions,
  ) {
    $this->sodaScsDockerRunServiceActions = $sodaScsDockerRunServiceActions;
    $this->sodaScsDockerVolumesServiceActions = $sodaScsDockerVolumesServiceActions;
  }

  /**
   * Get containers of a compose stack.
   *
   * In portainer the compose stacks (!= soda_scs_stack) are named like the
   * machine name of the wisskicomponent.
   * Portainer "stacks" don't have a first-class containers-of-stack endpoint
   * in your module; the reliable approach is: list Docker containers via
   * Portainer's Docker proxy and filter by label.
   *
   * @param string|null $machineName
   *   The machine name of the compose stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result object.
   */
  public function getContainersOfComposeStack(?string $machineName = NULL): SodaScsResult {
    // First get containers from portainer.
    $queryParams = [
      'all' => 1,
    ];

    // Add filters if machine name is provided.
    if ($machineName) {
      $queryParams['filters'] = json_encode([
        'label' => [
          'com.docker.compose.project=' . $machineName,
        ],
      ]);
    }

    // Build the request parameters.
    $getAllContainersRequestParams = [
      'queryParams' => $queryParams,
    ];

    $getAllContainersRequest = $this->sodaScsDockerRunServiceActions->buildGetAllRequest($getAllContainersRequestParams);
    $getAllContainersResponse = $this->sodaScsDockerRunServiceActions->makeRequest($getAllContainersRequest);
    if (!$getAllContainersResponse['success']) {
      return SodaScsResult::failure(
        message: 'Failed to get containers of compose stack.',
        error: (string) $getAllContainersResponse['error'],
      );
    }
    $getAllContainersResponseContents = json_decode($getAllContainersResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
    $containers = $getAllContainersResponseContents;

    return SodaScsResult::success(
      message: 'Containers of compose stack retrieved successfully.',
      data: [
        'containers' => $containers,
      ],
    );
  }

  /**
   * Remove volumes of a compose stack.
   *
   * @param string $machineName
   *   The machine name of the compose stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The result object.
   */
  public function removeVolumesOfComposeStack(string $machineName): SodaScsResult {
    // Docker does not support deleting volumes by label in one request.
    // We need to list volumes with a label filter and delete them one by one.
    $getAllVolumesRequestParams = [
      'queryParams' => [
        'filters' => json_encode([
          'label' => [
            'com.docker.compose.project=' . $machineName,
          ],
        ]),
      ],
    ];

    $getAllVolumesRequest = $this->sodaScsDockerVolumesServiceActions->buildGetAllRequest($getAllVolumesRequestParams);
    $getAllVolumesResponse = $this->sodaScsDockerVolumesServiceActions->makeRequest($getAllVolumesRequest);
    if (!$getAllVolumesResponse['success']) {
      return SodaScsResult::failure(
        message: 'Failed to list volumes of compose stack.',
        error: (string) $getAllVolumesResponse['error'],
      );
    }

    $volumesPayload = json_decode($getAllVolumesResponse['data']['portainerResponse']->getBody()->getContents(), TRUE);
    $volumes = $volumesPayload['Volumes'] ?? [];

    $deletedVolumeNames = [];
    $failedVolumeDeletes = [];

    foreach ($volumes as $volume) {
      $volumeName = (string) ($volume['Name'] ?? '');
      if ($volumeName === '') {
        continue;
      }

      $deleteVolumeRequestParams = [
        'routeParams' => [
          'volumeId' => $volumeName,
        ],
      ];
      $deleteVolumeRequest = $this->sodaScsDockerVolumesServiceActions->buildDeleteRequest($deleteVolumeRequestParams);
      $deleteVolumeResponse = $this->sodaScsDockerVolumesServiceActions->makeRequest($deleteVolumeRequest);

      if ($deleteVolumeResponse['success']) {
        $deletedVolumeNames[] = $volumeName;
        continue;
      }

      $failedVolumeDeletes[] = [
        'volumeName' => $volumeName,
        'error' => (string) ($deleteVolumeResponse['error'] ?? ''),
        'statusCode' => (int) ($deleteVolumeResponse['statusCode'] ?? 0),
      ];
    }

    return SodaScsResult::success(
      message: 'Volumes of compose stack removed.',
      data: [
        'composeStackMachineName' => $machineName,
        'deletedVolumeNames' => $deletedVolumeNames,
        'failedVolumeDeletes' => $failedVolumeDeletes,
      ],
    );
  }

}
