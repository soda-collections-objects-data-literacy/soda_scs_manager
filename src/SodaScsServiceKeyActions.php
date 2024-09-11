<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsServiceKeyActions implements SodaScsServiceKeyActionsInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Class constructor.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
  ) {
    $this->entityTypeManager = $entityTypeManager;
  }

  /**
   * Generates a random password.
   *
   * @return string
   *   Randomly generated password.
   */
  public function generateRandomPassword(): string {
    $password = '';
    while (strlen($password) < 32) {
      $password .= base_convert(random_int(0, 35), 10, 36);
    }
    return substr($password, 0, 32);
  }

  /**
   * Creates a new Service Key.
   *
   * @param array $props
   *   The service key properties.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   *   The created service key.
   */
  public function createServiceKey(array $props): SodaScsServiceKeyInterface {
    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->create([
      'label' => $props['bundle'] . ' service key' . ' owned by ' . $props['user']->getDisplayName(),
      'servicePassword' => $this->generateRandomPassword(),
      'bundle' => $props['bundle'],
      'user' => $props['userId'],
    ]);
    $serviceKey->save();
    return $serviceKey;
  }

  /**
   * Loads an existing Service Key.
   *
   * @param array $props
   *   The service key properties.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface|null
   *   The existing service key.
   */
  public function getServiceKey(array $props): ?SodaScsServiceKeyInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $serviceKeys */
    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->loadByProperties([
      'bundle' => $props['bundle'],
      'user' => $props['userId'],
    ]);

    return $serviceKey ? reset($serviceKey) : NULL;
  }

}
