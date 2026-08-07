<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Loads a lightweight Nextcloud preview for the stack entity page.
 *
 * Aggregates recommendations, recent activity, and favorites via OCS/WebDAV
 * using the current user's stored Nextcloud credentials.
 */
class SodaScsNextcloudPreview {

  use StringTranslationTrait;

  /**
   * Max items per preview section.
   */
  private const ITEM_LIMIT = 8;

  /**
   * HTTP timeout in seconds for preview API calls.
   */
  private const REQUEST_TIMEOUT = 5;

  /**
   * Constructs a SodaScsNextcloudPreview.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'soda_scs_manager.nextcloud_service.actions')]
    protected SodaScsNextcloudServiceActions $nextcloudServiceActions,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
    protected LoggerChannelFactoryInterface $loggerFactory,
    TranslationInterface $stringTranslation,
  ) {
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Builds normalised preview data for a Drupal user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user whose Nextcloud account to preview.
   *
   * @return array
   *   Keys: status (connected|needs_connect), openUrl, recommendations,
   *   activities, favorites. Each section has status and items.
   */
  public function buildPreviewData(UserInterface $user): array {
    $baseUrl = '';
    try {
      $settings = $this->serviceHelpers->initNextcloudSettings();
      $baseUrl = rtrim((string) ($settings['baseUrl'] ?? ''), '/');
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('soda_scs_manager')->warning(
        'Nextcloud preview: settings unavailable: @message',
        ['@message' => $e->getMessage()]
      );
    }

    $emptySection = static fn (): array => [
      'status' => 'empty',
      'items' => [],
    ];

    $credentials = $this->nextcloudHelpers->ensureCredentials($user, 'SCS Manager Preview');
    if ($credentials === NULL) {
      return [
        'status' => 'needs_connect',
        'openUrl' => $baseUrl,
        'recommendations' => $emptySection(),
        'activities' => $emptySection(),
        'favorites' => $emptySection(),
      ];
    }

    $authParams = [
      'username' => $credentials['username'],
      'password' => $credentials['appPassword'],
      'limit' => self::ITEM_LIMIT,
      'timeout' => self::REQUEST_TIMEOUT,
    ];

    return [
      'status' => 'connected',
      'openUrl' => $baseUrl,
      'recommendations' => $this->fetchRecommendations($authParams, $baseUrl),
      'activities' => $this->fetchActivities($authParams, $baseUrl),
      'favorites' => $this->fetchFavorites($authParams, $baseUrl),
    ];
  }

  /**
   * Fetches and normalises the activity feed.
   *
   * @param array $authParams
   *   Auth + limit/timeout params.
   * @param string $baseUrl
   *   Nextcloud base URL.
   *
   * @return array
   *   Section with status and items.
   */
  protected function fetchActivities(array $authParams, string $baseUrl): array {
    $request = $this->nextcloudServiceActions->buildActivityRequest($authParams);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    if (!$response['success']) {
      return ['status' => 'error', 'items' => []];
    }

    try {
      $body = (string) $response['data']['nextcloudResponse']->getBody();
      $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      return ['status' => 'error', 'items' => []];
    }

    $rows = $decoded['ocs']['data'] ?? [];
    if (!is_array($rows)) {
      return ['status' => 'error', 'items' => []];
    }

    $items = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $fileId = $row['object_id'] ?? NULL;
      $link = (string) ($row['link'] ?? '');
      if ($link === '' && $baseUrl !== '' && $fileId) {
        $link = $baseUrl . '/f/' . rawurlencode((string) $fileId);
      }
      $label = (string) ($row['subject'] ?? $row['object_name'] ?? '');
      if ($label === '') {
        continue;
      }
      $items[] = [
        'label' => $label,
        'subtitle' => (string) ($row['datetime'] ?? ''),
        'url' => $link,
      ];
      if (count($items) >= self::ITEM_LIMIT) {
        break;
      }
    }

