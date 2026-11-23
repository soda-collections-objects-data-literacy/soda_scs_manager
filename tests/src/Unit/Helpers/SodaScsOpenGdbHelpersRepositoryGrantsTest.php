<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The repository grants of a recreated OpenGDB account.
 *
 * Issue 3638851. The OpenGDB requests are doubles.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers
 * @group soda_scs_manager
 */
class SodaScsOpenGdbHelpersRepositoryGrantsTest extends UnitTestCase {

  /**
   * The OpenGDB request actions double.
   */
  private SodaScsOpenGdbRequestInterface&MockObject $requestActions;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->requestActions = $this->createMock(SodaScsOpenGdbRequestInterface::class);
  }

  /**
   * The grant list is ROLE_USER plus read and write on every repository.
   *
   * @covers ::setUserRepositoryGrants
   */
  public function testGrantListHoldsRoleAndRightsOnEveryRepository(): void {
    $this->requestActions->expects($this->once())->method('buildUpdateRequest')
      ->with($this->callback(function (array $params): bool {
        $this->assertSame('user', $params['type']);
        $this->assertSame(['username' => 'alice'], $params['routeParams']);
        $this->assertSame([
          'ROLE_USER',
          'READ_REPO_ts-first',
          'WRITE_REPO_ts-first',
          'READ_REPO_ts-second',
          'WRITE_REPO_ts-second',
        ], $params['body']['grantedAuthorities']);
        return TRUE;
      }))
      ->willReturn(['route' => 'update-user']);
    $this->requestActions->expects($this->once())->method('makeRequest')
      ->with(['route' => 'update-user'])
      ->willReturn(['success' => TRUE]);

    $this->helpers()->setUserRepositoryGrants('alice', ['ts-first', 'ts-second']);
  }

  /**
   * A refused update reaches the caller as a helpers exception.
   *
   * @covers ::setUserRepositoryGrants
   */
  public function testRefusedUpdateThrows(): void {
    $this->requestActions->method('buildUpdateRequest')->willReturn(['route' => 'update-user']);
    $this->requestActions->method('makeRequest')->willReturn(['success' => FALSE, 'error' => 'HTTP 500']);

    $this->expectException(SodaScsHelpersException::class);

    $this->helpers()->setUserRepositoryGrants('alice', ['ts-first']);
  }

  /**
   * Added repositories keep the rights the account already has.
   *
   * Issue 3640913: a new project owner is an existing account with rights on
   * other repositories; replacing the list would lock it out of them.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsKeepsExistingAuthorities(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('makeRequest')->willReturnMap([
      [['route' => 'get-user'], $this->userResponse(['ROLE_USER', 'READ_REPO_ts-old', 'WRITE_REPO_ts-old'])],
      [['route' => 'update-user'], ['success' => TRUE]],
    ]);
    $sent = NULL;
    $this->requestActions->expects($this->once())->method('buildUpdateRequest')
      ->willReturnCallback(function (array $params) use (&$sent): array {
        $sent = $params;
        return ['route' => 'update-user'];
      });

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-new']);

    $this->assertSame(['username' => 'alice'], $sent['routeParams']);
    $this->assertSame(
      ['ROLE_USER', 'READ_REPO_ts-old', 'WRITE_REPO_ts-old', 'READ_REPO_ts-new', 'WRITE_REPO_ts-new'],
      $sent['body']['grantedAuthorities'],
    );
  }

  /**
   * A repository the account already has is not listed twice.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsDoesNotDuplicateAuthorities(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('makeRequest')->willReturnMap([
      [['route' => 'get-user'], $this->userResponse(['READ_REPO_ts-one', 'WRITE_REPO_ts-one'])],
      [['route' => 'update-user'], ['success' => TRUE]],
    ]);
    $sent = NULL;
    $this->requestActions->method('buildUpdateRequest')
      ->willReturnCallback(function (array $params) use (&$sent): array {
        $sent = $params;
        return ['route' => 'update-user'];
      });

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-one']);

    $this->assertSame(['ROLE_USER', 'READ_REPO_ts-one', 'WRITE_REPO_ts-one'], $sent['body']['grantedAuthorities']);
  }

  /**
   * An account OpenGDB does not know is never written.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsThrowsAndWritesNothingForUnknownUser(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('makeRequest')->willReturn([
      'success' => FALSE,
      'data' => ['openGdbResponse' => new \Exception('not found', 404)],
    ]);
    $this->requestActions->expects($this->never())->method('buildUpdateRequest');

    $this->expectException(SodaScsHelpersException::class);

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-new']);
  }

  /**
   * A failing read of the account is not mistaken for an empty grant list.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsThrowsAndWritesNothingWhenReadFails(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('makeRequest')->willReturn([
      'success' => FALSE,
      'data' => ['openGdbResponse' => new \Exception('server error', 500)],
    ]);
    $this->requestActions->expects($this->never())->method('buildUpdateRequest');

    $this->expectException(SodaScsHelpersException::class);

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-new']);
  }

  /**
   * An answer without a grant list is malformed and nothing is written.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsThrowsAndWritesNothingForMalformedAnswer(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('makeRequest')->willReturn([
      'success' => TRUE,
      'data' => ['openGdbResponse' => new Response(200, [], '{"username":"alice"}')],
    ]);
    $this->requestActions->expects($this->never())->method('buildUpdateRequest');

    $this->expectException(SodaScsHelpersException::class);

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-new']);
  }

  /**
   * A refused update reaches the caller as a helpers exception.
   *
   * @covers ::addUserRepositoryGrants
   */
  public function testAddGrantsThrowsWhenUpdateIsRefused(): void {
    $this->requestActions->method('buildGetRequest')->willReturn(['route' => 'get-user']);
    $this->requestActions->method('buildUpdateRequest')->willReturn(['route' => 'update-user']);
    $this->requestActions->method('makeRequest')->willReturnMap([
      [['route' => 'get-user'], $this->userResponse(['ROLE_USER'])],
      [['route' => 'update-user'], ['success' => FALSE, 'error' => 'HTTP 500']],
    ]);

    $this->expectException(SodaScsHelpersException::class);

    $this->helpers()->addUserRepositoryGrants('alice', ['ts-new']);
  }

  /**
   * An OpenGDB answer with the given grants for the user lookup.
   *
   * @param string[] $authorities
   *   The granted authorities.
   *
   * @return array<string, mixed>
   *   The request action result.
   */
  private function userResponse(array $authorities): array {
    $body = json_encode(['username' => 'alice', 'grantedAuthorities' => $authorities]);
    return [
      'success' => TRUE,
      'data' => ['openGdbResponse' => new Response(200, [], $body)],
    ];
  }

  /**
   * Builds the helper under test.
   */
  private function helpers(): SodaScsOpenGdbHelpers {
    return new SodaScsOpenGdbHelpers(
      $this->requestActions,
      $this->createMock(SodaScsServiceHelpers::class),
      $this->createMock(SodaScsServiceKeyActionsInterface::class),
    );
  }

}
