<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Password\PasswordGeneratorInterface;
use Drupal\externalauth\AuthmapInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsProtectedAccounts;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceUserActions;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The service account scs_demo that owns the demo source project.
 *
 * It is a normal SSO account (Drupal, Keycloak, Nextcloud) with the password
 * from the environment of the manager container, so admins can sign in as
 * it. It is protected from deletion (SodaScsProtectedAccounts).
 */
class SodaScsDemoServiceAccount {

  /**
   * Environment variable with the password of the account.
   */
  public const PASSWORD_ENV = 'SCS_DEMO_ACCOUNT_PASSWORD';

  /**
   * Environment variable with the optional e-mail address of the account.
   */
  public const EMAIL_ENV = 'SCS_DEMO_ACCOUNT_EMAIL';

  /**
   * Constructs the service.
   */
  public function __construct(
    #[Autowire(service: 'externalauth.authmap')]
    protected AuthmapInterface $authmap,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.helpers')]
    protected SodaScsKeycloakHelpers $keycloakHelpers,
    #[Autowire(service: 'soda_scs_manager.keycloak_service.user.actions')]
    protected SodaScsKeycloakServiceUserActions $keycloakUserActions,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerChannelInterface $logger,
    #[Autowire(service: 'soda_scs_manager.nextcloud.helpers')]
    protected SodaScsNextcloudHelpers $nextcloudHelpers,
    #[Autowire(service: 'password_generator')]
    protected PasswordGeneratorInterface $passwordGenerator,
    #[Autowire(service: 'soda_scs_manager.project.helpers')]
    protected SodaScsProjectHelpers $projectHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * The Drupal account, if it exists.
   */
  public function account(): ?UserInterface {
    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => SodaScsProtectedAccounts::DEMO_ACCOUNT_NAME]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Creates the Drupal account when it is missing, without external calls.
   *
   * Used by the update hook; ensure() completes the account later.
   *
   * @return \Drupal\user\UserInterface
   *   The account.
   */
  public function ensureDrupalAccount(): UserInterface {
    $account = $this->account();
    if ($account instanceof UserInterface) {
      return $account;
    }
    $password = (string) getenv(self::PASSWORD_ENV);
    /** @var \Drupal\user\UserInterface $account */
    $account = $this->entityTypeManager->getStorage('user')->create([
      'mail' => (string) getenv(self::EMAIL_ENV),
      'name' => SodaScsProtectedAccounts::DEMO_ACCOUNT_NAME,
      'pass' => $password !== '' ? $password : $this->passwordGenerator->generate(32),
      'status' => 1,
    ]);
    $account->save();
    return $account;
  }

  /**
   * Makes sure the account exists in Drupal, Keycloak and Nextcloud.
   *
   * Idempotent; the password from the environment is set on every run.
   * Without it, an account that was completed before is accepted as is.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   Success data['account'] holds the Drupal account.
   */
  public function ensure(): SodaScsResult {
    $password = (string) getenv(self::PASSWORD_ENV);
    if ($password === '') {
      // Without the password the account cannot be created, but one that
      // was set up before can still own projects (e.g. in the job runner,
      // which may start without the variable).
      $account = $this->account();
      if ($account instanceof UserInterface && $this->projectHelpers->getUserSsoUuid($account) && $this->nextcloudHelpers->getMountCredentials($account) !== NULL) {
        return SodaScsResult::success(message: 'The account scs_demo is ready.', data: ['account' => $account]);
      }
      return SodaScsResult::failure(
        error: sprintf('%s is not set in the environment of the manager container.', self::PASSWORD_ENV),
        message: sprintf('Set %s in the deployment .env and recreate the manager container.', self::PASSWORD_ENV),
      );
    }

    $account = $this->ensureDrupalAccount();
    $account->setPassword($password);
    $email = (string) getenv(self::EMAIL_ENV);
    $account->setEmail($email !== '' ? $email : NULL);
    $account->save();

    $keycloakUserId = $this->ensureKeycloakAccount($account, $email, $password);
    if ($keycloakUserId === NULL) {
      return SodaScsResult::failure(
        error: 'Could not create or update the Keycloak account of scs_demo.',
        message: 'Could not create the Keycloak account of scs_demo. See the log for details.',
      );
    }
    $provider = $this->openIdConnectProvider();
    if ($provider === NULL) {
      return SodaScsResult::failure(
        error: 'No OpenID Connect client is configured in the SCS Manager settings.',
        message: 'Could not link scs_demo to Keycloak.',
      );
    }
    if ($this->projectHelpers->getUserSsoUuid($account) !== $keycloakUserId) {
      $this->authmap->save($account, $provider, $keycloakUserId);
    }

    if (!$this->nextcloudHelpers->ensureAccountViaOcc($account, $password)) {
      return SodaScsResult::failure(
        error: 'Could not create the Nextcloud account of scs_demo or its app password.',
        message: 'Could not create the Nextcloud account of scs_demo.',
      );
    }

    return SodaScsResult::success(
      message: 'The account scs_demo is ready.',
      data: ['account' => $account],
    );
  }

  /**
   * Creates the Keycloak user or resets its password and e-mail address.
   *
   * An existing Keycloak user is only taken when the Drupal account is
   * already linked to it: somebody could have registered the name before it
   * was reserved. Resetting the e-mail address to the configured one (or
   * none) keeps a former address from resetting the password.
   *
   * @return string|null
   *   The Keycloak user id, or NULL on failure.
   */
  protected function ensureKeycloakAccount(UserInterface $account, string $email, string $password): ?string {
    $token = $this->keycloakHelpers->getKeycloakToken();
    if (!$token) {
      return NULL;
    }
    $existing = $this->findKeycloakUserId($token, $account->getAccountName());
    if ($existing !== NULL) {
      if ($this->projectHelpers->getUserSsoUuid($account) !== $existing) {
        $this->logger->error('A Keycloak user scs_demo exists that the Drupal account @uid is not linked to; it is not taken over. Delete or rename it in Keycloak and run the command again.', ['@uid' => $account->id()]);
        return NULL;
      }
      if (!$this->keycloakHelpers->updateKeycloakUserProfile($existing, ['email' => $email])) {
        $this->logger->error('Could not set the e-mail address of the Keycloak account of scs_demo.');
        return NULL;
      }
      return $this->keycloakHelpers->resetKeycloakUserPassword($existing, $password) ? $existing : NULL;
    }

    $body = [
      'credentials' => [
        [
          'temporary' => FALSE,
          'type' => 'password',
          'value' => $password,
        ],
      ],
      'enabled' => TRUE,
      'firstName' => 'SCS',
      'lastName' => 'Demo',
      'username' => $account->getAccountName(),
    ];
    if ($email !== '') {
      $body['email'] = $email;
      $body['emailVerified'] = TRUE;
    }
    $settings = $this->serviceHelpers->initKeycloakGeneralSettings();
    $response = $this->keycloakUserActions->makeRequest($this->keycloakUserActions->buildCreateRequest([
      'body' => $body,
      'routeParams' => ['realm' => $settings['realm'] ?? ''],
      'token' => $token,
    ]));
    if (empty($response['success'])) {
      $this->logger->error('Could not create the Keycloak account of scs_demo: @error', ['@error' => (string) ($response['error'] ?? '')]);
      return NULL;
    }
    return $this->findKeycloakUserId($token, $account->getAccountName());
  }

  /**
   * Looks up a Keycloak user id by exact username.
   */
  protected function findKeycloakUserId(string $token, string $username): ?string {
    $response = $this->keycloakUserActions->makeRequest($this->keycloakUserActions->buildGetAllRequest([
      'queryParams' => ['exact' => 'true', 'username' => $username],
      'token' => $token,
      'type' => 'user',
    ]));
    if (empty($response['success'])) {
      return NULL;
    }
    $users = json_decode((string) $response['data']['keycloakResponse']->getBody()->getContents(), TRUE);
    foreach (is_array($users) ? $users : [] as $user) {
      if (($user['username'] ?? NULL) === $username && is_string($user['id'] ?? NULL)) {
        return $user['id'];
      }
    }
    return NULL;
  }

  /**
   * The externalauth provider id of the Keycloak OpenID Connect client.
   */
  protected function openIdConnectProvider(): ?string {
    $clientId = (string) ($this->serviceHelpers->initKeycloakGeneralSettings()['openIdConnectClientMachineName'] ?? '');
    return $clientId === '' ? NULL : 'openid_connect.' . $clientId;
  }

}
