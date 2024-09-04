<?php

namespace Drupal\soda_scs_manager;


use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\SodaScsServiceKeyActions;


/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsTriplestoreComponentActions implements SodaScsComponentActionsInterface {

  use DependencySerializationTrait;

  /**
   * The entity type manager.
   * 
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The settings config.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected Config $settings;

  /**
   * The SCS Service Key actions service.
   *
   * @var \Drupal\soda_scs_manager\SodaScsServiceKeyActions
   */
  protected SodaScsServiceKeyActions $sodaScsServiceKeyActions;

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;



  /**
   * Class constructor.
   */
  public function __construct(
    ConfigFactoryInterface $configFactory,
    EntityTypeManagerInterface $entityTypeManager,

  ) {
    // Services from container.
    $settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->entityTypeManager = $entityTypeManager;

  }


  /**
   * Create Triplestore Component.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function createComponent(SodaScsComponentInterface $component): array {
    $triplestoreEntity = $this->entityTypeManager->getStorage('soda_scs_component')->create(
      [
        'label' => $component->label() . ' Triplestore',
        'bundle' => 'triplestore',
        'subdomain' => $component->get('subdomain')->value . '-ts',
      ]
    );

    $triplestoreEntity->save();
    return [];
  }

  public function getComponent(SodaScsComponentInterface $component): array {
    return [];
  }

public function updateComponent(SodaScsComponentInterface $component): array {
    return [];
  }

  public function deleteComponent(SodaScsComponentInterface $component): array {
      return [];
  }

}