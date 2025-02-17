<?php

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Config\ConfigFactoryInterface;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Helper functions for SCS components.
 */
class SodaScsComponentHelpers {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;


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
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationInterface
   */
  protected TranslationInterface $stringTranslation;

  /**
   * The settings.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $settings;

  public function __construct(
    ConfigFactoryInterface $configFactory,
    ClientInterface $httpClient,
    LoggerChannelFactoryInterface $loggerFactory,
    MessengerInterface $messenger,
    TranslationInterface $stringTranslation,
  ) {
    // Services from container.
    $this->settings = $configFactory
      ->getEditable('soda_scs_manager.settings');
    $this->httpClient = $httpClient;
    $this->loggerFactory = $loggerFactory;
    $this->messenger = $messenger;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Drupal instance health check.
   */
  public function healthCheck(string $component, string $subdomain) {
    try {
      $route = 'https://' . $subdomain . '.' . $this->settings->get('wisski')['instances']['cloudDomain'] . $this->settings->get('wisski')['instances']['healthCheck']['url'];
      $response = $this->httpClient->request('get', $route);
      if ($response->getStatusCode() == 200) {
        // Request successful, handle the data in $response->data.
        return [
          "message" => "Component health check is available.",
          'success' => TRUE,
        ];
      }
      else {
        // Request failed, handle the error.
        return [
          "message" => 'Component health check is not available: ' . $response->getStatusCode(),
          'success' => FALSE,
        ];
      }
    }
    catch (\Exception $e) {
      // Health status says everything no need for error handling.
    }
  }

}
