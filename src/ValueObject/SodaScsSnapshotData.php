<?php

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
   * @param array $createSnapshotContainerResponse
   *   The create snapshot container response.
   * @param array $metadata
   *   The metadata.
   * @param array $startSnapshotContainerResponse
   *   The start snapshot container response.
   * @param array|null $snapshotContainerStatus
   *   The snapshot container status.
   * @param array|null $startWisskiContainerResponse
   *   The start WissKI container response.
   * @param string $componentBundle
   *   The component bundle.
   * @param string $componentId
   *   The component ID.
   * @param string $componentMachineName
   *   The component machine name.
   * @param string $snapshotContainerId
   *   The snapshot container ID.
   * @param string $snapshotContainerName
   *   The snapshot container name.
   */
  public function __construct(
    public array $createSnapshotContainerResponse,
    public array $metadata,
    public array $startSnapshotContainerResponse,
    public array|null $snapshotContainerStatus,
    public array|null $startWisskiContainerResponse,
    public string $componentBundle,
    public string $componentId,
    public string $componentMachineName,
    public string $snapshotContainerId,
    public string $snapshotContainerName,
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
      snapshotContainerStatus: $componentData['snapshotContainerStatus'] ?? NULL,
      startSnapshotContainerResponse: $componentData['startSnapshotContainerResponse'],
      startWisskiContainerResponse: $componentData['startWisskiContainerResponse'] ?? NULL,
    );
  }

}
