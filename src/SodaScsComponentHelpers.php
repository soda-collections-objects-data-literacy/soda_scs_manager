<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;

class SodaScsComponentHelpers {

  /**
   * Retrieves a referenced component of a given bundle.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * @param string $bundle
   * 
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   * 
   * @throws \Drupal\soda_scs_manager\Exceptions\SodaScsComponentException
   * 
   */
  public function retrieveReferencedComponent(SodaScsComponentInterface $component, string $bundle): SodaScsComponentInterface {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $referencedComponentsItemList */
    $referencedComponentsItemList = $component->get('referencedComponents');
    $referencedComponents = $referencedComponentsItemList->referencedEntities();
    $components = array_filter($referencedComponents, function ($referencedComponent) use ($bundle) {
          return $referencedComponent->bundle() === $bundle;
      });
    $referencedComponent = !empty($components) ? reset($components) : null;
    if (!$referencedComponent) {
      throw new SodaScsComponentException('Could not find SQL component.', 1, NULL);
    }
    return $referencedComponent;
}
}