    return [
      'status' => $items === [] ? 'empty' : 'ok',
      'items' => $items,
    ];
  }

  /**
   * Fetches and normalises favorited files via WebDAV SEARCH.
   *
   * @param array $authParams
   *   Auth + limit/timeout params.
   * @param string $baseUrl
   *   Nextcloud base URL.
   *
   * @return array
   *   Section with status and items.
   */
  protected function fetchFavorites(array $authParams, string $baseUrl): array {
    $request = $this->nextcloudServiceActions->buildFavoritesSearchRequest($authParams);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    if (!$response['success']) {
      return ['status' => 'error', 'items' => []];
    }

    try {
      $xml = (string) $response['data']['nextcloudResponse']->getBody();
      $items = $this->parseFavoritesXml($xml, $baseUrl);
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('soda_scs_manager')->warning(
        'Nextcloud preview: favorites parse failed: @message',
        ['@message' => $e->getMessage()]
      );
      return ['status' => 'error', 'items' => []];
    }

    return [
      'status' => $items === [] ? 'empty' : 'ok',
      'items' => array_slice($items, 0, self::ITEM_LIMIT),
    ];
  }

  /**
   * Fetches and normalises recommended files.
   *
   * @param array $authParams
   *   Auth + limit/timeout params.
   * @param string $baseUrl
   *   Nextcloud base URL.
   *
   * @return array
   *   Section with status and items.
   */
  protected function fetchRecommendations(array $authParams, string $baseUrl): array {
    $request = $this->nextcloudServiceActions->buildRecommendationsRequest($authParams);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    if (!$response['success']) {
      return ['status' => 'error', 'items' => []];
    }

    try {
      $body = (string) $response['data']['nextcloudResponse']->getBody();
      $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      return ['status' => 'error', 'items' => []];
    }

    $data = $decoded['ocs']['data'] ?? [];
    $rows = [];
    if (is_array($data) && isset($data['recommendations']) && is_array($data['recommendations'])) {
      $rows = $data['recommendations'];
    }
    elseif (is_array($data) && array_is_list($data)) {
      $rows = $data;
    }

    $items = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $fileId = $row['id'] ?? $row['fileId'] ?? NULL;
      $name = (string) ($row['name'] ?? '');
      if ($name === '') {
        continue;
      }
      $directory = (string) ($row['directory'] ?? '');
      $url = '';
      if ($baseUrl !== '' && $fileId) {
        $url = $baseUrl . '/f/' . rawurlencode((string) $fileId);
      }
      $items[] = [
        'label' => $name,
        'subtitle' => $directory !== '' ? $directory : (string) ($row['reason'] ?? ''),
        'url' => $url,
      ];
      if (count($items) >= self::ITEM_LIMIT) {
        break;
      }
    }

    return [
      'status' => $items === [] ? 'empty' : 'ok',
      'items' => $items,
    ];
  }

  /**
   * Parses a WebDAV multistatus XML response into preview items.
   *
   * @param string $xml
   *   Raw XML body.
   * @param string $baseUrl
   *   Nextcloud base URL for deep links.
   *
   * @return list<array{label: string, subtitle: string, url: string}>
   *   Normalised items.
   */
  protected function parseFavoritesXml(string $xml, string $baseUrl): array {
    if ($xml === '') {
      return [];
    }

    $previous = libxml_use_internal_errors(TRUE);
    $document = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($document === FALSE) {
      return [];
    }

    $document->registerXPathNamespace('d', 'DAV:');
    $document->registerXPathNamespace('oc', 'http://owncloud.org/ns');

    $responses = $document->xpath('//d:response') ?: [];
    $items = [];
    foreach ($responses as $response) {
      $response->registerXPathNamespace('d', 'DAV:');
      $response->registerXPathNamespace('oc', 'http://owncloud.org/ns');

      $displayNames = $response->xpath('.//d:displayname');
      $fileIds = $response->xpath('.//oc:fileid');
      $hrefs = $response->xpath('./d:href');

      $label = isset($displayNames[0]) ? trim((string) $displayNames[0]) : '';
      if ($label === '' && isset($hrefs[0])) {
        $label = basename(rawurldecode((string) $hrefs[0]));
      }
      if ($label === '' || $label === '.' || $label === '/') {
        continue;
      }

      $fileId = isset($fileIds[0]) ? trim((string) $fileIds[0]) : '';
      $url = '';
      if ($baseUrl !== '' && $fileId !== '') {
        $url = $baseUrl . '/f/' . rawurlencode($fileId);
      }

      $subtitle = '';
      if (isset($hrefs[0])) {
        $path = rawurldecode((string) $hrefs[0]);
        $path = preg_replace('#^.*/remote\.php/dav/files/[^/]+/#', '', $path) ?? $path;
        $subtitle = dirname($path);
        if ($subtitle === '.' || $subtitle === '/') {
          $subtitle = '';
        }
      }

      $items[] = [
        'label' => $label,
        'subtitle' => $subtitle,
        'url' => $url,
      ];
    }

    return $items;
  }

}
