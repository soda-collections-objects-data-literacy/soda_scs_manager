<?php

declare(strict_types=1);

namespace Drupal\Tests\soda_scs_manager\Unit\EventSubscriber;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Form\FormState;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Render\RendererInterface;
use Drupal\soda_scs_manager\EventSubscriber\WsodCatcherSubscriber;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Tests the controlled error page for unhandled server exceptions.
 *
 * @group soda_scs_manager
 * @coversDefaultClass \Drupal\soda_scs_manager\EventSubscriber\WsodCatcherSubscriber
 */
class WsodCatcherSubscriberTest extends UnitTestCase {

  private const SECRET_MESSAGE = 'SQLSTATE[HY000]: connection to core--mariadb--db refused';

  /**
   * The renderer.
   *
   * @var \Drupal\Core\Render\RendererInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private RendererInterface $renderer;

  /**
   * The module logger channel.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  private LoggerChannelInterface $logger;

  /**
   * The subscriber under test.
   */
  private WsodCatcherSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->renderer = $this->createMock(RendererInterface::class);
    $this->logger = $this->createMock(LoggerChannelInterface::class);
    $loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $loggerFactory->method('get')->with('soda_scs_manager')->willReturn($this->logger);
    $this->subscriber = new WsodCatcherSubscriber($this->renderer, $loggerFactory);
  }

  /**
   * Builds an exception event for a main or sub request.
   */
  private function buildEvent(\Throwable $exception, int $requestType = HttpKernelInterface::MAIN_REQUEST): ExceptionEvent {
    return new ExceptionEvent(
      $this->createMock(HttpKernelInterface::class),
      Request::create('/soda-scs-manager/component/1/delete'),
      $requestType,
      $exception,
    );
  }

  /**
   * Exceptions that other subscribers must handle are left alone.
   *
   * @return array<string, array{0: \Throwable}>
   *   Exceptions the subscriber must not turn into the WSOD page, by case name.
   */
  public static function skippedExceptionProvider(): array {
    return [
      'param not converted' => [new ParamNotConvertedException('The "soda_scs_component" parameter was not converted for the path "/soda-scs-manager/component/{soda_scs_component}/delete"')],
      'enforced response' => [new EnforcedResponseException(new RedirectResponse('/elsewhere'))],
      'form ajax' => [new FormAjaxException(['#type' => 'form'], new FormState(), 'ajax')],
      'not found' => [new NotFoundHttpException('nope')],
      'access denied' => [new AccessDeniedHttpException('denied')],
      'client error' => [new HttpException(400, 'bad request')],
    ];
  }

  /**
   * @covers ::onException
   */
  #[DataProvider('skippedExceptionProvider')]
  public function testOnExceptionLeavesHandledExceptionsAlone(\Throwable $exception): void {
    $this->logger->expects($this->never())->method($this->anything());
    $this->renderer->expects($this->never())->method('renderRoot');

    $event = $this->buildEvent($exception);
    $this->subscriber->onException($event);

    $this->assertFalse($event->hasResponse());
    $this->assertSame($exception, $event->getThrowable());
  }

  /**
   * @covers ::onException
   */
  public function testOnExceptionIgnoresSubRequests(): void {
    $this->logger->expects($this->never())->method($this->anything());
    $this->renderer->expects($this->never())->method('renderRoot');

    $event = $this->buildEvent(new \RuntimeException(self::SECRET_MESSAGE), HttpKernelInterface::SUB_REQUEST);
    $this->subscriber->onException($event);

    $this->assertFalse($event->hasResponse());
  }

  /**
   * @covers ::onException
   */
  public function testOnExceptionRendersErrorPageWithoutExceptionMessage(): void {
    $this->logger->expects($this->once())
      ->method('error')
      ->with('Unhandled server exception: @message', ['@message' => self::SECRET_MESSAGE]);
    $this->renderer->expects($this->once())
      ->method('renderRoot')
      ->with($this->callback(function (array $build): bool {
        $this->assertSame('scs_custom_theme_wsod', $build['#theme']);
        $this->assertArrayNotHasKey('#error_message', $build);
        return TRUE;
      }))
      ->willReturn('<h1>Error page</h1>');

    $event = $this->buildEvent(new \RuntimeException(self::SECRET_MESSAGE));
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertSame(500, $response->getStatusCode());
    $this->assertSame('<h1>Error page</h1>', $response->getContent());
    $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));
  }

  /**
   * @covers ::onException
   */
  public function testOnExceptionLogsClassNameWhenMessageIsEmpty(): void {
    $this->logger->expects($this->once())
      ->method('error')
      ->with('Unhandled server exception: @message', ['@message' => \LogicException::class]);
    $this->renderer->method('renderRoot')->willReturn('page');

    $event = $this->buildEvent(new \LogicException());
    $this->subscriber->onException($event);

    $this->assertSame(500, $event->getResponse()->getStatusCode());
  }

  /**
   * @covers ::onException
   */
  public function testOnExceptionRendersErrorPageForServerErrorHttpException(): void {
    $this->logger->expects($this->once())->method('error');
    $this->renderer->method('renderRoot')->willReturn('page');

    $event = $this->buildEvent(new HttpException(503, 'maintenance'));
    $this->subscriber->onException($event);

    $this->assertSame(500, $event->getResponse()->getStatusCode());
  }

  /**
   * @covers ::onException
   */
  public function testOnExceptionFallbackHtmlHidesExceptionMessageWhenRenderFails(): void {
    $loggedErrors = [];
    $this->logger->expects($this->exactly(2))
      ->method('error')
      ->willReturnCallback(function (string $message, array $context) use (&$loggedErrors): void {
        $loggedErrors[] = [$message, $context];
      });
    $this->renderer->method('renderRoot')
      ->willThrowException(new \RuntimeException('Twig template broken'));

    $event = $this->buildEvent(new \RuntimeException(self::SECRET_MESSAGE));
    $this->subscriber->onException($event);

    $response = $event->getResponse();
    $this->assertSame(500, $response->getStatusCode());
    $this->assertStringNotContainsString(self::SECRET_MESSAGE, (string) $response->getContent());
    $this->assertStringNotContainsString('core--mariadb--db', (string) $response->getContent());
    $this->assertStringNotContainsString('Twig template broken', (string) $response->getContent());
    $this->assertStringContainsString('Something went wrong', (string) $response->getContent());
    $this->assertSame('text/html; charset=UTF-8', $response->headers->get('Content-Type'));

    $this->assertSame(['Unhandled server exception: @message', ['@message' => self::SECRET_MESSAGE]], $loggedErrors[0]);
    $this->assertSame([
      'The WSOD error template could not be rendered: @message',
      ['@message' => 'Twig template broken'],
    ], $loggedErrors[1]);
  }

}
