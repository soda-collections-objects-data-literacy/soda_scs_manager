<?php
// web/modules/custom/soda_scs_manager/src/Exception/PortainerException.php
namespace Drupal\soda_scs_manager\Exception;

use Drupal\Core\StringTranslation\StringTranslationTrait;

class SodaScsPortainerException extends \RuntimeException implements SodaScsManagerExceptionInterface {
  use StringTranslationTrait;
  
  public function __construct(
    string $message = "",
    int $code = 0,
    ?\Throwable $previous = null,
    protected array $safeContext = []
  ) {
    parent::__construct($this->t($message), $code, $previous);
  }

  public function getSafeContext(): array {
    return $this->safeContext;
  }

  public function getUserMessage(): string {
    return $this->t('Service operation failed. Please try again later.');
  }
}