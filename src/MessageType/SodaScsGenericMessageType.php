<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\MessageType;

use Drupal\Component\Utility\Html;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;

/**
 * Default handler for messages without a dedicated type plugin.
 *
 * Future private or group messages can start as this type (subject, body,
 * sender, recipient) and later add a plugin only when they need extra actions.
 */
final class SodaScsGenericMessageType implements SodaScsMessageTypeInterface {

  use StringTranslationTrait;

  /**
   * {@inheritdoc}
   */
  public function buildActions(SodaScsMessageInterface $message): array {
    return [
      'delete' => [
        'title' => $this->t('Delete'),
        'classes' => ['button', 'button--danger'],
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildBody(SodaScsMessageInterface $message): array {
    $body = (string) $message->get('body')->value;
    if ($body === '') {
      return [
        '#markup' => '<p>' . $this->t('This message has no body.') . '</p>',
      ];
    }

    return [
      '#markup' => '<p>' . nl2br(Html::escape($body), FALSE) . '</p>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function buildSubject(SodaScsMessageInterface $message): string {
    $subject = $message->getSubject();
    return $subject !== '' ? $subject : (string) $this->t('Message');
  }

  /**
   * {@inheritdoc}
   */
  public function getId(): string {
    return 'generic';
  }

  /**
   * {@inheritdoc}
   */
  public function handleAction(string $action, SodaScsMessageInterface $message, UserInterface $actor): SodaScsResult {
    if ($action !== 'delete') {
      return SodaScsResult::failure(
        error: 'unknown_action',
        message: (string) $this->t('This action is not available for this message.'),
        log: FALSE,
      );
    }

    try {
      $message->delete();
    }
    catch (\Throwable) {
      return SodaScsResult::failure(
        error: 'storage_error',
        message: (string) $this->t('Could not delete the message. Please try again.'),
      );
    }

    return SodaScsResult::success(
      data: [],
      message: (string) $this->t('The message has been deleted.'),
    );
  }

}
