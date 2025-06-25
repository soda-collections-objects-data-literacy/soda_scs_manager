<?php

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;

/**
 * Tests the SecureLoggingTrait functionality.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Traits\SecureLoggingTrait
 * @group soda_scs_manager
 */
class SecureLoggingTest extends UnitTestCase {

  /**
   * Test class that uses the SecureLoggingTrait.
   */
  private $testClass;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create an anonymous class that uses the trait.
    $this->testClass = new class {
      use SecureLoggingTrait;

      // Make protected methods public for testing.
      public function testSanitizeForLogging(string $input, array $additionalPatterns = []): string {
        return $this->sanitizeForLogging($input, $additionalPatterns);
      }

      public function testSanitizeCommandForLogging(string $command): string {
        return $this->sanitizeCommandForLogging($command);
      }
    };
  }

  /**
   * Tests sanitization of MySQL password patterns.
   *
   * @covers ::sanitizeForLogging
   */
  public function testSanitizeMysqlPasswords(): void {
    $testCases = [
      'mysql -h localhost -uroot -psecretpassword' => 'mysql -h localhost -uroot -p[REDACTED]',
      'mysql -h localhost -uroot -p secretpassword' => 'mysql -h localhost -uroot -p [REDACTED]',
      'mysql --password=secretpassword' => 'mysql --password=[REDACTED]',
      'mysqldump -p"complex password"' => 'mysqldump -p[REDACTED]',
    ];

    foreach ($testCases as $input => $expected) {
      $this->assertEquals($expected, $this->testClass->testSanitizeForLogging($input));
    }
  }

  /**
   * Tests sanitization of various password patterns.
   *
   * @covers ::sanitizeForLogging
   */
  public function testSanitizePasswordPatterns(): void {
    $testCases = [
      'password=secret123' => 'password=[REDACTED]',
      'pwd="secret123"' => 'pwd="[REDACTED]"',
      "pass='secret123'" => "pass='[REDACTED]'",
      'DB_PASSWORD=supersecret' => 'DB_PASSWORD=[REDACTED]',
      'API_SECRET=abcd1234' => 'API_SECRET=[REDACTED]',
      'AUTH_KEY=xyz789' => 'AUTH_KEY=[REDACTED]',
    ];

    foreach ($testCases as $input => $expected) {
      $this->assertEquals($expected, $this->testClass->testSanitizeForLogging($input));
    }
  }

  /**
   * Tests sanitization of connection strings.
   *
   * @covers ::sanitizeForLogging
   */
  public function testSanitizeConnectionStrings(): void {
    $testCases = [
      'mysql://user:password@localhost:3306/db' => 'mysql://user:[REDACTED]@localhost:3306/db',
      'postgresql://admin:secret@db.example.com/mydb' => 'postgresql://admin:[REDACTED]@db.example.com/mydb',
      'https://user:token@api.example.com/endpoint' => 'https://user:[REDACTED]@api.example.com/endpoint',
    ];

    foreach ($testCases as $input => $expected) {
      $this->assertEquals($expected, $this->testClass->testSanitizeForLogging($input));
    }
  }

  /**
   * Tests sanitization of JSON with sensitive fields.
   *
   * @covers ::sanitizeForLogging
   */
  public function testSanitizeJsonFields(): void {
    $testCases = [
      '{"password": "secret123"}' => '{"password": "[REDACTED]"}',
      '{"secret": "api_key_value"}' => '{"secret": "[REDACTED]"}',
      '{"key": "sensitive_data"}' => '{"key": "[REDACTED]"}',
      '{"username": "admin", "password": "secret"}' => '{"username": "admin", "password": "[REDACTED]"}',
    ];

    foreach ($testCases as $input => $expected) {
      $this->assertEquals($expected, $this->testClass->testSanitizeForLogging($input));
    }
  }

  /**
   * Tests that non-sensitive data is preserved.
   *
   * @covers ::sanitizeForLogging
   */
  public function testPreserveNonSensitiveData(): void {
    $testCases = [
      'This is a regular log message',
      'Database connection successful',
      'User admin logged in',
      'File uploaded: document.pdf',
      'Query executed in 0.5 seconds',
    ];

    foreach ($testCases as $input) {
      $this->assertEquals($input, $this->testClass->testSanitizeForLogging($input));
    }
  }

  /**
   * Tests the command-specific sanitization method.
   *
   * @covers ::sanitizeCommandForLogging
   */
  public function testSanitizeCommandForLogging(): void {
    $testCases = [
      'mysql -h localhost -uroot -pmysecret' => 'mysql -h localhost -uroot -p[REDACTED]',
      'PGPASSWORD=secret psql -h db' => 'PGPASSWORD=[REDACTED] psql -h db',
      'docker run -e DB_PASSWORD=secret mysql' => 'docker run -e DB_PASSWORD=[REDACTED] mysql',
    ];

    foreach ($testCases as $input => $expected) {
      $this->assertEquals($expected, $this->testClass->testSanitizeCommandForLogging($input));
    }
  }

  /**
   * Tests sanitization with additional patterns.
   *
   * @covers ::sanitizeForLogging
   */
  public function testAdditionalPatterns(): void {
    $additionalPatterns = [
      '/(custom_secret=)([^\s]+)/' => '$1[CUSTOM_REDACTED]',
    ];

    $input = 'custom_secret=mysecret password=another';
    $expected = 'custom_secret=[CUSTOM_REDACTED] password=[REDACTED]';

    $this->assertEquals(
      $expected,
      $this->testClass->testSanitizeForLogging($input, $additionalPatterns)
    );
  }

}
