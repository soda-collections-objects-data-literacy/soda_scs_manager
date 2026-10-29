<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ValueObject;

/**
 * Projects affected when an account is deleted.
 *
 * Owned projects are removed. Memberships are only left.
 */
final class SodaScsUserOffboardingInventory {

  /**
   * Constructs the inventory.
   *
   * @param list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface> $memberships
   *   Projects the user belongs to without owning them.
   * @param list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface> $ownedAlone
   *   Owned projects with no other members.
   * @param list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface> $ownedShared
   *   Owned projects that still have other members.
   */
  public function __construct(
    private readonly array $memberships,
    private readonly array $ownedAlone,
    private readonly array $ownedShared,
  ) {}

  /**
   * Whether any owned project still has other members.
   */
  public function hasSharedOwnership(): bool {
    return $this->ownedShared !== [];
  }

  /**
   * Projects the user belongs to without owning them.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   The memberships.
   */
  public function memberships(): array {
    return $this->memberships;
  }

  /**
   * Owned projects with no other members.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   The projects.
   */
  public function ownedAlone(): array {
    return $this->ownedAlone;
  }

  /**
   * Owned projects that still have other members.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   The projects.
   */
  public function ownedShared(): array {
    return $this->ownedShared;
  }

  /**
   * Every project the user owns.
   *
   * @return list<\Drupal\soda_scs_manager\Entity\SodaScsProjectInterface>
   *   Alone and shared projects.
   */
  public function ownedProjects(): array {
    return array_merge($this->ownedAlone, $this->ownedShared);
  }

}
