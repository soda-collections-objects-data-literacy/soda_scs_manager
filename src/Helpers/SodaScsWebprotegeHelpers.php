<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\RequestActions\SodaScsWebprotegeServiceActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Provisions WebProtégé ontology projects for SCS projects.
 */
class SodaScsWebprotegeHelpers {

  use StringTranslationTrait;

  /**
   * Default Classes perspective used in WebProtégé project URLs.
   */
  public const CLASSES_PERSPECTIVE_ID = '69df8fa8-4f84-499e-9341-28eb5085c40b';

  /**
   * WebProtégé OIDC login endpoint (see OidcAuthServlet in the webprotege repo).
   */
  private const OIDC_LOGIN_PATH = '/webprotege/oidc/login';

  /**
   * UUID pattern used by WebProtégé project ids.
   */
  private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

  /**
   * Constructs the helper.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.webprotege_service.actions')]
    protected SodaScsWebprotegeServiceActions $webprotegeServiceActions,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('soda_scs_manager');
  }

  /**
   * Logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Maps a Drupal/Keycloak account name to a WebProtégé UserId.
   *
   * Mirrors OidcSsoUserProvisioner.sanitizeUserName().
   */
  public static function sanitizeUserName(string $raw): string {
    $trimmed = strtolower(trim($raw));
    if ($trimmed === '') {
      return '';
    }

    $sanitized = '';
    $length = strlen($trimmed);
    for ($i = 0; $i < $length; $i++) {
      $char = $trimmed[$i];
      if (
        ($char >= 'a' && $char <= 'z')
        || ($char >= '0' && $char <= '9')
        || $char === '_'
        || $char === '-'
        || $char === '.'
      ) {
        $sanitized .= $char;
      }
      else {
        $sanitized .= '_';
      }
    }

    if (strlen($sanitized) > 200) {
      $sanitized = substr($sanitized, 0, 200);
    }

    return $sanitized;
  }

  /**
   * Builds the public WebProtégé hash URL for a project or the project list.
   */
  public static function buildProjectHashUrl(string $publicHost, ?string $projectUuid): string {
    return self::normalizeHost($publicHost) . '/WebProtege.jsp#' . self::placeToken($projectUuid);
  }

  /**
   * Builds a WebProtégé URL that opens the project after Keycloak sign-in.
   *
   * The hash fragment of a plain project URL is lost during the OIDC
   * redirect, so signed-out users would land on the project list. The OIDC
   * login endpoint keeps the place in {@code return_to} and skips the IdP
   * round trip for users who are already signed in.
   */
  public static function buildProjectLoginUrl(string $publicHost, ?string $projectUuid): string {
    return self::normalizeHost($publicHost) . self::OIDC_LOGIN_PATH . '?' . http_build_query([
      'return_to' => self::placeToken($projectUuid),
    ]);
  }

  /**
   * WebProtégé place token of a project's Classes perspective or the list.
   */
  private static function placeToken(?string $projectUuid): string {
    if (is_string($projectUuid) && preg_match(self::UUID_PATTERN, $projectUuid)) {
      return 'projects/' . strtolower($projectUuid) . '/perspectives/' . self::CLASSES_PERSPECTIVE_ID;
    }
    return 'projects/list';
  }

  /**
   * Trims the host and adds https:// when no scheme is given.
   */
  private static function normalizeHost(string $publicHost): string {
    $host = rtrim($publicHost, '/');
    if ($host !== '' && !preg_match('#^https?://#i', $host)) {
      $host = 'https://' . $host;
    }
    return $host;
  }

