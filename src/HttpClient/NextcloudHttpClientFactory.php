<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\HttpClient;

use Drupal\Core\Http\ClientFactory;
use GuzzleHttp\ClientInterface;

/**
 * Factory for the Nextcloud HTTP client with SCS Manager User-Agent.
 */
class NextcloudHttpClientFactory {

  /**
   * Constructs the factory.
   *
   * @param \Drupal\Core\Http\ClientFactory $httpClientFactory
   *   The Drupal HTTP client factory.
   */
  public function __construct(
    protected ClientFactory $httpClientFactory,
  ) {}

  /**
   * Creates the Nextcloud HTTP client.
   *
   * @return \GuzzleHttp\ClientInterface
   *   The HTTP client with User-Agent: SCS Manager.
   */
  public function create(): ClientInterface {
    return $this->httpClientFactory->fromOptions([
      'headers' => [
        'User-Agent' => 'SCS Manager',
      ],
    ]);
  }

}
