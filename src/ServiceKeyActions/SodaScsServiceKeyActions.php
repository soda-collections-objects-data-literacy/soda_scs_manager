<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\ServiceKeyActions;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
#[Autowire(service: 'soda_scs_manager.service_key.actions')]
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

    $servicePassword = ($props['type'] === 'token') ? $props['token'] : $this->generateRandomPassword();

    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->create([
      'bundle' => $props['bundle'],
      'label' => $props['bundleLabel'] . ' ' . $props['type'] . ' (' . $props['username'] . ')',
      'servicePassword' => $servicePassword,
      'scsComponentBundle' => $props['bundle'],
      'type' => $props['type'],
      'owner' => $props['userId'],
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
      'scsComponentBundle' => $props['bundle'],
      'owner' => $props['userId'],
      'type' => $props['type'],
    ]);

    return $serviceKey ? reset($serviceKey) : NULL;
  }

}
