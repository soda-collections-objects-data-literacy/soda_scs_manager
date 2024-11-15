<?php

namespace Drupal\soda_scs_manager\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Block\BlockPluginInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a 'Service Status' Block.
 *
 * @Block(
 *   id = "soda_scs_manager_service_status_block",
 *   admin_label = @Translation("Component Service Status"),
 *   category = @Translation("Soda SCS Manager"),
 * )
 */
class SodaScsManagerServiceStatusBlock extends BlockBase implements BlockPluginInterface, ContainerFactoryPluginInterface {

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs a new ServiceStatusBlock instance.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin ID for the plugin instance.
   * @param mixed $plugin_definition
   *   The plugin implementation definition.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, ClientInterface $http_client) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('http_client')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build() {
    return [
      '#theme' => 'component_status',
      '#attached' => [
        'library' => [
          'soda_scs_manager/globalStyling',
        ],
      ],
    ];
  }

  /**
   * Checks if the service is reachable.
   *
   * @return bool
   *   TRUE if the service is reachable, FALSE otherwise.
   */
  protected function checkServiceStatus() {
    try {
      $response = $this->httpClient->request('GET', 'http://your-service-url/health-check');
      return $response->getStatusCode() === 200;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

}
