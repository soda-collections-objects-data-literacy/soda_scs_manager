<?php

declare(strict_types=1);
// web/modules/custom/soda_scs_manager/src/Exception/PortainerException.php
namespace Drupal\soda_scs_manager\Exception;

use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Exception for Portainer requests.
 */
class SodaScsPortainerException extends \RuntimeException implements SodaScsManagerExceptionInterface {
  use StringTranslationTrait;

  /**
   * The safe context.
   *
   * @var array
   */
  protected array $safeContext = [];

  /**
   * Constructs a SodaScsPortainerException.
   *
   * @param string $message
   *   The error message.
   * @param int $code
   *   The error code.
   * @param \Throwable|null $previous
   *   The previous exception.
   * @param array $safeContext
   *   The safe context.
   */
  public function __construct(
    string $message,
    int $code = 0,
    ?\Throwable $previous = NULL,
    array $safeContext = [],
  ) {
    parent::__construct($this->t("Portainer request failed: @message", ['@message' => $message]), $code, $previous);
    $this->safeContext = $safeContext;
  }

  /**
   * Gets the safe context.
   *
   * @return array
   *   The safe context.
   */
  public function getSafeContext(): array {
    return $this->safeContext;
  }

  /**
   * Gets the user message.
   *
   * @return string
   *   The user message.
   */
  public function getUserMessage(): string {
    return $this->t('Service operation failed. Please try again later.');
  }

}
