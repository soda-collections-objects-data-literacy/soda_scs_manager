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
 * Uses scs_manager_integration OCS API for the platform SCS-Share Team Folder feed.
 */
class SodaScsNextcloudPreview {

  use StringTranslationTrait;

  /**
   * Max items shown in the preview.
   */
  private const ITEM_LIMIT = 8;

  /**
   * externalProjectId of the platform Team Folder feed.
   */
  private const PLATFORM_EXTERNAL_PROJECT_ID = 'scs-platform-share';

  /**
   * Fallback folder name when externalProjectId is not matched.
   */
  private const PLATFORM_FOLDER_NAME = 'SCS-Share';

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
   *   Keys: status (connected|needs_connect), openUrl, activities.
   *   activities has status and items.
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
        'activities' => $emptySection(),
      ];
    }

    $authParams = [
      'username' => $credentials['username'],
      'password' => $credentials['appPassword'],
      'timeout' => self::REQUEST_TIMEOUT,
    ];

    return [
      'status' => 'connected',
      'openUrl' => $baseUrl,
      'activities' => $this->fetchPlatformShareFeed($authParams, $baseUrl),
    ];
  }

  /**
   * Decodes an OCS JSON response body into the data payload.
   *
   * @param array $response
   *   Normalised makeRequest() result.
   *
   * @return array|null
   *   ocs.data array, or NULL on failure.
   */
  protected function decodeOcsData(array $response): ?array {
    if (!$response['success']) {
      return NULL;
    }
    $statusCode = (int) ($response['statusCode'] ?? 0);
    if ($statusCode === 304 || $statusCode === 204) {
      return [];
    }
    try {
      $body = (string) $response['data']['nextcloudResponse']->getBody();
      if ($body === '') {
        return [];
      }
      $decoded = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
    }
    catch (\Throwable $e) {
      return NULL;
    }
    $data = $decoded['ocs']['data'] ?? NULL;
    return is_array($data) ? $data : NULL;
  }

  /**
   * Loads the platform SCS-Share feed via scs_manager_integration.
   *
   * @param array $authParams
   *   User auth + timeout params.
   * @param string $baseUrl
   *   Nextcloud base URL.
   *
   * @return array
   *   Section with status and items.
   */
  protected function fetchPlatformShareFeed(array $authParams, string $baseUrl): array {
    $folderId = $this->resolvePlatformShareFolderId($authParams);
    if ($folderId === NULL) {
      // List request failed.
      return ['status' => 'error', 'items' => []];
    }
    if ($folderId === 0) {
      // No accessible platform share feed for this user.
      return ['status' => 'empty', 'items' => []];
    }

    $request = $this->nextcloudServiceActions->buildProjectFeedRequest($authParams + [
      'folderId' => $folderId,
      'limit' => self::ITEM_LIMIT,
    ]);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    $data = $this->decodeOcsData($response);
    if ($data === NULL) {
      return ['status' => 'error', 'items' => []];
    }

    $rows = $data['items'] ?? [];
    if (!is_array($rows)) {
      return ['status' => 'error', 'items' => []];
    }

    $items = [];
    foreach ($rows as $row) {
      if (!is_array($row)) {
        continue;
      }
      $object = is_array($row['object'] ?? NULL) ? $row['object'] : [];
      $label = trim((string) ($object['name'] ?? ''));
      $subject = trim((string) ($row['subject'] ?? ''));
      if ($label === '') {
        $label = $subject;
      }
      if ($label === '') {
        continue;
      }

      $subtitle = $subject;
      $timestamp = (int) ($row['timestamp'] ?? 0);
      if ($timestamp > 0) {
        $datetime = gmdate('c', $timestamp);
        $subtitle = $subtitle !== '' ? $subtitle . ' · ' . $datetime : $datetime;
      }

      $fileId = (int) ($object['fileId'] ?? 0);
      $url = '';
      if ($baseUrl !== '' && $fileId > 0) {
        $url = $baseUrl . '/f/' . rawurlencode((string) $fileId);
      }

      $items[] = [
        'label' => $label,
        'subtitle' => $subtitle,
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
   * Resolves the platform share feed folder id for the current user.
   *
   * @param array $authParams
   *   User auth + timeout params.
   *
   * @return int|null
   *   Folder id, 0 if not found/accessible, NULL if the list request failed.
   */
  protected function resolvePlatformShareFolderId(array $authParams): ?int {
    $request = $this->nextcloudServiceActions->buildProjectFoldersRequest($authParams);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    $data = $this->decodeOcsData($response);
    if ($data === NULL) {
      return NULL;
    }

    $folders = $data['folders'] ?? [];
    if (!is_array($folders)) {
      return NULL;
    }

    $fallbackId = 0;
    foreach ($folders as $folder) {
      if (!is_array($folder)) {
        continue;
      }
      $id = (int) ($folder['id'] ?? 0);
      if ($id <= 0) {
        continue;
      }
      $externalId = (string) ($folder['externalProjectId'] ?? '');
      if ($externalId === self::PLATFORM_EXTERNAL_PROJECT_ID) {
        return $id;
      }
      $name = (string) ($folder['name'] ?? '');
      if ($fallbackId === 0 && $name === self::PLATFORM_FOLDER_NAME) {
        $fallbackId = $id;
      }
    }

    return $fallbackId;
  }

}
