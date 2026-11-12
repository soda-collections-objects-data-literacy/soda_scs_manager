<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\EventSubscriber;

use Drupal\Core\Form\EnforcedResponseException;
use Drupal\Core\Form\FormAjaxException;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\ParamConverter\ParamNotConvertedException;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Throwable;

/**
 * Renders a controlled response for unhandled server exceptions.
 */
final class WsodCatcherSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the WSOD catcher.
   */
  public function __construct(
    private readonly RendererInterface $renderer,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      // Below EnforcedFormResponseSubscriber (128) so form redirects still work.
      // Above FormAjaxSubscriber (51): FormAjaxException is skipped explicitly
      // so AJAX form processing (e.g. managed file uploads) still works.
      KernelEvents::EXCEPTION => ['onException', 100],
    ];
  }

  /**
   * Replaces an unhandled server exception with the custom error page.
   */
  public function onException(ExceptionEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    $exception = $event->getThrowable();
    // Form API uses this exception to force redirects (e.g. OpenID Connect SSO).
    if ($exception instanceof EnforcedResponseException) {
      return;
    }
    // Form API uses this to break out of AJAX form processing (e.g. image
    // uploads). FormAjaxSubscriber converts it into an AjaxResponse.
    if ($exception instanceof FormAjaxException) {
      return;
    }
    // An entity ID in the path that cannot be loaded (e.g. a deleted
    // component). ParamConversionEnhancer (priority 75) turns it into a 404.
    if ($exception instanceof ParamNotConvertedException) {
      return;
    }
    if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
      return;
    }

    $this->loggerFactory->get('soda_scs_manager')->error(
      'Unhandled server exception: @message',
      ['@message' => $exception->getMessage() !== '' ? $exception->getMessage() : $exception::class]
    );

    // The exception message stays in the log: it can carry internal details
    // (SQL, service URLs, paths) and the page is public.
    try {
      $build = [
        '#theme' => 'scs_custom_theme_wsod',
      ];
      $output = $this->renderer->renderRoot($build);
    }
    catch (Throwable $renderException) {
      $this->loggerFactory->get('soda_scs_manager')->error(
        'The WSOD error template could not be rendered: @message',
        ['@message' => $renderException->getMessage()]
      );
      // Plain English on purpose: this runs when rendering itself failed, so
      // it must not depend on the theme or the translation service.
      $output = '<h1>Something went wrong</h1><p>The page could not be displayed.</p>';
    }

    $event->setResponse(new Response((string) $output, 500, [
      'Content-Type' => 'text/html; charset=UTF-8',
    ]));
  }

}
