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
 * Loads Nextcloud activity feeds via scs_manager_integration.
 *
 * Stack entity page: platform SCS-Share Team Folder.
 * Project page Nextcloud card: that project's Team Folder.
 */
class SodaScsNextcloudPreview {

  use StringTranslationTrait;

  /**
   * Max items shown in the stack entity preview.
   */
  private const ITEM_LIMIT = 8;

  /**
   * Max items shown on the project-page Nextcloud card.
   */
  private const CARD_ITEM_LIMIT = 6;

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
      'activities' => $this->fetchFeedByExternalProjectId(
        $authParams,
        $baseUrl,
        self::PLATFORM_EXTERNAL_PROJECT_ID,
        self::PLATFORM_FOLDER_NAME,
        self::ITEM_LIMIT,
      ),
    ];
  }

  /**
   * Builds activity data for a project's Team Folder feed.
   *
   * Uses scs_manager_integration GET /folders/{id}/feed, matched by
   * Drupal project id (externalProjectId).
   *
   * @param \Drupal\user\UserInterface $user
   *   The user whose Nextcloud account to query.
   * @param string $externalProjectId
   *   Drupal project entity id stored as Nextcloud externalProjectId.
   * @param string|null $fallbackFolderName
   *   Project label / Team Folder mount name if id lookup misses.
   *
   * @return array
   *   Keys: status (connected|needs_connect), openUrl, activities.
   */
  public function buildProjectFeedData(UserInterface $user, string $externalProjectId, ?string $fallbackFolderName = NULL): array {
    $baseUrl = '';
    try {
      $settings = $this->serviceHelpers->initNextcloudSettings();
      $baseUrl = rtrim((string) ($settings['baseUrl'] ?? ''), '/');
    }
    catch (\Throwable $e) {
      $this->loggerFactory->get('soda_scs_manager')->warning(
        'Nextcloud project feed: settings unavailable: @message',
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
      'activities' => $this->fetchFeedByExternalProjectId(
        $authParams,
        $baseUrl,
        $externalProjectId,
        $fallbackFolderName,
        self::CARD_ITEM_LIMIT,
      ),
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
   * Loads a managed-folder activity feed via scs_manager_integration.
   *
   * @param array $authParams
   *   User auth + timeout params.
   * @param string $baseUrl
   *   Nextcloud base URL.
   * @param string $externalProjectId
   *   Folder externalProjectId (Drupal project id or platform share id).
   * @param string|null $fallbackFolderName
   *   Optional mount-point name if id lookup misses.
   * @param int $limit
   *   Max items to return.
   *
   * @return array
   *   Section with status and items.
   */
  protected function fetchFeedByExternalProjectId(array $authParams, string $baseUrl, string $externalProjectId, ?string $fallbackFolderName = NULL, int $limit = self::ITEM_LIMIT): array {
    $limit = max(1, min(self::ITEM_LIMIT, $limit));
    $folderId = $this->resolveFolderId($authParams, $externalProjectId, $fallbackFolderName);
    if ($folderId === NULL) {
      $this->loggerFactory->get('soda_scs_manager')->warning(
        'Nextcloud project feed: folder list failed for externalProjectId @id.',
        ['@id' => $externalProjectId],
      );
      return ['status' => 'error', 'items' => []];
    }
    if ($folderId === 0) {
      return ['status' => 'empty', 'items' => []];
    }

    $request = $this->nextcloudServiceActions->buildProjectFeedRequest($authParams + [
      'folderId' => $folderId,
      'limit' => $limit,
    ]);
    $response = $this->nextcloudServiceActions->makeRequest($request);
    $data = $this->decodeOcsData($response);
    if ($data === NULL) {
      $this->loggerFactory->get('soda_scs_manager')->warning(
        'Nextcloud project feed: feed request failed for folder @folder (externalProjectId @id): @error',
        [
          '@folder' => (string) $folderId,
          '@id' => $externalProjectId,
          '@error' => (string) ($response['error'] ?? 'invalid OCS payload'),
        ],
      );
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
      $mapped = $this->mapFeedItem($row, $baseUrl);
      if ($mapped === NULL) {
        continue;
      }
      $items[] = $mapped;
      if (count($items) >= $limit) {
        break;
      }
    }

    return [
      'status' => $items === [] ? 'empty' : 'ok',
      'items' => $items,
    ];
  }

  /**
   * Maps one scs_manager_integration feed row to preview item fields.
   *
   * @param array<string, mixed> $row
   *   Raw feed item.
   * @param string $baseUrl
   *   Nextcloud base URL.
   *
   * @return array{label: string, subtitle: string, url: string, action: string}|null
   *   Normalised item, or NULL if the row has no displayable label.
   */
  protected function mapFeedItem(array $row, string $baseUrl): ?array {
    $object = is_array($row['object'] ?? NULL) ? $row['object'] : [];
    $label = trim((string) ($object['name'] ?? ''));
    $subject = trim((string) ($row['subject'] ?? ''));
    if ($label === '') {
      $label = $this->fileNameFromSubject($subject);
    }
    if ($label === '') {
      return NULL;
    }

    $action = $this->resolveFeedAction($row);
    $timestamp = (int) ($row['timestamp'] ?? 0);
    $subtitle = $timestamp > 0 ? gmdate('Y-m-d H:i', $timestamp) : '';

    $fileId = (int) ($object['fileId'] ?? 0);
    $url = '';
    if ($baseUrl !== '' && $fileId > 0) {
      $url = $baseUrl . '/f/' . rawurlencode((string) $fileId);
    }

    return [
      'label' => $label,
      'subtitle' => $subtitle,
      'url' => $url,
      'action' => $action,
    ];
  }

  /**
   * Maps a feed row to a UI action key (created, changed, deleted, …).
   *
   * @param array<string, mixed> $row
   *   Raw feed item.
   */
  protected function resolveFeedAction(array $row): string {
    $haystack = strtolower(implode(' ', [
      (string) ($row['type'] ?? ''),
      (string) ($row['subjectKey'] ?? ''),
      (string) ($row['subject'] ?? ''),
    ]));

    return match (TRUE) {
      str_contains($haystack, 'created') => 'created',
      str_contains($haystack, 'deleted') => 'deleted',
      str_contains($haystack, 'restored') => 'restored',
      str_contains($haystack, 'renamed') => 'renamed',
      str_contains($haystack, 'moved') => 'moved',
      str_contains($haystack, 'changed') => 'changed',
      default => 'changed',
    };
  }

  /**
   * Strips a leading activity verb from a formatted subject when no file name.
   */
  protected function fileNameFromSubject(string $subject): string {
    $stripped = preg_replace('/^(Created|Changed|Deleted|Restored|Renamed|Moved)\s+/i', '', $subject);
    return trim((string) $stripped);
  }

  /**
   * Resolves a managed folder id for the current user.
   *
   * @param array $authParams
   *   User auth + timeout params.
   * @param string $externalProjectId
   *   Folder externalProjectId to match.
   * @param string|null $fallbackFolderName
   *   Optional name/label fallback.
   *
   * @return int|null
   *   Folder id, 0 if not found/accessible, NULL if the list request failed.
   */
  protected function resolveFolderId(array $authParams, string $externalProjectId, ?string $fallbackFolderName = NULL): ?int {
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
      if ($externalId === $externalProjectId) {
        return $id;
      }
      if ($fallbackFolderName === NULL || $fallbackFolderName === '' || $fallbackId !== 0) {
        continue;
      }
      $name = (string) ($folder['name'] ?? '');
      $label = (string) ($folder['label'] ?? '');
      if ($name === $fallbackFolderName || $label === $fallbackFolderName) {
        $fallbackId = $id;
      }
    }

    return $fallbackId;
  }

}
