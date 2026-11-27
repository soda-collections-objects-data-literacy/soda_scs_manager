<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Controller;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\openid_connect\OpenIDConnectClaims;
use Drupal\openid_connect\OpenIDConnectClientEntityInterface;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\openid_connect\Plugin\OpenIDConnectClientInterface;
use Drupal\soda_scs_manager\Controller\SodaScsKeycloakLoginRedirectController;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests the login route that doubles as the 403 page.
 *
 * Background requests of an expired session (health and notification polling)
 * must get a plain JSON 403 instead of a Keycloak authorize redirect.
 */
#[CoversMethod(SodaScsKeycloakLoginRedirectController::class, 'redirectToKeycloak')]
#[CoversMethod(SodaScsKeycloakLoginRedirectController::class, 'isBackgroundRequest')]
#[Group('soda_scs_manager')]
class SodaScsKeycloakLoginRedirectControllerTest extends UnitTestCase {

  private const CLIENT_ID = 'test_client';

  /**
   * Builds the controller with the given session mock.
   *
   * @param \Drupal\openid_connect\OpenIDConnectSessionInterface $session
   *   The OpenID Connect session mock.
   * @param \Drupal\openid_connect\OpenIDConnectClientEntityInterface|null $client
   *   The client entity the storage returns, or NULL for none.
   * @param string $configuredClientId
   *   The client machine name stored in the module settings.
   */
  private function buildController(
    OpenIDConnectSessionInterface $session,
    ?OpenIDConnectClientEntityInterface $client = NULL,
    string $configuredClientId = self::CLIENT_ID,
  ): SodaScsKeycloakLoginRedirectController {
    $settings = $this->createMock(ImmutableConfig::class);
    $settings->method('get')->willReturnCallback(
      static fn (string $key) => $key === 'keycloak.keycloakTabs.generalSettings.fields.OpenIdConnectClientMachineName'
        ? $configuredClientId
        : NULL
    );
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('soda_scs_manager.settings')->willReturn($settings);

    $storage = $this->createMock(EntityStorageInterface::class);
    $storage->method('load')->with(self::CLIENT_ID)->willReturn($client);
    $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $entityTypeManager->method('getStorage')->with('openid_connect_client')->willReturn($storage);

    $claims = $this->createMock(OpenIDConnectClaims::class);
    $claims->method('getScopes')->willReturn('openid email');

    $controller = new SodaScsKeycloakLoginRedirectController($configFactory, $entityTypeManager, $claims, $session);
    $controller->setStringTranslation($this->getStringTranslationStub());
    return $controller;
  }

  /**
   * Builds an enabled client whose plugin answers authorize() with a redirect.
   */
  private function buildEnabledClient(): OpenIDConnectClientEntityInterface {
    $plugin = $this->createMock(OpenIDConnectClientInterface::class);
    $plugin->method('authorize')->willReturn(new RedirectResponse('https://idp.example.test/authorize'));
    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $client->method('status')->willReturn(TRUE);
    $client->method('getPlugin')->willReturn($plugin);
    return $client;
  }

  /**
   * Builds a GET request to the login route with the given server headers.
   *
   * @param array<string, string> $server
   *   Server variables, e.g. HTTP_ACCEPT.
   */
  private function loginRequest(array $server): Request {
    return Request::create('/user/login', 'GET', [], [], [], $server);
  }

  /**
   * Runs a background request and asserts the JSON 403 answer.
   *
   * @param array<string, string> $server
   *   Server variables that mark the request as a background request.
   */
  private function assertBackgroundRequestGetsJson403(array $server): void {
    $session = $this->createMock(OpenIDConnectSessionInterface::class);
    $session->expects($this->never())->method('saveDestination');
    $session->expects($this->never())->method('saveOp');
    $client = $this->createMock(OpenIDConnectClientEntityInterface::class);
    $client->expects($this->never())->method('getPlugin');

    $response = $this->buildController($session, $client)
      ->redirectToKeycloak($this->loginRequest($server));

    $this->assertInstanceOf(JsonResponse::class, $response);
    $this->assertSame(403, $response->getStatusCode());
    $this->assertSame(
      ['message' => 'Your session has expired. Reload the page to log in again.'],
      json_decode((string) $response->getContent(), TRUE),
    );
  }

  /**
   * Tests XML HTTP request gets JSON 403 without authorize request.
   */
  public function testXmlHttpRequestGetsJson403WithoutAuthorizeRequest(): void {
    $this->assertBackgroundRequestGetsJson403(['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
  }

  /**
   * Tests JSON accept request gets JSON 403 without authorize request.
   */
  public function testJsonAcceptRequestGetsJson403WithoutAuthorizeRequest(): void {
    $this->assertBackgroundRequestGetsJson403(['HTTP_ACCEPT' => 'application/json']);
  }

  /**
   * Tests JSON accept with quality fallback still gets JSON 403.
   */
  public function testJsonAcceptWithQualityFallbackStillGetsJson403(): void {
    $this->assertBackgroundRequestGetsJson403(['HTTP_ACCEPT' => 'application/json, text/plain, */*']);
  }

  /**
   * Page navigations go on to Keycloak.
   *
   * @param array<string, string> $server
   *   Server variables of a browser navigation.
   */
  #[DataProvider('pageNavigationHeadersProvider')]
  public function testPageNavigationStartsAuthorizeRequest(array $server): void {
    $session = $this->createMock(OpenIDConnectSessionInterface::class);
    $session->expects($this->once())->method('saveDestination');
    $session->expects($this->once())->method('saveOp')->with('login');

    $response = $this->buildController($session, $this->buildEnabledClient())
      ->redirectToKeycloak($this->loginRequest($server));

    $this->assertInstanceOf(RedirectResponse::class, $response);
    $this->assertSame('https://idp.example.test/authorize', $response->getTargetUrl());
  }

  /**
   * Provides the headers of requests that are page navigations.
   *
   * @return array<string, array<int, array<string, string>>>
   *   Server variables by case name.
   */
  public static function pageNavigationHeadersProvider(): array {
    return [
      'browser accept header' => [['HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']],
      'html before json' => [['HTTP_ACCEPT' => 'text/html, application/json']],
      'no accept header' => [[]],
    ];
  }

  /**
   * Tests page navigation without enabled client is not found.
   */
  public function testPageNavigationWithoutEnabledClientIsNotFound(): void {
    $session = $this->createMock(OpenIDConnectSessionInterface::class);
    $session->expects($this->never())->method('saveDestination');

    $this->expectException(NotFoundHttpException::class);
    $this->buildController($session, NULL)
      ->redirectToKeycloak($this->loginRequest(['HTTP_ACCEPT' => 'text/html']));
  }

}
