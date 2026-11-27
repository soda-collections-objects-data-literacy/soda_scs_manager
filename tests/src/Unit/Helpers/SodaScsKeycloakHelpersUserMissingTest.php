<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Helpers;

use Drupal\soda_scs_manager\Helpers\SodaScsKeycloakHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsNextcloudGroupHelpers;
use Drupal\soda_scs_manager\RequestActions\SodaScsKeycloakServiceGroupActions;
use Drupal\soda_scs_manager\RequestActions\SodaScsServiceRequestInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Prophecy\ObjectProphecy;
use Prophecy\PhpUnit\ProphecyTrait;

/**
 * Whether Keycloak says that a user does not exist.
 *
 * Issue 3638812: only a 404 with "User not found" marks a user as gone; a
 * failed request or another error must never read as a missing user.
 */
#[CoversMethod(SodaScsKeycloakHelpers::class, 'isKeycloakUserMissing')]
#[Group('soda_scs_manager')]
class SodaScsKeycloakHelpersUserMissingTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * The mocked Keycloak user request actions.
   */
  private ObjectProphecy $userActions;

  /**
   * The service under test.
   */
  private SodaScsKeycloakHelpers $helpers;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->userActions = $this->prophesize(SodaScsServiceRequestInterface::class);
    $this->userActions->buildTokenRequest()->willReturn(['request' => 'token']);
    $this->userActions->makeRequest(['request' => 'token'])->willReturn([
      'success' => TRUE,
      'data' => ['keycloakResponse' => new Response(200, [], json_encode(['access_token' => 'fetched-token']))],
    ]);
    $this->helpers = new SodaScsKeycloakHelpers(
      $this->prophesize(SodaScsKeycloakServiceGroupActions::class)->reveal(),
      $this->userActions->reveal(),
      $this->prophesize(SodaScsNextcloudGroupHelpers::class)->reveal(),
    );
  }

  /**
   * A 404 "User not found" is a missing user; the user is asked for by id.
   */
  public function testMissingUserWhenKeycloakAnswers404UserNotFound(): void {
    $this->userActions->buildGetRequest([
      'routeParams' => ['userId' => 'sub-1'],
      'token' => 'fetched-token',
    ])->willReturn(['request' => 'get'])->shouldBeCalledOnce();
    $this->userActions->makeRequest(['request' => 'get'])->willReturn($this->failed(404, '{"error":"User not found"}'));

    $this->assertTrue($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * An existing user is not missing.
   */
  public function testExistingUserIsNotMissing(): void {
    $this->answerGetWith([
      'success' => TRUE,
      'statusCode' => 200,
      'data' => ['keycloakResponse' => new Response(200, [], '{"id":"sub-1"}')],
    ]);

    $this->assertFalse($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * A 404 for another reason (unknown realm) is not a missing user.
   */
  public function testOtherNotFoundIsNotMissingUser(): void {
    $this->answerGetWith($this->failed(404, '{"error":"Realm not found."}'));

    $this->assertFalse($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * Other statuses are no missing user, even when the body names the user.
   */
  #[DataProvider('provideOtherStatuses')]
  public function testOtherStatusIsNotMissingUser(int $status): void {
    $this->answerGetWith($this->failed($status, '{"error":"User not found"}'));

    $this->assertFalse($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * Statuses of an outage, a refused token and a bad request.
   *
   * @return array<string, array{0: int}>
   *   The statuses.
   */
  public static function provideOtherStatuses(): array {
    return [
      'unauthorized' => [401],
      'forbidden' => [403],
      'server error' => [500],
      'unavailable' => [503],
    ];
  }

  /**
   * A 404 whose exception carries no response is no missing user.
   */
  public function testNotFoundWithoutResponseIsNotMissingUser(): void {
    $this->answerGetWith([
      'success' => FALSE,
      'statusCode' => 404,
      'data' => ['keycloakResponse' => new RequestException('Connection reset', new Request('GET', 'https://keycloak.test'))],
    ]);

    $this->assertFalse($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * A 404 without a Guzzle exception in the answer is no missing user.
   */
  public function testNotFoundWithoutExceptionIsNotMissingUser(): void {
    $this->answerGetWith(['success' => FALSE, 'statusCode' => 404, 'data' => []]);

    $this->assertFalse($this->helpers->isKeycloakUserMissing('sub-1'));
  }

  /**
   * Feeds the answer of the user request.
   *
   * @param array $answer
   *   The answer of makeRequest().
   */
  private function answerGetWith(array $answer): void {
    $this->userActions->buildGetRequest(['routeParams' => ['userId' => 'sub-1'], 'token' => 'fetched-token'])
      ->willReturn(['request' => 'get']);
    $this->userActions->makeRequest(['request' => 'get'])->willReturn($answer);
  }

  /**
   * Builds the answer makeRequest() gives for a failed request.
   *
   * @return array<string, mixed>
   *   The answer.
   */
  private function failed(int $status, string $body): array {
    $response = new Response($status, [], $body);
    return [
      'success' => FALSE,
      'statusCode' => $status,
      'data' => ['keycloakResponse' => new RequestException('failed', new Request('GET', 'https://keycloak.test'), $response)],
    ];
  }

}
