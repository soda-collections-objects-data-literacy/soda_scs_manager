<?php

namespace Drupal\Tests\soda_scs_manager\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\soda_scs_manager\Traits\SecureLoggingTrait;
use PHPUnit\Framework\Attributes\DataProvider;

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
   * Tests sanitization of the MySQL -p option.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('mysqlPasswordProvider')]
  public function testSanitizeMysqlPasswords(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides inputs with a password in the MySQL -p option.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function mysqlPasswordProvider(): array {
    return [
      'attached to the option' => [
        'mysql -h localhost -uroot -psecretpassword',
        'mysql -h localhost -uroot -p[REDACTED]',
      ],
      'separated by a space' => [
        'mysql -h localhost -uroot -p secretpassword',
        'mysql -h localhost -uroot -p [REDACTED]',
      ],
      'double-quoted with a space' => [
        'mysqldump -p"complex password"',
        'mysqldump -p[REDACTED]',
      ],
      'double-quoted with a space, more arguments follow' => [
        'mysql -uroot -p"two words" -e "SELECT 1"',
        'mysql -uroot -p[REDACTED] -e "SELECT 1"',
      ],
      'single-quoted with a space, more arguments follow' => [
        "mysql -uroot -p'two words' -e 'SELECT 1'",
        "mysql -uroot -p[REDACTED] -e 'SELECT 1'",
      ],
      'quoted and separated by a space' => [
        'mysql -uroot -p "two words" -e x',
        'mysql -uroot -p [REDACTED] -e x',
      ],
      'at the start of the string' => [
        '-psecret',
        '-p[REDACTED]',
      ],
      'long option --password=' => [
        'mysql --password=secretpassword',
        'mysql --password=[REDACTED]',
      ],
      'escapeshellarg of a password with a quote' => [
        "mysql -uroot -p'ab'\\''cd' -e 'SELECT 1'",
        "mysql -uroot -p[REDACTED] -e 'SELECT 1'",
      ],
      'quoted without the closing quote' => [
        'mysql -uroot -p"abc def',
        'mysql -uroot -p[REDACTED]',
      ],
      'in a JSON array' => [
        '["mysql","-uroot","-psecret"]',
        '["mysql","-uroot","-p[REDACTED]"]',
      ],
      'single-quoted word' => [
        "'-psecret'",
        "'-p[REDACTED]'",
      ],
    ];
  }

  /**
   * Tests that text with -p inside a word or another option stays unchanged.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('unchangedTextProvider')]
  public function testSanitizeKeepsTextWithoutSecret(string $input): void {
    $this->assertSame($input, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides text that must not be changed.
   *
   * @return array<string, array{string}>
   *   One input per case.
   */
  public static function unchangedTextProvider(): array {
    return [
      'regular message' => ['This is a regular log message'],
      'connection message' => ['Database connection successful'],
      'login message' => ['User admin logged in'],
      'upload message' => ['File uploaded: document.pdf'],
      'query message' => ['Query executed in 0.5 seconds'],
      'container name with -p inside' => ['wisski-prod--drupal'],
      'container name in a sentence' => ['Restarting wisski-prod--drupal now'],
      'long option --port=' => ['mysql -h db --port=3306'],
      'long option --port with a space' => ['mysql -h db --port 3306'],
      'project name with -p inside' => ['custom-project-name'],
      'project name after an option' => ['docker stack deploy -c custom-project-name'],
    ];
  }

  /**
   * Tests sanitization of various password patterns.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('passwordPatternProvider')]
  public function testSanitizePasswordPatterns(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides KEY=value inputs.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function passwordPatternProvider(): array {
    return [
      'password unquoted' => ['password=secret123', 'password=[REDACTED]'],
      'pwd double-quoted' => ['pwd="secret123"', 'pwd="[REDACTED]"'],
      'pass single-quoted' => ["pass='secret123'", "pass='[REDACTED]'"],
      'DB_PASSWORD unquoted' => ['DB_PASSWORD=supersecret', 'DB_PASSWORD=[REDACTED]'],
      'API_SECRET unquoted' => ['API_SECRET=abcd1234', 'API_SECRET=[REDACTED]'],
      'AUTH_KEY unquoted' => ['AUTH_KEY=xyz789', 'AUTH_KEY=[REDACTED]'],
      'DB_PASSWORD double-quoted with two words' => [
        'DB_PASSWORD="two words"',
        'DB_PASSWORD="[REDACTED]"',
      ],
      'DB_PASSWORD single-quoted with two words' => [
        "DB_PASSWORD='two words'",
        "DB_PASSWORD='[REDACTED]'",
      ],
      'DB_PASSWORD quoted, more arguments follow' => [
        'DB_PASSWORD="two words" docker run image',
        'DB_PASSWORD="[REDACTED]" docker run image',
      ],
      'API_SECRET double-quoted with two words' => [
        'API_SECRET="a b c"',
        'API_SECRET="[REDACTED]"',
      ],
      'AUTH_KEY single-quoted with two words' => [
        "AUTH_KEY='x y'",
        "AUTH_KEY='[REDACTED]'",
      ],
      'password double-quoted with two words' => [
        'password="two words"',
        'password="[REDACTED]"',
      ],
      'pwd single-quoted with two words' => [
        "pwd='two words'",
        "pwd='[REDACTED]'",
      ],
      'password unquoted ends at a semicolon' => [
        'password=abc;echo done',
        'password=[REDACTED];echo done',
      ],
      'password unquoted ends at an ampersand' => [
        'password=abc&user=admin',
        'password=[REDACTED]&user=admin',
      ],
      'password without the closing quote' => [
        'password="abc (truncated...)',
        'password="[REDACTED]"',
      ],
      'DB_PASSWORD single-quoted without the closing quote' => [
        "DB_PASSWORD='abc",
        "DB_PASSWORD='[REDACTED]'",
      ],
      'DB_PASSWORD quoted over two lines' => [
        "DB_PASSWORD=\"line1\nline2\" next",
        'DB_PASSWORD="[REDACTED]" next',
      ],
    ];
  }

  /**
   * Tests sanitization of the SQL IDENTIFIED BY clause.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('identifiedByProvider')]
  public function testSanitizeIdentifiedBy(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides SQL statements that set a password.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function identifiedByProvider(): array {
    return [
      'single-quoted' => [
        "CREATE USER 'app'@'%' IDENTIFIED BY 'secret'",
        "CREATE USER 'app'@'%' IDENTIFIED BY [REDACTED]",
      ],
      'single-quoted with a space' => [
        "CREATE USER 'app'@'%' IDENTIFIED BY 'two words';",
        "CREATE USER 'app'@'%' IDENTIFIED BY [REDACTED];",
      ],
      'double-quoted with a space' => [
        'ALTER USER "app"@"%" IDENTIFIED BY "two words"; FLUSH PRIVILEGES;',
        'ALTER USER "app"@"%" IDENTIFIED BY [REDACTED]; FLUSH PRIVILEGES;',
      ],
      'unquoted' => [
        'CREATE USER app IDENTIFIED BY secret',
        'CREATE USER app IDENTIFIED BY [REDACTED]',
      ],
      'lower case' => [
        "create user 'app' identified by 'secret'",
        "create user 'app' identified by [REDACTED]",
      ],
      'several spaces' => [
        "CREATE USER 'app' IDENTIFIED   BY   'secret'",
        "CREATE USER 'app' IDENTIFIED   BY   [REDACTED]",
      ],
      'SQL-doubled quote' => [
        "ALTER USER 'app' IDENTIFIED BY 'it''s'; FLUSH PRIVILEGES",
        "ALTER USER 'app' IDENTIFIED BY [REDACTED]; FLUSH PRIVILEGES",
      ],
    ];
  }

  /**
   * Tests sanitization of connection strings.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('connectionStringProvider')]
  public function testSanitizeConnectionStrings(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides connection strings with a password.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function connectionStringProvider(): array {
    return [
      'mysql' => [
        'mysql://user:password@localhost:3306/db',
        'mysql://user:[REDACTED]@localhost:3306/db',
      ],
      'postgresql' => [
        'postgresql://admin:secret@db.example.com/mydb',
        'postgresql://admin:[REDACTED]@db.example.com/mydb',
      ],
      'https' => [
        'https://user:token@api.example.com/endpoint',
        'https://user:[REDACTED]@api.example.com/endpoint',
      ],
    ];
  }

  /**
   * Tests sanitization of JSON with sensitive fields.
   *
   * @covers ::sanitizeForLogging
   */
  #[DataProvider('jsonFieldProvider')]
  public function testSanitizeJsonFields(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeForLogging($input));
  }

  /**
   * Provides JSON with sensitive fields.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function jsonFieldProvider(): array {
    return [
      'password' => ['{"password": "secret123"}', '{"password": "[REDACTED]"}'],
      'secret' => ['{"secret": "api_key_value"}', '{"secret": "[REDACTED]"}'],
      'key' => ['{"key": "sensitive_data"}', '{"key": "[REDACTED]"}'],
      'password next to a plain field' => [
        '{"username": "admin", "password": "secret"}',
        '{"username": "admin", "password": "[REDACTED]"}',
      ],
    ];
  }

  /**
   * Tests the command-specific sanitization method.
   *
   * @covers ::sanitizeCommandForLogging
   */
  #[DataProvider('commandProvider')]
  public function testSanitizeCommandForLogging(string $input, string $expected): void {
    $this->assertSame($expected, $this->testClass->testSanitizeCommandForLogging($input));
  }

  /**
   * Provides commands with secrets.
   *
   * The createServiceUser and updateServiceUserPassword cases have the shape of
   * the commands in SodaScsSqlServiceActions: escapeshellarg() quotes around
   * the host, root password, user and user password.
   *
   * @return array<string, array{string, string}>
   *   Input and expected output per case.
   */
  public static function commandProvider(): array {
    return [
      'mysql -p attached' => [
        'mysql -h localhost -uroot -pmysecret',
        'mysql -h localhost -uroot -p[REDACTED]',
      ],
      'mysql -p quoted with a space' => [
        "mysql -h localhost -uroot -p'my secret' -e 'SELECT 1'",
        "mysql -h localhost -uroot -p[REDACTED] -e 'SELECT 1'",
      ],
      'mysql --password=' => [
        'mysql --password=mysecret -h localhost',
        'mysql --password=[REDACTED] -h localhost',
      ],
      'mysql --password= quoted with a space' => [
        'mysql --password="my secret" -h localhost',
        'mysql --password="[REDACTED]" -h localhost',
      ],
      'PGPASSWORD' => [
        'PGPASSWORD=secret psql -h db',
        'PGPASSWORD=[REDACTED] psql -h db',
      ],
      'docker -e DB_PASSWORD' => [
        'docker run -e DB_PASSWORD=secret mysql',
        'docker run -e DB_PASSWORD=[REDACTED] mysql',
      ],
      'docker -e DB_PASSWORD quoted with two words' => [
        'docker run -e DB_PASSWORD="two words" mysql',
        'docker run -e DB_PASSWORD="[REDACTED]" mysql',
      ],
      'password= in a command' => [
        'tool password=secret;echo done',
        'tool password=[REDACTED];echo done',
      ],
      'pwd= in a command' => [
        'tool pwd=secret&x=1',
        'tool pwd=[REDACTED]&x=1',
      ],
      'pass= in a command' => [
        'tool pass=secret next',
        'tool pass=[REDACTED] next',
      ],
      'createServiceUser command' => [
        "mysql -h 'db.example' -uroot -p'root pass' -e 'CREATE USER \"'app_user'\"@\"%\" IDENTIFIED BY \"'user pass'\"; FLUSH PRIVILEGES;' 2>&1",
        "mysql -h 'db.example' -uroot -p[REDACTED] -e 'CREATE USER \"'app_user'\"@\"%\" IDENTIFIED BY [REDACTED]; FLUSH PRIVILEGES;' 2>&1",
      ],
      'createServiceUser command with simple passwords' => [
        "mysql -h 'db.example' -uroot -p'rootpass' -e 'CREATE USER \"'app_user'\"@\"%\" IDENTIFIED BY \"'userpass'\"; FLUSH PRIVILEGES;' 2>&1",
        "mysql -h 'db.example' -uroot -p[REDACTED] -e 'CREATE USER \"'app_user'\"@\"%\" IDENTIFIED BY [REDACTED]; FLUSH PRIVILEGES;' 2>&1",
      ],
      'updateServiceUserPassword command' => [
        "mysql -h 'db.example' -uroot -p'rootpass' -e 'ALTER USER \"'app_user'\"@\"%\" IDENTIFIED BY \"'userpass'\"; FLUSH PRIVILEGES;' 2>&1",
        "mysql -h 'db.example' -uroot -p[REDACTED] -e 'ALTER USER \"'app_user'\"@\"%\" IDENTIFIED BY [REDACTED]; FLUSH PRIVILEGES;' 2>&1",
      ],
      'container name and port stay readable' => [
        'docker exec wisski-prod--drupal mysql --port=3306 -pmysecret',
        'docker exec wisski-prod--drupal mysql --port=3306 -p[REDACTED]',
      ],
      'createDatabase command with a quote in the root password' => [
        "mysql -h 'db.example' -uroot -p'ab'\\''cd' -e 'CREATE DATABASE `app`;' 2>&1",
        "mysql -h 'db.example' -uroot -p[REDACTED] -e 'CREATE DATABASE `app`;' 2>&1",
      ],
    ];
  }

  /**
   * Tests that a command without a secret stays unchanged.
   *
   * @covers ::sanitizeCommandForLogging
   */
  #[DataProvider('commandWithoutSecretProvider')]
  public function testSanitizeCommandKeepsCommandWithoutSecret(string $command): void {
    $this->assertSame($command, $this->testClass->testSanitizeCommandForLogging($command));
  }

  /**
   * Provides commands without a secret.
   *
   * @return array<string, array{string}>
   *   One command per case.
   */
  public static function commandWithoutSecretProvider(): array {
    return [
      'container name' => ['docker restart wisski-prod--drupal'],
      'port option' => ['mysql -h db --port=3306 -e "SELECT 1"'],
      'project name in a path' => ['docker compose -f /srv/custom-project-name/compose.yml ps'],
    ];
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

    $this->assertSame(
      $expected,
      $this->testClass->testSanitizeForLogging($input, $additionalPatterns)
    );
  }

}
