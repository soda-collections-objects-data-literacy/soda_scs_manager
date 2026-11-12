<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Kernel\EventSubscriber;

use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Tests\soda_scs_manager\Kernel\SodaScsKernelTestBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the WSOD catcher together with core's exception subscribers.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\EventSubscriber\WsodCatcherSubscriber
 */
class WsodCatcherSubscriberKernelTest extends SodaScsKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->container->get('router.builder')->rebuild();
  }

  /**
   * @covers ::onException
   */
  public function testParamNotConvertedExceptionBecomesNotFoundAfterDispatch(): void {
    $event = new ExceptionEvent(
      $this->container->get('http_kernel'),
      Request::create('/soda-scs-manager/component/99999999/delete'),
      HttpKernelInterface::MAIN_REQUEST,
      new ParamNotConvertedException('The "soda_scs_component" parameter was not converted'),
    );

    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::EXCEPTION);

    $this->assertInstanceOf(NotFoundHttpException::class, $event->getThrowable());
  }

  /**
   * @covers ::onException
   */
  public function testUnknownComponentUrlReturnsNotFound(): void {
    $this->container->get('current_user')->setAccount($this->testUser);
    $request = Request::create('/soda-scs-manager/component/99999999/delete');

    $response = $this->container->get('http_kernel')->handle($request);

    $this->assertSame(404, $response->getStatusCode());
  }

}
