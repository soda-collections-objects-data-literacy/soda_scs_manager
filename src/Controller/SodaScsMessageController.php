<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Render\Markup;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsMessageInterface;
use Drupal\soda_scs_manager\Form\SodaScsMessageActionForm;
use Drupal\soda_scs_manager\Helpers\SodaScsMessageHelpers;
use Drupal\soda_scs_manager\MessageType\SodaScsMessageTypeManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Inbox and message detail pages.
 */
final class SodaScsMessageController extends ControllerBase {

  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    FormBuilderInterface $formBuilder,
    private readonly SodaScsMessageHelpers $messageHelpers,
    private readonly SodaScsMessageTypeManager $messageTypeManager,
  ) {
    $this->formBuilder = $formBuilder;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('date.formatter'),
      $container->get('form_builder'),
      $container->get('soda_scs_manager.message.helpers'),
      $container->get('soda_scs_manager.message_type.manager'),
    );
  }

  /**
   * List messages for the current user.
   */
  public function inbox(): array {
    $messages = $this->messageHelpers->loadInbox($this->currentUser());
    $rows = [];

    foreach ($messages as $message) {
      $type = $this->messageTypeManager->resolve($message);
      $sender = $message->getSender();
      $senderName = $this->messageHelpers->resolveSenderName($message);
      $rows[] = [
        'id' => $message->id(),
        'is_unread' => !$message->isRead(),
        'sender' => $senderName,
        'sender_initials' => mb_strtoupper(mb_substr($senderName, 0, 1)),
        'sender_picture' => $sender !== NULL && $sender->hasField('user_picture') && !$sender->get('user_picture')->isEmpty()
          ? $sender->get('user_picture')->view(['label' => 'hidden'])
          : NULL,
        'subject' => $this->subjectMarkup($type->buildSubject($message)),
        'date' => $this->dateFormatter->format($message->getCreatedTime(), 'short'),
        'url' => $message->toUrl()->toString(),
        'actions' => $this->formBuilder->getForm(SodaScsMessageActionForm::class, $message, TRUE),
      ];
    }

    return [
      '#theme' => 'soda_scs_manager__messages',
      '#messages' => $rows,
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling'],
      ],
      '#cache' => [
        'contexts' => ['user'],
        'tags' => ['soda_scs_message_list:' . $this->currentUser()->id()],
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Redirect the former notifications path to the inbox.
   */
  public function redirectLegacyInbox(): RedirectResponse {
    return new RedirectResponse(
      Url::fromRoute('soda_scs_manager.messages')->toString(),
    );
  }

  /**
   * Open a message: mark read and show body plus type-specific actions.
   */
  public function view(SodaScsMessageInterface $soda_scs_message): array {
    $this->messageHelpers->markRead($soda_scs_message);
    $type = $this->messageTypeManager->resolve($soda_scs_message);

    return [
      '#theme' => 'soda_scs_manager__message',
      '#subject' => $this->subjectMarkup($type->buildSubject($soda_scs_message)),
      '#sender' => $this->messageHelpers->resolveSenderName($soda_scs_message),
      '#date' => $this->dateFormatter->format($soda_scs_message->getCreatedTime(), 'short'),
      '#body' => $type->buildBody($soda_scs_message),
      '#actions' => $this->formBuilder->getForm(SodaScsMessageActionForm::class, $soda_scs_message),
      '#inbox_url' => Url::fromRoute('soda_scs_manager.messages')->toString(),
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling'],
      ],
      '#cache' => [
        'max-age' => 0,
      ],
    ];
  }

  /**
   * Page title for a single message.
   */
  public function viewTitle(SodaScsMessageInterface $soda_scs_message): string {
    return strip_tags($this->messageTypeManager->resolve($soda_scs_message)->buildSubject($soda_scs_message));
  }

  /**
   * Allows only <strong> in a subject; placeholder values are already escaped.
   */
  private function subjectMarkup(string $subject): Markup {
    return Markup::create(Xss::filter($subject, ['strong']));
  }

}
