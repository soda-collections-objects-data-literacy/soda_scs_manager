<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\MessageType;

use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;

/**
 * Plugin-like handler for a message type.
 *
 * Add a tagged service implementing this interface to support a new kind of
 * message (private, group, etc.) without changing the inbox UI.
 */
interface SodaScsMessageTypeInterface {

  /**
   * Build action submit buttons for the message detail form.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup|string, classes?: list<string>}>
   *   Action id keyed definitions.
   */
  public function buildActions(SodaScsMessageInterface $message): array;

  /**
   * Build the message body render array.
   */
  public function buildBody(SodaScsMessageInterface $message): array;

  /**
   * Build the subject shown in the inbox and detail header.
   */
  public function buildSubject(SodaScsMessageInterface $message): string;

  /**
   * Machine name matching soda_scs_message.type.
   */
  public function getId(): string;

  /**
   * Run a named action (accept, decline, delete, …).
   */
  public function handleAction(string $action, SodaScsMessageInterface $message, UserInterface $actor): SodaScsResult;

}
