<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Site\Settings;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Encrypts and stores Nextcloud app passwords in Drupal user data.
 *
 * Login name stays in Keycloak; only the secret is stored here.
 */
class NextcloudMountCredentialsStore {

  public const USER_DATA_MODULE = 'soda_scs_manager';

  public const KEY_APP_PASSWORD = 'nextcloud_app_password_encrypted';

  public const KEY_MOUNT_STATUS = 'nextcloud_mount_status';

  /**
   * When Keycloak last reported the user's account as missing (timestamp).
   */
  public const KEY_KEYCLOAK_USER_MISSING_SINCE = 'nextcloud_keycloak_user_missing_since';

  /**
   * Seconds the mount reconcile skips a user marked as missing in Keycloak.
   *
   * Then it asks Keycloak again, in case the account was restored.
   */
  public const KEYCLOAK_USER_MISSING_RECHECK_SECONDS = 86400;

  /**
   * Mount status of a user whose Keycloak account no longer exists.
   *
   * The mount reconcile skips such users for a day, or until a login or a
   * Nextcloud connect sets another status.
   */
  public const STATUS_KEYCLOAK_USER_MISSING = 'error:keycloak user missing';

  /**
   * Constructs the store.
   */
  public function __construct(
    #[Autowire(service: 'datetime.time')]
    protected TimeInterface $time,
    #[Autowire(service: 'user.data')]
    protected UserDataInterface $userData,
  ) {}

  /**
   * Stores an encrypted app password for a Drupal user.
   */
  public function setAppPassword(UserInterface $user, string $appPassword): void {
    $this->userData->set(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_APP_PASSWORD,
      $this->encrypt($appPassword),
    );
  }

  /**
   * Returns the decrypted app password, or NULL.
   */
  public function getAppPassword(UserInterface $user): ?string {
    $encrypted = $this->userData->get(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_APP_PASSWORD,
    );
    if (!is_string($encrypted) || $encrypted === '') {
      return NULL;
    }
    try {
      $plain = $this->decrypt($encrypted);
      return $plain !== '' ? $plain : NULL;
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * Ids of the users with a stored app password.
   *
   * @return list<int>
   *   The user ids.
   */
  public function userIdsWithAppPassword(): array {
    $stored = $this->userData->get(self::USER_DATA_MODULE, NULL, self::KEY_APP_PASSWORD);
    return is_array($stored) ? array_map('intval', array_keys($stored)) : [];
  }

  /**
   * Deletes the stored app password.
   */
  public function clearAppPassword(UserInterface $user): void {
    $this->userData->delete(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_APP_PASSWORD,
    );
  }

  /**
   * Removes the Keycloak-missing mark, e.g. when the user logs in again.
   */
  public function clearKeycloakUserMissing(UserInterface $user): void {
    if ($this->getMountStatus($user) === self::STATUS_KEYCLOAK_USER_MISSING) {
      $this->clearMountStatus($user);
    }
    $this->userData->delete(self::USER_DATA_MODULE, (int) $user->id(), self::KEY_KEYCLOAK_USER_MISSING_SINCE);
  }

  /**
   * Whether the user was marked as missing in Keycloak less than a day ago.
   */
  public function isKeycloakUserMarkedMissing(UserInterface $user): bool {
    if ($this->getMountStatus($user) !== self::STATUS_KEYCLOAK_USER_MISSING) {
      return FALSE;
    }
    $since = (int) $this->userData->get(self::USER_DATA_MODULE, (int) $user->id(), self::KEY_KEYCLOAK_USER_MISSING_SINCE);
    return $since + self::KEYCLOAK_USER_MISSING_RECHECK_SECONDS > $this->time->getRequestTime();
  }

  /**
   * Marks the user as missing in Keycloak, for the mount reconcile.
   */
  public function markKeycloakUserMissing(UserInterface $user): void {
    $this->setMountStatus($user, self::STATUS_KEYCLOAK_USER_MISSING);
    $this->userData->set(self::USER_DATA_MODULE, (int) $user->id(), self::KEY_KEYCLOAK_USER_MISSING_SINCE, $this->time->getRequestTime());
  }

  /**
   * Persists mount status for UI (mounted|error:…|disconnected).
   */
  public function setMountStatus(UserInterface $user, string $status): void {
    $this->userData->set(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_MOUNT_STATUS,
      $status,
    );
  }

  /**
   * Returns stored mount status.
   */
  public function getMountStatus(UserInterface $user): ?string {
    $status = $this->userData->get(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_MOUNT_STATUS,
    );
    return is_string($status) ? $status : NULL;
  }

  /**
   * Clears mount status.
   */
  public function clearMountStatus(UserInterface $user): void {
    $this->userData->delete(
      self::USER_DATA_MODULE,
      (int) $user->id(),
      self::KEY_MOUNT_STATUS,
    );
  }

  /**
   * Encrypts a secret with sodium_secretbox keyed from hash_salt.
   */
  protected function encrypt(string $plaintext): string {
    $key = $this->encryptionKey();
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);
    return base64_encode($nonce . $cipher);
  }

  /**
   * Decrypts a value produced by encrypt().
   *
   * @throws \RuntimeException
   */
  protected function decrypt(string $payload): string {
    $raw = base64_decode($payload, TRUE);
    if ($raw === FALSE || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
      throw new \RuntimeException('Invalid encrypted payload.');
    }
    $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->encryptionKey());
    if ($plain === FALSE) {
      throw new \RuntimeException('Could not decrypt Nextcloud app password.');
    }
    return $plain;
  }

  /**
   * Derives a 32-byte key from Drupal hash salt.
   */
  protected function encryptionKey(): string {
    return hash('sha256', Settings::getHashSalt() . '|soda_scs_manager|nextcloud_app_password', TRUE);
  }

}
