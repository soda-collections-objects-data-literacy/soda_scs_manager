<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Functional\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Database\Connection;
use Drupal\Core\Site\Settings;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the Keycloak user registration form.
 */
#[Group('soda_scs_manager')]
class KeycloakUserRegistrationFormTest extends BrowserTestBase {

  /**
   * Seconds the signed form build time is set back for a valid submission.
   *
   * The form rejects submissions faster than its minimum fill-out time (5 s);
   * a build time set back this far passes that gate without sleep().
   */
  private const FORM_BUILT_SECONDS_AGO = 10;

  /**
   * Required legal checkboxes and locale fields for a valid registration POST.
   *
   * @return array<string, int|string>
   *   Field values keyed by field name.
   */
  private function registrationFormExtras(): array {
    return [
      'interface_langcode' => 'en',
      'timezone' => 'Europe/Berlin',
      'terms_of_service' => 1,
      'privacy_policy' => 1,
    ];
  }

  /**
   * Submits the loaded registration form as a person who took their time.
   *
   * Merges the required extras and sets the signed build time back by
   * FORM_BUILT_SECONDS_AGO, so the honeypot time gate does not reject it.
   *
   * @param array<string, int|string> $edit
   *   Field values to submit.
   */
  private function submitRegistration(array $edit): void {
    $builtAt = (string) (time() - self::FORM_BUILT_SECONDS_AGO);
    $this->assertSession()->hiddenFieldExists('honeypot_time')
      ->setValue($builtAt . '|' . Crypt::hmacBase64($builtAt, Settings::getHashSalt()));
    $this->submitForm(array_merge($edit, $this->registrationFormExtras()), 'Register');
  }

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'file',
    'options',
    'language',
    'soda_scs_manager',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected Connection $database;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->database = $this->container->get('database');
  }

  /**
   * Tests that the registration form renders correctly.
   */
  public function testRegistrationFormRenders(): void {
    $this->drupalGet('/user/register');

    // Check form elements exist.
    $this->assertSession()->fieldExists('email');
    $this->assertSession()->fieldExists('username');
    $this->assertSession()->fieldExists('first_name');
    $this->assertSession()->fieldExists('last_name');
    $this->assertSession()->fieldExists('interface_langcode');
    $this->assertSession()->fieldExists('timezone');
    $this->assertSession()->fieldExists('password[pass1]');
    $this->assertSession()->fieldExists('password[pass2]');
    $this->assertSession()->buttonExists('Register');
  }

  /**
   * Tests email validation.
   */
  public function testEmailValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid email.
    $this->submitRegistration([
      'email' => 'invalid-email',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for validation error.
    $this->assertSession()->pageTextContains('is not valid');
  }

  /**
   * Tests username validation - only alphanumeric and underscores allowed.
   */
  public function testUsernameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid username (special characters).
    $this->submitRegistration([
      'email' => 'test@example.com',
      'username' => 'test-user!@#',
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for validation error.
    $this->assertSession()->pageTextContains('alphanumeric characters and underscores');
  }

  /**
   * Tests username minimum length validation.
   */
  public function testUsernameMinimumLengthValidation(): void {
    $this->drupalGet('/user/register');

    $this->submitRegistration([
      'email' => 'test@example.com',
      'username' => 'ab',
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    $this->assertSession()->pageTextContains('at least 3 characters');
  }

  /**
   * Tests first name validation.
   */
  public function testFirstNameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid first name (numbers).
    $this->submitRegistration([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test123',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for validation error.
    $this->assertSession()->pageTextContains('may not contain digits');
  }

  /**
   * Tests last name validation.
   */
  public function testLastNameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid last name (digits).
    $this->submitRegistration([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User123',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for validation error.
    $this->assertSession()->pageTextContains('may not contain digits');
  }

  /**
   * Tests successful registration submission.
   */
  public function testSuccessfulRegistration(): void {
    $email = 'newuser_' . $this->randomMachineName() . '@example.com';
    $username = 'testuser_' . $this->randomMachineName();

    $this->drupalGet('/user/register');

    // Submit valid registration.
    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for success message.
    $this->assertSession()->pageTextContains('pending approval');

    // Verify database entry was created.
    $result = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur')
      ->condition('email', $email)
      ->execute()
      ->fetchAssoc();

    $this->assertNotEmpty($result);
    $this->assertEquals($username, $result['username']);
    $this->assertEquals('Test', $result['first_name']);
    $this->assertEquals('User', $result['last_name']);
    $this->assertEquals('en', $result['interface_langcode']);
    $this->assertEquals('Europe/Berlin', $result['timezone']);
    $this->assertEquals('pending', $result['status']);
  }

  /**
   * Tests duplicate email validation.
   */
  public function testDuplicateEmailValidation(): void {
    $email = 'duplicate_' . $this->randomMachineName() . '@example.com';

    // Insert a pending registration with the same email.
    $this->database->insert('keycloak_user_registration')
      ->fields([
        'email' => $email,
        'username' => 'existinguser',
        'first_name' => 'Existing',
        'last_name' => 'User',
        'interface_langcode' => 'en',
        'timezone' => 'Europe/Berlin',
        'password' => 'hashedpassword',
        'status' => 'pending',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    $this->drupalGet('/user/register');

    // Try to register with the same email.
    $this->submitRegistration([
      'email' => $email,
      'username' => 'newuser',
      'first_name' => 'New',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for duplicate email error.
    $this->assertSession()->pageTextContains('already a pending registration');
  }

  /**
   * Tests duplicate username validation.
   */
  public function testDuplicateUsernameValidation(): void {
    $username = 'duplicateuser_' . $this->randomMachineName();

    // Insert a pending registration with the same username.
    $this->database->insert('keycloak_user_registration')
      ->fields([
        'email' => 'existing@example.com',
        'username' => $username,
        'first_name' => 'Existing',
        'last_name' => 'User',
        'interface_langcode' => 'en',
        'timezone' => 'Europe/Berlin',
        'password' => 'hashedpassword',
        'status' => 'pending',
        'created' => time(),
        'updated' => time(),
      ])
      ->execute();

    $this->drupalGet('/user/register');

    // Try to register with the same username.
    $this->submitRegistration([
      'email' => 'new_' . $this->randomMachineName() . '@example.com',
      'username' => $username,
      'first_name' => 'New',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check for duplicate username error.
    $this->assertSession()->pageTextContains('username is already taken');
  }

  /**
   * Tests that required fields are enforced.
   */
  public function testRequiredFields(): void {
    $this->drupalGet('/user/register');

    // Submit empty form.
    $this->submitForm([], 'Register');

    // Check all required field errors appear. Site language and time zone are
    // selects with a default value, so an empty form still submits one.
    $this->assertSession()->pageTextContains('Email address field is required');
    $this->assertSession()->pageTextContains('Username field is required');
    $this->assertSession()->pageTextContains('First name field is required');
    $this->assertSession()->pageTextContains('Last name field is required');
    $this->assertSession()->pageTextContains('Password field is required');
    $this->assertSession()->pageTextNotContains('Site language field is required');
    $this->assertSession()->pageTextNotContains('Time zone field is required');
  }

  /**
   * Tests password confirmation validation.
   */
  public function testPasswordConfirmation(): void {
    $this->drupalGet('/user/register');

    // Submit with non-matching passwords.
    $this->submitRegistration([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'differentpassword',
    ]);

    // Check for password mismatch error.
    $this->assertSession()->pageTextContains('do not match');
  }

  /**
   * Tests valid names with accented characters.
   */
  public function testAccentedCharactersInNames(): void {
    $email = 'accented_' . $this->randomMachineName() . '@example.com';
    $username = 'accenteduser_' . $this->randomMachineName();

    $this->drupalGet('/user/register');

    // Submit with accented characters in names.
    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => 'José',
      'last_name' => 'García-López',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Should succeed - accented characters are allowed.
    $this->assertSession()->pageTextContains('pending approval');

    // Verify database entry.
    $result = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur')
      ->condition('email', $email)
      ->execute()
      ->fetchAssoc();

    $this->assertEquals('José', $result['first_name']);
    $this->assertEquals('García-López', $result['last_name']);
  }

  /**
   * Tests names with apostrophes.
   */
  public function testApostropheInNames(): void {
    $email = 'apostrophe_' . $this->randomMachineName() . '@example.com';
    $username = 'apostropheuser_' . $this->randomMachineName();

    $this->drupalGet('/user/register');

    // Submit with apostrophes in names.
    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => "O'Brien",
      'last_name' => "O'Connor",
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Should succeed - apostrophes are allowed.
    $this->assertSession()->pageTextContains('pending approval');
  }

  /**
   * Tests that registration timestamp is set.
   */
  public function testRegistrationTimestamp(): void {
    $email = 'timestamp_' . $this->randomMachineName() . '@example.com';
    $username = 'timestampuser_' . $this->randomMachineName();
    $beforeTime = time();

    $this->drupalGet('/user/register');

    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    $afterTime = time();

    // Verify timestamps are set.
    $result = $this->database->select('keycloak_user_registration', 'kur')
      ->fields('kur')
      ->condition('email', $email)
      ->execute()
      ->fetchAssoc();

    $this->assertGreaterThanOrEqual($beforeTime, (int) $result['created']);
    $this->assertLessThanOrEqual($afterTime, (int) $result['created']);
    $this->assertGreaterThanOrEqual($beforeTime, (int) $result['updated']);
    $this->assertLessThanOrEqual($afterTime, (int) $result['updated']);
  }

  /**
   * Tests that registration redirects to front page after submission.
   */
  public function testRegistrationRedirect(): void {
    $email = 'redirect_' . $this->randomMachineName() . '@example.com';
    $username = 'redirectuser_' . $this->randomMachineName();

    $this->drupalGet('/user/register');

    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Check we're redirected (not still on registration form).
    $this->assertSession()->addressNotEquals('/user/register');
  }

  /**
   * Tests username with underscore is valid.
   */
  public function testUsernameWithUnderscore(): void {
    $email = 'underscore_' . $this->randomMachineName() . '@example.com';
    $username = 'test_user_' . $this->randomMachineName();

    $this->drupalGet('/user/register');

    $this->submitRegistration([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'password[pass1]' => 'password123',
      'password[pass2]' => 'password123',
    ]);

    // Should succeed.
    $this->assertSession()->pageTextContains('pending approval');
  }

  /**
   * Tests that the form has proper description texts.
   */
  public function testFormDescriptions(): void {
    $this->drupalGet('/user/register');

    // Check that form has description/help text.
    $this->assertSession()->pageTextContains('reviewed by an administrator');
    $this->assertSession()->pageTextContains('valid email address');
  }

}
