<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\SodaScsStackActionsInterface;

/**
 * Handles the communication with the SCS user manager daemon for triplestore stacks.
 */
class SodaScsTriplestoreStackActions implements SodaScsStackActionsInterface
{

  use DependencySerializationTrait;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected LoggerChannelFactoryInterface $loggerFactory;

  /**
   * The messenger service.
   *
   * @var \Drupal\Core\Messenger\MessengerInterface
   */
  protected MessengerInterface $messenger;

  /**
   * The SCS triplestore actions service.
   * 
   * @var \Drupal\soda_scs_manager\SodaScsComponentActionsInterface
   */ 
  protected SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions;

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
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    SodaScsComponentActionsInterface $sodaScsTriplestoreComponentActions,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->sodaScsTriplestoreComponentActions = $sodaScsTriplestoreComponentActions;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Create a triplestore stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component
   *  
   * @return array
   * 
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array
  {
    try {
      // Create the SQL component.
      $triplestoreComponentCreateResult = $this->sodaScsTriplestoreComponentActions->createComponent($component);

      if (!$triplestoreComponentCreateResult['success']) {
        return [
          'message' => 'Could not create triplestore component.',
          'data' => [
            'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          ],
          'success' => FALSE,
          'error' =>  $triplestoreComponentCreateResult['error'],
        ];
      }
      return [
        'message' => 'Could not create triplestore component.',
        'data' => [
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
        ],
        'success' => FALSE,
        'error' =>  $triplestoreComponentCreateResult['error'],
      ];

    } catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Triplestore component creation exists with error: @error trace: @trace", [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create triplestore component. See logs for more details."));
      return [
        'message' => 'Could not create triplestore component.',
        'data' => [
          'triplestoreComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' =>  $e,
      ];
    }
  }

  /**
   * Read all triplestore stacks.
   * 
   * @param $bundle
   * @param $options
   * 
   * @return array
   */
  public function getStacks($bundle, $options): array
  {
    return [];
  }

  /**
   * Read a triplestore stack.
   * 
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   */
  public function getStack($component): array
  {
    return [];
  }

  /**
   * Update a triplestore stack.
   * 
   * @param $component
   * 
   * @return array
   */
  public function updateStack($component): array
  {
    return [];
  }

  /**
   * Delete a triplestore stack.
   * 
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   * 
   * @return array
   */
  public function deleteStack(SodaScsComponentInterface $component): array
  {
    return [];
  }
}
