<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\Demo;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\openid_connect\OpenIDConnectSessionInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\DependencyInjection\ContainerInterface;

require_once dirname(__DIR__, 4) . '/soda_scs_manager.module';

/**
 * When soda_scs_manager_openid_connect_post_authorize() queues a demo.
 *
 * Issue 3638489: the demo is requested only when openid_connect put an access
 * token into the session, so the Nextcloud credentials can be stored first.
 */
#[Group('soda_scs_manager')]
class SodaScsOpenidConnectPostAuthorizeHookTest extends UnitTestCase {

  use ProphecyTrait;

  /**
   * Accounts the hook asked the demo service for.
   *
   * @var \Drupal\user\UserInterface[]
   */
  protected array $demoRequests = [];

  /**
   * What the stub demo service answers.
   */
  protected bool $demoQueued = TRUE;

  /**
   * The mocked cache tags invalidator.
   *
   * @var \Drupal\Core\Cache\CacheTagsInvalidatorInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $invalidator;

  /**
   * The container before the test.
   */
  protected ?ContainerInterface $previousContainer = NULL;

  /**
   * The mocked openid_connect session.
   *
   * @var \Drupal\openid_connect\OpenIDConnectSessionInterface|\Prophecy\Prophecy\ObjectProphecy
   */
  protected $session;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->previousContainer = \Drupal::hasContainer() ? \Drupal::getContainer() : NULL;

    $this->session = $this->prophesize(OpenIDConnectSessionInterface::class);
    $this->invalidator = $this->prophesize(CacheTagsInvalidatorInterface::class);

    // The demo service is final; the hook only calls ensureFor().
    $test = $this;
    $demo = new class($test) {

      public function __construct(
        private readonly SodaScsOpenidConnectPostAuthorizeHookTest $test,
      ) {}

      /**
       * Records the request and answers like the demo service.
       */
      public function ensureFor(UserInterface $account): bool {
        return $this->test->recordDemoRequest($account);
      }

    };

    $container = new ContainerBuilder();
    $container->set('cache_tags.invalidator', $this->invalidator->reveal());
    $container->set('openid_connect.session', $this->session->reveal());
    $container->set('soda_scs_manager.demo.automatic', $demo);
    \Drupal::setContainer($container);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if ($this->previousContainer !== NULL) {
      \Drupal::setContainer($this->previousContainer);
    }
    else {
      \Drupal::unsetContainer();
    }
    parent::tearDown();
  }

  /**
   * Records a call of the stub demo service.
   */
  public function recordDemoRequest(UserInterface $account): bool {
    $this->demoRequests[] = $account;
    return $this->demoQueued;
  }

  /**
   * Linking an account saves no access token: no demo is requested.
   */
  public function testNoDemoWithoutAccessToken(): void {
    $this->session->retrieveAccessToken(FALSE)->willReturn(NULL);
    $this->invalidator->invalidateTags(Argument::any())->shouldNotBeCalled();

    soda_scs_manager_openid_connect_post_authorize($this->account(), []);

    $this->assertSame([], $this->demoRequests);
  }

  /**
   * A sign-in with an access token requests the demo for that account.
   *
   * The access token is read without clearing it, so later code still has it.
   */
  public function testDemoRequestedAndCacheTagInvalidatedWithAccessToken(): void {
    $this->session->retrieveAccessToken(FALSE)->willReturn('token')->shouldBeCalled();
    $this->invalidator->invalidateTags(['soda_scs_manager:user_demo:39'])->shouldBeCalledOnce();
    $account = $this->account();

    soda_scs_manager_openid_connect_post_authorize($account, []);

    $this->assertSame([$account], $this->demoRequests);
  }

  /**
   * No demo queued (one exists, or no template): the cache stays.
   */
  public function testCacheTagKeptWhenNoDemoQueued(): void {
    $this->demoQueued = FALSE;
    $this->session->retrieveAccessToken(FALSE)->willReturn('token');
    $this->invalidator->invalidateTags(Argument::any())->shouldNotBeCalled();

    soda_scs_manager_openid_connect_post_authorize($this->account(), []);

    $this->assertCount(1, $this->demoRequests);
  }

  /**
   * The signing-in user.
   */
  protected function account(): UserInterface {
    $account = $this->prophesize(UserInterface::class);
    $account->id()->willReturn(39);
    return $account->reveal();
  }

}
