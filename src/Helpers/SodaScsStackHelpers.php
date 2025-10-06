<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentActionsException;

/**
 * Helper functions for SCS components.
 */
class SodaScsStackHelpers {
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * The Soda SCS component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * SodaScsStackHelpers constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The Soda SCS component helpers.
   *   The messenger.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
  }

  /**
   * Retrieves a referenced component of a given SODa SCS Stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   * @param string $bundle
   *   The bundle of the referenced component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   *   The referenced component.
   *
   * @throws \Drupal\soda_scs_manager\Exceptions\SodaScsComponentException
   *   When the referenced component is not found.
   */
  public function retrieveIncludedComponent(SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface {

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponents = $stack->getValue($stack, 'includedComponents');

    $includedComponent = array_values(array_filter($includedComponents, function ($includedComponent) use ($bundle) {
      $componentBundle = $includedComponent->bundle->get(0)->get('value')->getValue();
      return $componentBundle === $bundle;
    }))[0] ?? NULL;
    if (!$includedComponent) {
      throw new SodaScsComponentActionsException('Component not found', 1);
    }
    return $includedComponent;
  }

  /**
   * Remove value from included component field of a given SODa SCS stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component value to be deleted from field list.
   */
  public function removeIncludedComponentValue(SodaScsStackInterface $stack, SodaScsComponentInterface $component) {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();
    $filteredComponents = array_filter($includedComponents, function ($includedComponent) use ($component) {
      return $includedComponent->target_id != $component->id();
    });
    $stack->set('includedComponents', $filteredComponents);
    $stack->save();
  }

  /**
   * Remove non existing components from includedComponents field.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   */
  public function cleanIncludedComponents(SodaScsStackInterface $stack) {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();
    $filteredComponents = array_filter($includedComponents, function ($includedComponent) {
      return $includedComponent->id() !== NULL;
    });
    $stack->set('includedComponents', $filteredComponents);
    $stack->save();
  }

  /**
   * Check the health of a WissKI stack.
   *
   * @param string $machineName
   *   The machine name of the WissKI stack.
   *
   * @return array
   *   The health of the WissKI stack.
   */
  public function checkWisskiHealth(string $machineName) {
    // Get the stack entity by machine name.
    $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $stackEntities = $stackStorage->loadByProperties(['machineName' => $machineName]);

    if (empty($stackEntities)) {
      return [
        'message' => 'Stack not found.',
        'code' => 404,
        'success' => FALSE,
        'error' => 'No stack found with machine name: ' . $machineName,
      ];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    $stack = reset($stackEntities);

    // Get included components.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();

    // Find the WissKI component.
    $wisskiComponent = NULL;
    foreach ($includedComponents as $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      if ($component->get('bundle')->value === 'soda_scs_wisski_component') {
        $wisskiComponent = $component;
        break;
      }
    }

    if (!$wisskiComponent) {
      return [
        'message' => 'WissKI component not found in stack.',
        'code' => 404,
        'success' => FALSE,
        'error' => 'No WissKI component found in stack: ' . $machineName,
      ];
    }

    // Check the health of the WissKI component.
    return $this->sodaScsComponentHelpers->drupalHealthCheck($wisskiComponent->get('machineName')->value);
  }

  /**
   * Check the health of a Jupyter stack.
   *
   * @param string $machineName
   *   The machine name of the Jupyter stack.
   *
   * @return array
   *   The health of the Jupyter stack.
   */
  public function checkJupyterHealth(string $machineName) {
    return [
      'message' => 'Jupyter health check not implemented yet.',
      'code' => 501,
      'success' => FALSE,
      'error' => 'Jupyter health check not implemented yet.',
    ];
  }

  /**
   * Check the health of a Nextcloud stack.
   *
   * @param string $machineName
   *   The machine name of the Nextcloud stack.
   *
   * @return array
   *   The health of the Nextcloud stack.
   */
  public function checkNextcloudHealth(string $machineName) {
    return [
      'message' => 'Nextcloud health check not implemented yet.',
      'code' => 501,
      'success' => FALSE,
      'error' => 'Nextcloud health check not implemented yet.',
    ];
  }

}
