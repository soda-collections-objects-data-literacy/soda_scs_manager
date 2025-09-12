<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;

/**
 * Helper functions for SCS components.
 */
class SodaScsStackHelpers {
  use StringTranslationTrait;

  /**
   * The messenger.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected $messenger;

  /**
   * SodaScsStackHelpers constructor.
   *
   * @param \Drupal\Core\Messenger\MessengerInterface $messenger
   *   The messenger.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation.
   */
  public function __construct(MessengerInterface $messenger, TranslationInterface $stringTranslation) {
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
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
      throw new SodaScsComponentException('Component not found', 1);
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

}
