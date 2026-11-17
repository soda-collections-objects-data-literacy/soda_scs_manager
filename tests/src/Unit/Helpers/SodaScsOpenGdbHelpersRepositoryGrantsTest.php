<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Exception\SodaScsHelpersException;
use Drupal\soda_scs_manager\Helpers\SodaScsOpenGdbHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsOpenGdbRequestInterface;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\Tests\UnitTestCase;
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
