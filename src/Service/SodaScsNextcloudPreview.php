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
 * Shows recent file activity (uploads, edits, deletes, public uploads) via
 * the Activity OCS API using the current user's stored credentials.
 */
class SodaScsNextcloudPreview {

  use StringTranslationTrait;

  /**
   * Max items shown in the preview.
   */
  private const ITEM_LIMIT = 8;

  /**
   * Fetch more rows than ITEM_LIMIT so non-file events can be skipped.
   */
  private const FETCH_LIMIT = 50;

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
      'limit' => self::FETCH_LIMIT,
      'timeout' => self::REQUEST_TIMEOUT,
    ];

    return [
      'status' => 'connected',
      'openUrl' => $baseUrl,
      'activities' => $this->fetchActivities($authParams, $baseUrl),
    ];
  }

  /**
   * Fetches and normalises recent file activity.
   *
   * Uses the unfiltered activity feed and keeps file-related rows. Nextcloud's
   * `/activity/files` filter omits types such as public_links_upload and
   * returns HTTP 304 with an empty body when nothing matches.
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

    $statusCode = (int) ($response['statusCode'] ?? 0);
    // Nextcloud returns 304/204 with an empty body when there is nothing to show.
    if ($statusCode === 304 || $statusCode === 204) {
      return ['status' => 'empty', 'items' => []];
    }

    try {
      $body = (string) $response['data']['nextcloudResponse']->getBody();
      if ($body === '') {
        return ['status' => 'empty', 'items' => []];
      }
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
      if (!is_array($row) || !$this->isFileActivity($row)) {
        continue;
      }

      $fileId = $row['object_id'] ?? NULL;
      $link = (string) ($row['link'] ?? '');
      if ($link === '' && $baseUrl !== '' && $fileId) {
        $link = $baseUrl . '/f/' . rawurlencode((string) $fileId);
      }

      $objectName = trim((string) ($row['object_name'] ?? ''));
      if ($objectName !== '') {
        $objectName = basename(str_replace('\\', '/', $objectName));
      }
      $subject = trim(html_entity_decode(strip_tags((string) ($row['subject'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
      $label = $objectName !== '' ? $objectName : $subject;
      if ($label === '') {
        continue;
      }

      $subtitle = (string) ($row['datetime'] ?? '');
      if ($objectName !== '' && $subject !== '' && $subject !== $objectName) {
        $subtitle = $subtitle !== '' ? $subject . ' · ' . $subtitle : $subject;
      }

      $items[] = [
        'label' => $label,
        'subtitle' => $subtitle,
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
   * Whether an activity row is file-related.
   *
   * @param array $row
   *   Raw OCS activity row.
   *
   * @return bool
   *   TRUE for files app / files object events.
   */
  protected function isFileActivity(array $row): bool {
    return ($row['app'] ?? '') === 'files'
      || ($row['object_type'] ?? '') === 'files';
  }

}
