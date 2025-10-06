<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ValueObject;

/**
 * Response object for project group operations.
 *
 * @todo Use this class for all responses.
 */
final class SodaScsSnapshotData {

  /**
   * Constructor.
   *
   * @param string $componentBundle
   *   The component bundle.
   * @param string $componentId
   *   The component ID.
   * @param string $componentMachineName
   *   The component machine name.
   * @param array $createSnapshotContainerResponse
   *   The create snapshot container response.
   * @param array $metadata
   *   The metadata.
   * @param string $snapshotContainerId
   *   The snapshot container ID.
   * @param string $snapshotContainerName
   *   The snapshot container name.
   * @param array|null $snapshotContainerRemoved
   *   The snapshot container removed.
   * @param array|null $snapshotContainerStatus
   *   The snapshot container status.
   * @param array $startSnapshotContainerResponse
   *   The start snapshot container response.
   * @param array|null $startWisskiContainerResponse
   *   The start WissKI container response.
   */
  public function __construct(
    public string $componentBundle,
    public string $componentId,
    public string $componentMachineName,
    public array $createSnapshotContainerResponse,
    public array $metadata,
    public string $snapshotContainerId,
    public string $snapshotContainerName,
    public bool|null $snapshotContainerRemoved,
    public array|null $snapshotContainerStatus,
    public array $startSnapshotContainerResponse,
    public array|null $startWisskiContainerResponse,
  ) {}

  /**
   * Create snapshot data from component array.
   *
   * @param array $componentData
   *   The component data array.
   *
   * @return self
   *   The SodaScsSnapshotData object.
   */
  public static function fromArray(array $componentData): self {
    return new self(
      componentBundle: $componentData['componentBundle'],
      componentId: $componentData['componentId'],
      componentMachineName: $componentData['componentMachineName'],
      createSnapshotContainerResponse: $componentData['createSnapshotContainerResponse'],
      metadata: $componentData['metadata'],
      snapshotContainerId: $componentData['snapshotContainerId'],
      snapshotContainerName: $componentData['snapshotContainerName'],
      snapshotContainerRemoved: $componentData['snapshotContainerRemoved'] ?? NULL,
      snapshotContainerStatus: $componentData['snapshotContainerStatus'] ?? NULL,
      startSnapshotContainerResponse: $componentData['startSnapshotContainerResponse'],
      startWisskiContainerResponse: $componentData['startWisskiContainerResponse'] ?? NULL,
    );
  }

}
