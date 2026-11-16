<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Psr7\Response;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Group lookup and group delete of the Keycloak helpers.
 *
 * Issue 3638568: the lookup asks Keycloak for the exact name and throws when
 * it cannot answer, so a delete never takes an outage for a missing group.
 *
 * @coversDefaultClass \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers
 * @group soda_scs_manager
 */
class SodaScsKeycloakHelpersFindGroupTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The mocked Keycloak group request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $groupActions;

  /**
   * The mocked Keycloak user request actions.
   *
   * @var \Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $userActions;

  /**
   * The service under test.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers
   */
  protected SodaScsKeycloakHelpers $helpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->groupActions = $this->prophesize(SodaScsKeycloakServiceGroupActions::class);
    $this->userActions = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->helpers = new SodaScsKeycloakHelpers(
      $this->groupActions->reveal(),
      $this->userActions->reveal(),
    );
  }

  /**
   * The lookup asks for the exact name and returns the group.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameAsksForExactNameAndReturnsGroup(): void {
    $this->groupActions->buildGetAllRequest([
      'queryParams' => ['exact' => 'true', 'search' => 'wisski-foo-admin'],
      'token' => 'not-a-token',
    ])
      ->willReturn(['request' => 'lookup'])
      ->shouldBeCalledOnce();
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn($this->answer([
      ['id' => 'uuid-1', 'name' => 'wisski-foo-admin-2'],
      ['id' => 'uuid-2', 'name' => 'wisski-foo-admin'],
    ]));

    $group = $this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token');

    $this->assertSame(['id' => 'uuid-2', 'name' => 'wisski-foo-admin'], $group);
  }

  /**
   * Keycloak searches by substring: a similar name is no match.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameReturnsNullWhenOnlySimilarNamesAreFound(): void {
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn($this->answer([
      ['id' => 'uuid-1', 'name' => 'wisski-foo-admin-2'],
      ['id' => 'uuid-3', 'name' => 'xwisski-foo-admin'],
      ['id' => 'uuid-4'],
    ]));

    $this->assertNull($this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token'));
  }

  /**
   * An empty list is a missing group, not a failure.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameReturnsNullWhenThereIsNoGroup(): void {
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn($this->answer([]));

    $this->assertNull($this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token'));
  }

  /**
   * A body that is no JSON list is read as no group.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameReturnsNullWhenBodyIsNoList(): void {
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], 'not json')],
    ]);

    $this->assertNull($this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token'));
  }

  /**
   * A token is fetched when the caller has none.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameFetchesTokenWhenNoneIsGiven(): void {
    $this->userActions->buildTokenRequest()->willReturn(['request' => 'token']);
    $this->userActions->makeRequest(['request' => 'token'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode(['access_token' => 'fetched-token']))],
    ]);
    $this->groupActions->buildGetAllRequest([
      'queryParams' => ['exact' => 'true', 'search' => 'wisski-foo-user'],
      'token' => 'fetched-token',
    ])
      ->willReturn(['request' => 'lookup'])
      ->shouldBeCalledOnce();
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn($this->answer([
      ['id' => 'uuid-5', 'name' => 'wisski-foo-user'],
    ]));

    $this->assertSame('uuid-5', $this->helpers->findGroupByName('wisski-foo-user')['id']);
  }

  /**
   * Without a token the lookup throws instead of answering "no group".
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameThrowsWithoutToken(): void {
    $this->userActions->buildTokenRequest()->willReturn(['request' => 'token']);
    $this->userActions->makeRequest(['request' => 'token'])->willReturn(['success' => FALSE, 'error' => 'Unauthorized.']);
    $this->groupActions->buildGetAllRequest(Argument::any())->shouldNotBeCalled();

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Keycloak token request failed.');
    $this->helpers->findGroupByName('wisski-foo-admin');
  }

  /**
   * A failed lookup throws with the reason of the request.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameThrowsWhenLookupFails(): void {
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])
      ->willReturn(['success' => FALSE, 'error' => 'Server error.']);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Keycloak group lookup of wisski-foo-admin failed: Server error.');
    $this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token');
  }

  /**
   * A failed lookup without reason still throws.
   *
   * @covers ::findGroupByName
   */
  public function testFindGroupByNameThrowsWhenAnswerHasNoDetails(): void {
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])->willReturn([]);

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('Keycloak group lookup of wisski-foo-admin failed: no details');
    $this->helpers->findGroupByName('wisski-foo-admin', 'not-a-token');
  }

  /**
   * An outage during the lookup deletes no group.
   *
   * @covers ::deleteComponentAccessGroups
   */
  public function testDeleteComponentAccessGroupsDeletesNothingWhenLookupFails(): void {
    $this->userActions->buildTokenRequest()->willReturn(['request' => 'token']);
    $this->userActions->makeRequest(['request' => 'token'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode(['access_token' => 'not-a-token']))],
    ]);
    $this->groupActions->buildGetAllRequest(Argument::any())->willReturn(['request' => 'lookup']);
    $this->groupActions->makeRequest(['request' => 'lookup'])
      ->willReturn(['success' => FALSE, 'error' => 'Server error.']);
    $this->groupActions->buildDeleteRequest(Argument::any())->shouldNotBeCalled();

    $this->expectException(\RuntimeException::class);
    $this->helpers->deleteComponentAccessGroups('wisski-foo');
  }

  /**
   * Both groups that exist are deleted and reported.
   *
   * @covers ::deleteComponentAccessGroups
   */
  public function testDeleteComponentAccessGroupsDeletesBothGroups(): void {
    $this->userActions->buildTokenRequest()->willReturn(['request' => 'token']);
    $this->userActions->makeRequest(['request' => 'token'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode(['access_token' => 'not-a-token']))],
    ]);
    foreach (['admin', 'user'] as $kind) {
      $this->groupActions->buildGetAllRequest([
        'queryParams' => ['exact' => 'true', 'search' => 'wisski-foo-' . $kind],
        'token' => 'not-a-token',
      ])
        ->willReturn(['request' => 'lookup-' . $kind]);
      $this->groupActions->makeRequest(['request' => 'lookup-' . $kind])->willReturn($this->answer([
        ['id' => $kind . '-uuid', 'name' => 'wisski-foo-' . $kind],
      ]));
      $this->groupActions->buildDeleteRequest([
        'routeParams' => ['groupId' => $kind . '-uuid'],
        'token' => 'not-a-token',
      ])
        ->willReturn(['request' => 'delete-' . $kind]);
      $this->groupActions->makeRequest(['request' => 'delete-' . $kind])
        ->willReturn(['success' => TRUE])
        ->shouldBeCalledOnce();
    }

    $result = $this->helpers->deleteComponentAccessGroups('wisski-foo');

    $this->assertSame([
      'adminGroupName' => 'wisski-foo-admin',
      'userGroupName' => 'wisski-foo-user',
      'adminDeleted' => TRUE,
      'userDeleted' => TRUE,
    ], $result);
  }

  /**
   * A successful Keycloak answer with a JSON list.
   *
   * @param array $body
   *   The list the answer carries.
   *
   * @return array
   *   The request result.
   */
  protected function answer(array $body): array {
    return [
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode($body))],
    ];
  }

}
