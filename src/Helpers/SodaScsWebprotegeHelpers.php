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
    $host = rtrim($publicHost, '/');
    if ($host !== '' && !preg_match('#^https?://#i', $host)) {
      $host = 'https://' . $host;
    }
    $base = $host . '/WebProtege.jsp';
    if (is_string($projectUuid) && preg_match(self::UUID_PATTERN, $projectUuid)) {
      return $base . '#projects/' . strtolower($projectUuid) . '/perspectives/' . self::CLASSES_PERSPECTIVE_ID;
    }
    return $base . '#projects/list';
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
