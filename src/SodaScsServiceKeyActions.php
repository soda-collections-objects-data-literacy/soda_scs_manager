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
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The service component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface
   *   The created service key.
   */
  public function createServiceKey($component): SodaScsServiceKeyInterface {
    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->create([
      'label' => $component->get('bundle')->target_id . ' service key' . ' owned by ' . $component->getOwner()->getDisplayName(),
      'servicePassword' => $this->generateRandomPassword(),
      'bundle' => $component->get('bundle')->target_id,
      'user' => $component->getOwnerId(),
    ]);
    $serviceKey->save();
    return $serviceKey;
  }

  /**
   * Loads an existing Service Key.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The service component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface|null
   *   The existing service key.
   */
  public function getServiceKey($component): ?SodaScsServiceKeyInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsServiceKeyInterface $serviceKeys */
    $serviceKey = $this->entityTypeManager->getStorage('soda_scs_service_key')->loadByProperties([
      'bundle' => $component->get('bundle')->target_id,
      'user' => $component->getOwnerId(),
    ]);

    return $serviceKey ? reset($serviceKey) : NULL;
  }

}
