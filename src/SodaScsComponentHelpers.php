<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;

/**
 * Helper functions for SCS components.
 */
class SodaScsComponentHelpers {
  use StringTranslationTrait;

  public function __construct(TranslationInterface $stringTranslation) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Retrieves a referenced component of a given bundle.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The SODa SCS Component.
   * @param string $bundle
   *   The bundle of the referenced component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface
   *   The referenced component.
   *
   * @throws \Drupal\soda_scs_manager\Exceptions\SodaScsComponentException
   *   When the referenced component is not found.
   */
  public function retrieveReferencedComponent(SodaScsComponentInterface $component, string $bundle): SodaScsComponentInterface {

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $referencedComponentsItemList */
    $referencedComponentsItemList = $component->get('referencedComponents');
    $referencedComponents = $referencedComponentsItemList->referencedEntities();
    $components = array_filter($referencedComponents, function ($referencedComponent) use ($bundle) {
      return $referencedComponent->bundle() === $bundle;
    });
    $referencedComponent = !empty($components) ? reset($components) : NULL;
    if (!$referencedComponent) {
      throw new SodaScsComponentException(('Could not find component.'), 1, NULL);
    }
    return $referencedComponent;
  }

}
