<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsNextcloudServiceActions;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Deletes and lists the Nextcloud groups that mirror Keycloak groups.
 *
 * Nextcloud gets a group per project (named by the project's gid, created
 * with the Team Folder) and per application access group (copied by
 * user_oidc at the Drive login). Neither removes the group again, so the
 * Manager deletes it together with the Keycloak group.
 *
 * Depends on the Nextcloud request actions only, so the project and Keycloak
 * helpers can use it. Not final, so tests of those helpers can mock it.
 */
class SodaScsNextcloudGroupHelpers {

  /**
   * OCS status: success.
   */
  private const OCS_STATUS_OK = 100;

  /**
   * OCS status of DELETE /cloud/groups: the group does not exist.
   */
  private const OCS_STATUS_GROUP_UNKNOWN = 101;

  /**
   * The logger channel.
   */
  private LoggerChannelInterface $logger;

  /**
   * Constructs the Nextcloud group helpers.
   */
  public function __construct(
    LoggerChannelFactoryInterface $loggerFactory,
    #[Autowire(service: 'soda_scs_manager.nextcloud_service.actions')]
    private readonly SodaScsNextcloudServiceActions $nextcloudServiceActions,
  ) {
    $this->logger = $loggerFactory->get('soda_scs_manager');
  }

  /**
   * Deletes a Nextcloud group by its exact id.
   *
   * @param string $groupId
   *   The Nextcloud group id.
   *
   * @return bool
   *   TRUE when the group is gone afterwards (also when it never existed).
   */
  public function deleteGroup(string $groupId): bool {
    if ($groupId === '' || $groupId === 'admin') {
      return FALSE;
    }

    $request = $this->nextcloudServiceActions->buildDeleteGroupRequest(['groupId' => $groupId]);
    if (empty($request['success'])) {
      $this->logger->warning('Nextcloud group @group not deleted: @error', [
        '@group' => $groupId,
        '@error' => (string) ($request['error'] ?? 'request could not be built'),
      ]);
      return FALSE;
    }

    $statusCode = $this->ocsStatusCode($this->nextcloudServiceActions->makeRequest($request));
    if ($statusCode === self::OCS_STATUS_OK || $statusCode === self::OCS_STATUS_GROUP_UNKNOWN) {
      return TRUE;
    }

    $this->logger->warning('Could not delete Nextcloud group @group (OCS status @status).', [
      '@group' => $groupId,
      '@status' => $statusCode ?? 'none',
    ]);
    return FALSE;
  }

  /**
   * Lists all Nextcloud groups with their member count.
   *
   * @return array<string, int>|null
   *   Member count keyed by group id, or NULL when the list failed.
   */
  public function listGroupMemberCounts(): ?array {
    $request = $this->nextcloudServiceActions->buildGroupDetailsRequest();
    if (empty($request['success'])) {
      return NULL;
    }
    $response = $this->nextcloudServiceActions->makeRequest($request);
    $decoded = $this->decodeOcs($response);
    if ($decoded === NULL || ($decoded['ocs']['meta']['statuscode'] ?? NULL) !== self::OCS_STATUS_OK) {
      return NULL;
    }

    $counts = [];
    foreach ($decoded['ocs']['data']['groups'] ?? [] as $group) {
      if (isset($group['id'])) {
        $counts[(string) $group['id']] = (int) ($group['usercount'] ?? 0);
      }
    }
    return $counts;
  }

  /**
   * Decodes the JSON body of a successful OCS response.
   *
   * @return array|null
   *   The decoded body, or NULL for a failed request or invalid JSON.
   */
  private function decodeOcs(array $response): ?array {
    if (empty($response['success'])) {
      return NULL;
    }
    $decoded = json_decode((string) $response['data']['nextcloudResponse']->getBody(), TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * The OCS status code of a response, or NULL when there is none.
   */
  private function ocsStatusCode(array $response): ?int {
    $status = $this->decodeOcs($response)['ocs']['meta']['statuscode'] ?? NULL;
    return is_numeric($status) ? (int) $status : NULL;
  }

}
