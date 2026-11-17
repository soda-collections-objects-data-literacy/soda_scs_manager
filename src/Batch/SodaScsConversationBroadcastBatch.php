<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Batch;

use Drupal\Core\StringTranslation\PluralTranslatableMarkup;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\user\Entity\User;

/**
 * Batch steps that start one conversation per recipient.
 *
 * Batch callbacks are called statically and cannot get services injected,
 * so they take them from the container.
 */
final class SodaScsConversationBroadcastBatch {

  /**
   * Recipients per batch request.
   */
  public const CHUNK_SIZE = 25;

  /**
   * Starts the conversations of one chunk of recipients.
   *
   * @param int $senderId
   *   The admin who writes.
   * @param list<int> $recipientIds
   *   The recipients of this chunk.
   * @param string $subject
   *   The subject.
   * @param string $body
   *   The first message.
   * @param array $context
   *   The batch context.
   */
  public static function startChunk(int $senderId, array $recipientIds, string $subject, string $body, array &$context): void {
    $sender = User::load($senderId);
    if ($sender === NULL) {
      \Drupal::logger('soda_scs_manager')->warning('Could not start @count conversations: admin @sender no longer exists.', [
        '@count' => count($recipientIds),
        '@sender' => $senderId,
      ]);
      $context['results']['failed'] = ($context['results']['failed'] ?? 0) + count($recipientIds);
      return;
    }
    $helpers = \Drupal::service('soda_scs_manager.conversation.helpers');
    foreach ($recipientIds as $recipientId) {
      $conversation = $helpers->startConversation($sender, (int) $recipientId, $subject, $body);
      if ($conversation !== NULL) {
        $context['results']['started'] = ($context['results']['started'] ?? 0) + 1;
      }
      else {
        $context['results']['failed'] = ($context['results']['failed'] ?? 0) + 1;
      }
    }
    $context['message'] = new TranslatableMarkup('Sent to @count recipients.', ['@count' => $context['results']['started'] ?? 0]);
  }

  /**
   * Reports how many conversations were started.
   *
   * @param bool $success
   *   FALSE when a batch request ended with an error.
   * @param array $results
   *   Counters of the steps.
   */
  public static function finish(bool $success, array $results): void {
    $messenger = \Drupal::messenger();
    $started = (int) ($results['started'] ?? 0);
    if ($started > 0) {
      $messenger->addStatus(new PluralTranslatableMarkup($started, 'Started 1 conversation.', 'Started @count conversations.'));
    }
    if (!$success || !empty($results['failed'])) {
      $messenger->addError(new TranslatableMarkup('Some conversations could not be started. See the log for details.'));
    }
  }

}
