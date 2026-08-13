<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\MessageType;

use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;

/**
 * Resolves message type handlers from tagged services.
 */
final class SodaScsMessageTypeManager {

  /**
   * Type handlers keyed by plugin ID.
   *
   * @var array<string, \Drupal\soda_scs_manager\MessageType\SodaScsMessageTypeInterface>
   */
  private array $types = [];

  /**
   * Constructs the manager.
   *
   * @param iterable<int, \Drupal\soda_scs_manager\MessageType\SodaScsMessageTypeInterface> $types
   *   Tagged message type services.
   */
  public function __construct(
    private readonly SodaScsGenericMessageType $genericType,
    iterable $types,
  ) {
    foreach ($types as $type) {
      $this->types[$type->getId()] = $type;
    }
  }

  /**
   * Resolve the handler for a message, falling back to stored subject/body.
   */
  public function resolve(SodaScsMessageInterface $message): SodaScsMessageTypeInterface {
    return $this->types[$message->getType()] ?? $this->genericType;
  }

}
