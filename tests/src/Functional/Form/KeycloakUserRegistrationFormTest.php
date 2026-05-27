<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Functional\Form;

use Drupal\Core\Database\Connection;
use Drupal\Tests\BrowserTestBase;

/**
 * Tests the Keycloak user registration form.
 *
 * @group soda_scs_manager
 */
class KeycloakUserRegistrationFormTest extends BrowserTestBase {

  /**
   * Required legal checkboxes and locale fields for a valid registration POST.
   *
   * @return array<string, int|string>
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
    $this->assertSession()->fieldExists('pass[pass1]');
    $this->assertSession()->fieldExists('pass[pass2]');
    $this->assertSession()->buttonExists('Register');
  }

  /**
   * Tests email validation.
   */
  public function testEmailValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid email.
    $this->submitForm(array_merge([
      'email' => 'invalid-email',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

    // Check for validation error.
    $this->assertSession()->pageTextContains('is not valid');
  }

  /**
   * Tests username validation - only alphanumeric and underscores allowed.
   */
  public function testUsernameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid username (special characters).
    $this->submitForm(array_merge([
      'email' => 'test@example.com',
      'username' => 'test-user!@#',
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

    // Check for validation error.
    $this->assertSession()->pageTextContains('alphanumeric characters and underscores');
  }

  /**
   * Tests username minimum length validation.
   */
  public function testUsernameMinimumLengthValidation(): void {
    $this->drupalGet('/user/register');

    $this->submitForm(array_merge([
      'email' => 'test@example.com',
      'username' => 'ab',
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

    $this->assertSession()->pageTextContains('at least 3 characters');
  }

  /**
   * Tests first name validation.
   */
  public function testFirstNameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid first name (numbers).
    $this->submitForm(array_merge([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test123',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

    // Check for validation error.
    $this->assertSession()->pageTextContains('may not contain digits');
  }

  /**
   * Tests last name validation.
   */
  public function testLastNameValidation(): void {
    $this->drupalGet('/user/register');

    // Submit with invalid last name (digits).
    $this->submitForm(array_merge([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User123',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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
    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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
    $this->submitForm(array_merge([
      'email' => $email,
      'username' => 'newuser',
      'first_name' => 'New',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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
    $this->submitForm(array_merge([
      'email' => 'new_' . $this->randomMachineName() . '@example.com',
      'username' => $username,
      'first_name' => 'New',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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

    // Check all required field errors appear.
    $this->assertSession()->pageTextContains('Email address field is required');
    $this->assertSession()->pageTextContains('Username field is required');
    $this->assertSession()->pageTextContains('First name field is required');
    $this->assertSession()->pageTextContains('Last name field is required');
    $this->assertSession()->pageTextContains('Site language field is required');
    $this->assertSession()->pageTextContains('Time zone field is required');
  }

  /**
   * Tests password confirmation validation.
   */
  public function testPasswordConfirmation(): void {
    $this->drupalGet('/user/register');

    // Submit with non-matching passwords.
    $this->submitForm(array_merge([
      'email' => 'test@example.com',
      'username' => 'testuser',
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'differentpassword',
    ], $this->registrationFormExtras()), 'Register');

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
    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => 'José',
      'last_name' => 'García-López',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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
    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => "O'Brien",
      'last_name' => "O'Connor",
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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

    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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

    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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

    $this->submitForm(array_merge([
      'email' => $email,
      'username' => $username,
      'first_name' => 'Test',
      'last_name' => 'User',
      'pass[pass1]' => 'password123',
      'pass[pass2]' => 'password123',
    ], $this->registrationFormExtras()), 'Register');

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
