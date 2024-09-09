<?php

namespace Drupal\soda_scs_manager;

use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

/**
 * Handles the triplestore stack actions.
 */
class SodaScsTriplestoreStackActions implements SodaScsStackActionsInterface {

  use DependencySerializationTrait;
  use StringTranslationTrait;

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
   *   The component.
   *
   * @return array
   *   The result.
   *
   * @throws \Exception
   */
  public function createStack(SodaScsComponentInterface $component): array {
    try {
      // Create the SQL component.
      $triplestoreComponentCreateResult = $this->sodaScsTriplestoreComponentActions->createComponent($component);

      if (!$triplestoreComponentCreateResult['success']) {
        return [
          'message' => $this->t('Could not create triplestore stack: %message', ['%message' => $triplestoreComponentCreateResult['message']]),
          'data' => [
            'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
          ],
          'success' => FALSE,
          'error' => $triplestoreComponentCreateResult['error'],
        ];
      }
      return [
        'message' => $this->t('Created triplestore stack: %message', ['%message' => $triplestoreComponentCreateResult['message']]),
        'data' => [
          'triplestoreComponentCreateResult' => $triplestoreComponentCreateResult,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Triplestore component creation exists with error: @error trace: @trace", [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not create triplestore component. See logs for more details."));
      return [
        'message' => $this->t('Could not create stack: %message', ['%message' => $e->getMessage()]),
        'data' => [
          'triplestoreComponentCreateResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

  /**
   * Read all triplestore stacks.
   *
   * @param string $bundle
   *   The bundle.
   * @param array $options
   *   The options.
   *
   * @return array
   *   The result array.
   */
  public function getStacks($bundle, $options): array {
    return [];
  }

  /**
   * Read a triplestore stack.
   *
   * @param Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function getStack($component): array {
    return [];
  }

  /**
   * Update a triplestore stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return array
   *   The result.
   */
  public function updateStack($component): array {
    return [];
  }

  /**
   * Delete a triplestore stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   *
   * @return array
   *   The result.
   */
  public function deleteStack(SodaScsComponentInterface $component): array {
    try {
      // Create the SQL component.
      $triplestoreComponentDeleteResult = $this->sodaScsTriplestoreComponentActions->deleteComponent($component);

      if (!$triplestoreComponentDeleteResult['success']) {
        return [
          'message' => 'Could not delete triplestore component.',
          'data' => [
            'triplestoreComponentDeleteResult' => $triplestoreComponentDeleteResult,
          ],
          'success' => FALSE,
          'error' => $triplestoreComponentDeleteResult['error'],
        ];
      }
      return [
        'message' => 'Deleted triplestore component.',
        'data' => [
          'triplestoreComponentDeleteResult' => $triplestoreComponentDeleteResult,
        ],
        'success' => TRUE,
        'error' => NULL,
      ];

    }
    catch (\Exception $e) {
      $this->loggerFactory->get('soda_scs_manager')->error("Triplestore component deletion exists with error: @error trace: @trace", [
        '@error' => $e->getMessage(),
        '@trace' => $e->getTraceAsString(),
      ]);
      $this->messenger->addError($this->stringTranslation->translate("Could not delete triplestore component. See logs for more details."));
      return [
        'message' => 'Could not delete triplestore component.',
        'data' => [
          'triplestoreComponentDeleteResult' => NULL,
        ],
        'success' => FALSE,
        'error' => $e,
      ];
    }
  }

}
