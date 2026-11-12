<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Exception;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A failure whose reason may be shown to the user.
 *
 * The exception message is for the log and may hold technical details; only
 * the translated user message reaches the messenger. Use it for causes the
 * user can act on (database exists, Nextcloud not connected, …), never for
 * raw service output.
 */
class SodaScsUserFacingException extends \Exception {

  /**
   * Constructs a SodaScsUserFacingException.
   *
   * @param string $message
   *   The message for the log.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $userMessage
   *   The translated reason shown to the user.
   * @param \Throwable|null $previous
   *   The previous throwable.
   */
  public function __construct(
    string $message,
    protected readonly TranslatableMarkup $userMessage,
    ?\Throwable $previous = NULL,
  ) {
    parent::__construct($message, 0, $previous);
  }

  /**
   * Returns the translated reason that is safe to show to the user.
   *
   * @return \Drupal\Core\StringTranslation\TranslatableMarkup
   *   The user message.
   */
  public function getUserMessage(): TranslatableMarkup {
    return $this->userMessage;
  }

}