  /**
   * Reads a WebProtégé project UUID from a create response body or Location header.
   */
  public static function extractProjectId(?array $payload, ?string $locationHeader = NULL): ?string {
    $candidates = [
      $payload['_id'] ?? NULL,
      $payload['projectId'] ?? NULL,
    ];
    if (is_array($payload['projectId'] ?? NULL)) {
      $candidates[] = $payload['projectId']['id'] ?? NULL;
    }
    foreach ($candidates as $candidate) {
      if (is_string($candidate) && preg_match(self::UUID_PATTERN, $candidate)) {
        return strtolower($candidate);
      }
    }

    if (is_string($locationHeader) && preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})(?:\/)?$/i', $locationHeader, $matches)) {
      return strtolower($matches[1]);
    }

    return NULL;
  }

  /**
   * Creates a WebProtégé ontology project for the SCS project and stores the UUID.
   */
  public function createOntologyProject(SodaScsProjectInterface $project): SodaScsResult {
    $existingId = trim((string) ($project->get('webprotegeProjectId')->value ?? ''));
    if ($existingId !== '') {
      return SodaScsResult::success(
        data: ['webprotegeProjectId' => $existingId],
        message: (string) $this->t('WebProtégé project already linked for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $owner = $project->getOwner();
    if (!$owner instanceof UserInterface) {
      return SodaScsResult::failure(
        'Project owner is missing.',
        (string) $this->t('Could not create a WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $ownerUserId = self::sanitizeUserName($owner->getAccountName());
    if ($ownerUserId === '' || $ownerUserId === 'guest') {
      return SodaScsResult::failure(
        'Could not map the project owner to a WebProtégé user id.',
        (string) $this->t('Could not create a WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $description = trim((string) ($project->get('description')->value ?? ''));
    if ($description === '') {
      $description = 'SCS project ' . $project->id();
    }

    $request = $this->webprotegeServiceActions->buildCreateRequest([
      'body' => [
        'projectOwner' => $ownerUserId,
        'displayName' => (string) $project->label(),
        'langTag' => $this->langTagForProject($project),
        'description' => $description,
      ],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      $error = (string) ($response['error'] ?? 'WebProtégé create failed.');
      $this->logger->error('Failed to create WebProtégé project for SCS project @project: @error', [
        '@project' => $project->label(),
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not create a WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    $payload = $this->decodeResponseBody($response);
    $location = NULL;
    $rawResponse = $response['data']['webprotegeResponse'] ?? NULL;
    if (is_object($rawResponse) && method_exists($rawResponse, 'getHeaderLine')) {
      $location = $rawResponse->getHeaderLine('Location');
    }
    $projectId = self::extractProjectId($payload, $location !== '' ? $location : NULL);
    if ($projectId === NULL) {
      return SodaScsResult::failure(
        'WebProtégé create response did not include a project id.',
        (string) $this->t('Could not create a WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $project->set('webprotegeProjectId', $projectId);
    $project->save();

    $this->syncMemberEditAccess($project);

    return SodaScsResult::success(
      data: ['webprotegeProjectId' => $projectId],
      message: (string) $this->t('Created WebProtégé project for @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Ensures a WebProtégé project exists and members have EDIT.
   *
   * Idempotent: safe to re-run here and later on scs-prime. Creates the
   * ontology project when {@code webprotegeProjectId} is empty, then grants
   * EDIT to current members (the owner is skipped).
   */
  public function syncOntologyProject(SodaScsProjectInterface $project): SodaScsResult {
    $alreadyLinked = $this->linkedProjectId($project) !== NULL;
    $create = $this->createOntologyProject($project);
    if (!$create->success) {
      return $create;
    }

    $members = $this->syncMemberEditAccess($project);
    $failedMembers = [];
    foreach ($members as $row) {
      if (empty($row['success'])) {
        $failedMembers[] = $row;
      }
    }

    $projectId = (string) ($create->data['webprotegeProjectId'] ?? $this->linkedProjectId($project) ?? '');
    $message = $alreadyLinked
      ? (string) $this->t('Synced WebProtégé members for @project.', [
        '@project' => $project->label(),
      ])
      : (string) $create->message;

    if ($failedMembers !== []) {
      return SodaScsResult::failure(
        'Failed to grant WebProtégé EDIT to one or more members.',
        $message,
        FALSE,
      );
    }

    return SodaScsResult::success(
      data: [
        'alreadyLinked' => $alreadyLinked,
        'webprotegeProjectId' => $projectId,
        'members' => $members,
      ],
      message: $message,
    );
  }

  /**
   * Grants EDIT on the linked WebProtégé project to a user.
   */
  public function grantEdit(SodaScsProjectInterface $project, UserInterface $user): SodaScsResult {
    $projectId = $this->linkedProjectId($project);
    if ($projectId === NULL) {
      return SodaScsResult::success(
        data: ['skipped' => TRUE],
        message: (string) $this->t('No WebProtégé project is linked yet.'),
      );
    }

    if ((int) $project->getOwnerId() === (int) $user->id()) {
      return SodaScsResult::success(
        data: ['skipped' => TRUE],
        message: (string) $this->t('Project owner already manages the WebProtégé project.'),
      );
    }

    $userName = self::sanitizeUserName($user->getAccountName());
    if ($userName === '' || $userName === 'guest') {
      return SodaScsResult::failure(
        'Could not map the user to a WebProtégé user id.',
        (string) $this->t('Could not grant WebProtégé access to @user.', [
          '@user' => $user->getDisplayName(),
        ]),
      );
    }

    $request = $this->webprotegeServiceActions->buildUpdateRequest([
      'routeParams' => [
        'projectId' => $projectId,
        'userName' => $userName,
      ],
      'body' => ['permission' => 'EDIT'],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      $error = (string) ($response['error'] ?? 'WebProtégé collaborator update failed.');
      $this->logger->error('Failed to grant WebProtégé EDIT to @user on project @project: @error', [
        '@user' => $user->getAccountName(),
        '@project' => $project->label(),
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not grant WebProtégé access to @user.', [
          '@user' => $user->getDisplayName(),
        ]),
        FALSE,
      );
    }

    return SodaScsResult::success(
      data: ['userName' => $userName],
      message: (string) $this->t('Granted WebProtégé edit access to @user.', [
        '@user' => $user->getDisplayName(),
      ]),
    );
  }

  /**
   * Revokes WebProtégé access for a user. The owner is never revoked.
   */
  public function revokeAccess(SodaScsProjectInterface $project, UserInterface $user): SodaScsResult {
    $projectId = $this->linkedProjectId($project);
    if ($projectId === NULL) {
      return SodaScsResult::success(
        data: ['skipped' => TRUE],
        message: (string) $this->t('No WebProtégé project is linked yet.'),
      );
    }

    if ((int) $project->getOwnerId() === (int) $user->id()) {
      return SodaScsResult::success(
        data: ['skipped' => TRUE],
        message: (string) $this->t('Project owner WebProtégé access is not revoked.'),
      );
    }

    $userName = self::sanitizeUserName($user->getAccountName());
    if ($userName === '' || $userName === 'guest') {
      return SodaScsResult::failure(
        'Could not map the user to a WebProtégé user id.',
        (string) $this->t('Could not revoke WebProtégé access from @user.', [
          '@user' => $user->getDisplayName(),
        ]),
      );
    }

    $request = $this->webprotegeServiceActions->buildDeleteRequest([
      'type' => 'collaborator',
      'routeParams' => [
        'projectId' => $projectId,
        'userName' => $userName,
      ],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      $error = (string) ($response['error'] ?? 'WebProtégé collaborator delete failed.');
      $this->logger->error('Failed to revoke WebProtégé access from @user on project @project: @error', [
        '@user' => $user->getAccountName(),
        '@project' => $project->label(),
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not revoke WebProtégé access from @user.', [
          '@user' => $user->getDisplayName(),
        ]),
        FALSE,
      );
    }

    return SodaScsResult::success(
      data: ['userName' => $userName],
      message: (string) $this->t('Revoked WebProtégé access from @user.', [
        '@user' => $user->getDisplayName(),
      ]),
    );
  }

  /**
   * Moves the linked WebProtégé project to trash.
   */
  public function trashOntologyProject(SodaScsProjectInterface $project): SodaScsResult {
    $projectId = $this->linkedProjectId($project);
    if ($projectId === NULL) {
      return SodaScsResult::success(
        data: ['removed' => FALSE],
        message: (string) $this->t('No WebProtégé project is linked for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    $request = $this->webprotegeServiceActions->buildDeleteRequest([
      'type' => 'project',
      'routeParams' => ['projectId' => $projectId],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      $error = (string) ($response['error'] ?? 'WebProtégé trash failed.');
      $this->logger->error('Failed to trash WebProtégé project @id for SCS project @project: @error', [
        '@id' => $projectId,
        '@project' => $project->label(),
        '@error' => $error,
      ]);
      return SodaScsResult::failure(
        $error,
        (string) $this->t('Could not delete the WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    return SodaScsResult::success(
      data: ['removed' => TRUE, 'webprotegeProjectId' => $projectId],
      message: (string) $this->t('Moved the WebProtégé project for @project to trash.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Loads the details (createdAt, modifiedAt, …) of the linked WebProtégé project.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success, data has webprotegeProjectId and details (decoded JSON).
   */
  public function getProjectDetails(SodaScsProjectInterface $project): SodaScsResult {
    $projectId = $this->linkedProjectId($project);
    if ($projectId === NULL) {
      return SodaScsResult::failure(
        'No WebProtégé project is linked.',
        (string) $this->t('No WebProtégé project is linked for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    $request = $this->webprotegeServiceActions->buildGetRequest([
      'routeParams' => ['projectId' => $projectId],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    $details = empty($response['success']) ? NULL : $this->decodeResponseBody($response);
    if ($details === NULL) {
      return SodaScsResult::failure(
        (string) ($response['error'] ?? '') ?: 'WebProtégé project details could not be read.',
        (string) $this->t('Could not read the WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    return SodaScsResult::success(
      data: [
        'webprotegeProjectId' => $projectId,
        'details' => $details,
      ],
      message: (string) $this->t('Read WebProtégé project details for @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Lists the revisions of the linked WebProtégé project.
   *
   * A project nobody has edited has no revisions.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   On success, data has webprotegeProjectId, count, and latest (the last
   *   revision as returned by WebProtégé, or NULL).
   */
  public function listRevisions(SodaScsProjectInterface $project): SodaScsResult {
    $projectId = $this->linkedProjectId($project);
    if ($projectId === NULL) {
      return SodaScsResult::failure(
        'No WebProtégé project is linked.',
        (string) $this->t('No WebProtégé project is linked for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    $request = $this->webprotegeServiceActions->buildGetRequest([
      'type' => 'revisions',
      'routeParams' => ['projectId' => $projectId],
    ]);
    $response = $this->webprotegeServiceActions->makeRequest($request);
    if (empty($response['success'])) {
      return SodaScsResult::failure(
        (string) ($response['error'] ?? 'WebProtégé revisions request failed.'),
        (string) $this->t('Could not read the WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
        FALSE,
      );
    }

    $revisions = $this->decodeResponseBody($response);
    if ($revisions === NULL || !array_is_list($revisions)) {
      return SodaScsResult::failure(
        'WebProtégé revisions response is not a list.',
        (string) $this->t('Could not read the WebProtégé project for @project.', [
          '@project' => $project->label(),
        ]),
      );
    }

    return SodaScsResult::success(
      data: [
        'webprotegeProjectId' => $projectId,
        'count' => count($revisions),
        'latest' => $revisions === [] ? NULL : end($revisions),
      ],
      message: (string) $this->t('Read WebProtégé revisions for @project.', [
        '@project' => $project->label(),
      ]),
    );
  }

  /**
   * Maps a Drupal user to the WebProtégé UserId string.
   */
  public function toWebprotegeUserId(UserInterface $user): string {
    return self::sanitizeUserName($user->getAccountName());
  }

  /**
   * Whether the WebProtégé API key is set in SCS Manager settings.
   */
  public function isApiConfigured(): bool {
    $request = $this->webprotegeServiceActions->buildCreateRequest(['body' => []]);
    return !empty($request['success']);
  }

  /**
   * Grants EDIT to current project members (not the owner).
   *
   * @return list<array{uid: int|string, accountName: string, success: bool, skipped: bool, error: string|null, message: string}>
   */
  public function syncMemberEditAccess(SodaScsProjectInterface $project): array {
    $rows = [];
    if (!$project->hasField('members') || $project->get('members')->isEmpty()) {
      return $rows;
    }

    foreach ($project->get('members')->referencedEntities() as $member) {
      if (!$member instanceof UserInterface) {
        continue;
      }
      $grant = $this->grantEdit($project, $member);
      $rows[] = [
        'uid' => $member->id(),
        'accountName' => $member->getAccountName(),
        'success' => $grant->success,
        'skipped' => !empty($grant->data['skipped']),
        'error' => $grant->error,
        'message' => $grant->message,
      ];
    }

    return $rows;
  }

  private function linkedProjectId(SodaScsProjectInterface $project): ?string {
    $projectId = trim((string) ($project->get('webprotegeProjectId')->value ?? ''));
    if ($projectId === '' || !preg_match(self::UUID_PATTERN, $projectId)) {
      return NULL;
    }
    return strtolower($projectId);
  }

  private function langTagForProject(SodaScsProjectInterface $project): string {
    $langcode = $project->language()->getId();
    if ($langcode === '' || $langcode === 'und') {
      return 'en';
    }
    return str_replace('_', '-', $langcode);
  }

  /**
   * @param array<string, mixed> $response
   *
   * @return array<string, mixed>|null
   */
  private function decodeResponseBody(array $response): ?array {
    $rawResponse = $response['data']['webprotegeResponse'] ?? NULL;
    if (!is_object($rawResponse) || !method_exists($rawResponse, 'getBody')) {
      return NULL;
    }
    try {
      $contents = (string) $rawResponse->getBody();
      $decoded = json_decode($contents, TRUE);
      return is_array($decoded) ? $decoded : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

}
