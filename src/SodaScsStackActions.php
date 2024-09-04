<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentException;

/**
* Handles the communication with the SCS user manager daemon.
*/
class SodaScsStackActions implements SodaScsStackActionsInterface {
  
  use DependencySerializationTrait;
  
  
  /**
  * The SCS sql actions service.
  *
  * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
  */
  protected SodaScsStackActionsInterface $sodaScsSqlStackActions;
  
  /**
  * The SCS wisski actions service.
  *
  * @var \Drupal\soda_scs_manager\SodaScsStackActionsInterface
  */
  protected SodaScsStackActionsInterface $sodaScsWisskiStackActions;
  
  
  /**
  * Class constructor.
  */
  public function __construct(
    SodaScsStackActionsInterface $sodaScsSqlStackActions,
    SodaScsStackActionsInterface $sodaScsWisskiStackActions,
    ) {
      $this->sodaScsSqlStackActions = $sodaScsSqlStackActions;
      $this->sodaScsWisskiStackActions = $sodaScsWisskiStackActions;
    }
    
    /**
    * Creates a stack.
    * 
    * A stack consists of one or more components.
    * We sort by bundle.
    * 
    * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
    *   The component.
    *
    * @return array
    *   The result of the request.
    *
    */
    public function createStack(SodaScsComponentInterface $component): array {
      #$componentParams = assembleComponentParams($component);
      switch ($component->bundle()) {
        case 'wisski':
          return $this->sodaScsWisskiStackActions->createStack($component);
          default:
          return [  
          ];
          break;
        }
      }
      
      /**
      * Get all stacks of a bundle.
      *
      * @param $bundle
      * @param $options
      *
      * @return array
      */
      public function getStacks($bundle, $options): array {
        switch ($bundle) {
          case 'wisski':
            return $this->sodaScsWisskiStackActions->getStacks($bundle, $options);
            default:
            return [];
          }
        }
        
        /**
        * Read a stack.
        *
        * @param $component
        *
        * @return array
        */
        public function getStack($component): array {
          return  [
            'message' => 'Component read',
            'data' => [],
            'error' => NULL,
            'success' => TRUE,
          ];
          
        }
        
        /**
        * Updates a stack.
        *
        * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
        *
        * @return array
        */
        public function updateStack($component): array {
          switch ($component->bundle()) {
            case 'wisski':
              return $this->sodaScsWisskiStackActions->updateStack($component);
              default:
              return [];
            }
          }
          
          /**
          * Deletes a stack.
          *
          * @param $component
          *
          * @return array
          */
          public function deleteStack($component): array {
            switch ($component->bundle()) {
              case 'wisski':
                return $this->sodaScsWisskiStackActions->deleteStack($component);
                break;
                case 'sql':
                  return $this->sodaScsSqlStackActions->deleteStack($component);
                  break;
                  default:
                  return [
                    'message' => 'Component not deleted',
                    'data' => [
                    ],
                    'success' => FALSE,
                    'error' => NULL,
                  ];
                  break;
                }
                
              }
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