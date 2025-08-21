<?php

namespace Drupal\soda_scs_manager\ValueObject;

/**
 * Response object for project group operations.
 */
final readonly class SodaScsKeycloakGroupData {

  /**
   * Constructor.
   *
   * @param string $groupId
   *   The group ID.
   * @param string $name
   *   The group name.
   * @param string $uuid
   *   The group UUID.
   */
  public function __construct(
    public string $groupId,
    public string $name,
    public string $uuid,
  ) {}

  /**
   * Create a new SodaScsKeycloakGroupData object.
   *
   * @param string $groupId
   *   The group ID.
   * @param string $name
   *   The group name.
   * @param string $uuid
   *   The group UUID.
   *
   * @return self
   */
  public static function create(string $groupId, string $name, string $uuid): self {
    return new self(
      groupId: $groupId,
      name: $name,
      uuid: $uuid,
    );
  }

  /**
   * Create a new SodaScsKeycloakGroupData object.
   *
   * @param string $groupId
   *   The group ID.
   * @param string $name
   *   The group name.
   * @param string $uuid
   *   The group UUID.
   *
   * @return self
   */
  public static function failure(string $error, string $message): self {
    return new self(
      groupId: '',
      name: '',
      uuid: '',
    );
  }

}
