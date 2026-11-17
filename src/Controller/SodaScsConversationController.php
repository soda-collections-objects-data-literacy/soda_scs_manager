<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;
use Drupal\soda_scs_manager\Form\SodaScsConversationReplyForm;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conversation thread and the admin list of conversations.
 */
final class SodaScsConversationController extends ControllerBase {

  /**
   * Conversations per page of the admin list.
   */
  private const TEAM_PAGE_SIZE = 25;

  public function __construct(
    private readonly SodaScsConversationHelpers $conversationHelpers,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('soda_scs_manager.conversation.helpers'),
      $container->get('date.formatter'),
      $container->get('pager.manager'),
      $container->get('renderer'),
    );
  }

  /**
   * The messages of a thread as HTML, for the refresh of an open thread.
   */
  public function messages(SodaScsConversationInterface $soda_scs_conversation): Response {
    // Reading on GET is intended, as in core's history module: it only changes
    // the read flag of the viewer's side, never content.
    $this->conversationHelpers->markRead($soda_scs_conversation, $this->currentUser());
    $build = $this->buildMessageList($soda_scs_conversation);
    $response = new Response((string) $this->renderer->renderInIsolation($build));
    $response->setPrivate();
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * All conversations, for the admin team.
   */
  public function teamList(): array {
    if (!$this->conversationHelpers->isInstalled()) {
      return [
        '#markup' => $this->t('Conversations are not installed yet. Run drush updb.'),
        '#cache' => ['max-age' => 0],
      ];
    }
    $pager = $this->pagerManager->createPager($this->conversationHelpers->countForTeam(), self::TEAM_PAGE_SIZE);
    $conversations = $this->conversationHelpers->loadForTeam(self::TEAM_PAGE_SIZE, $pager->getCurrentPage() * self::TEAM_PAGE_SIZE);

    $rows = [];
    foreach ($conversations as $conversation) {
      $participant = $conversation->getParticipant();
      $rows[] = [
        'data' => [
          $participant !== NULL ? $participant->getDisplayName() : $this->t('Unknown user'),
          [
            'data' => [
              '#type' => 'link',
              '#title' => $conversation->label(),
              '#url' => $conversation->toUrl(),
            ],
          ],
          $conversation->getLastMessageTime() > 0 ? $this->dateFormatter->format($conversation->getLastMessageTime(), 'short') : '',
          $conversation->isUnreadForTeam() ? $this->t('New reply') : '',
        ],
        'class' => $conversation->isUnreadForTeam() ? ['scs-manager--messages-row--unread'] : [],
      ];
    }

    return [
      'new' => [
        '#type' => 'link',
        '#title' => $this->t('New message'),
        '#url' => Url::fromRoute('soda_scs_manager.conversations.start'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          $this->t('User'),
          $this->t('Subject'),
          $this->t('Last activity'),
          $this->t('Status'),
        ],
        '#rows' => $rows,
        '#empty' => $this->t('No conversations yet.'),
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['soda_scs_manager/globalStyling']],
      '#cache' => [
        'contexts' => ['url.query_args.pagers:0', 'user.permissions'],
        'tags' => [SodaScsConversationInterface::TEAM_CACHE_TAG],
      ],
    ];
  }

  /**
   * A conversation: messages as chat bubbles and the reply field.
   */
  public function view(SodaScsConversationInterface $soda_scs_conversation): array {
    // See messages() about marking read on GET.
    $this->conversationHelpers->markRead($soda_scs_conversation, $this->currentUser());
    $isAdmin = $this->currentUser()->hasPermission('soda scs manager admin');
    $participant = $soda_scs_conversation->getParticipant();

    return [
      '#theme' => 'soda_scs_manager__conversation',
      '#subject' => $soda_scs_conversation->label(),
      '#participant' => $isAdmin && $participant !== NULL ? $participant->getDisplayName() : '',
      '#messages' => $this->buildMessageList($soda_scs_conversation),
      '#reply_form' => $soda_scs_conversation->access('reply')
        ? $this->formBuilder()->getForm(SodaScsConversationReplyForm::class, $soda_scs_conversation)
        : NULL,
      '#back_url' => $isAdmin
        ? Url::fromRoute('soda_scs_manager.conversations.team')->toString()
        : Url::fromRoute('soda_scs_manager.messages')->toString(),
      '#refresh_url' => Url::fromRoute('soda_scs_manager.conversation.messages', [
        'soda_scs_conversation' => $soda_scs_conversation->id(),
      ])->toString(),
      '#attached' => [
        'library' => ['soda_scs_manager/globalStyling', 'soda_scs_manager/conversationThread'],
      ],
      '#cache' => ['max-age' => 0],
    ];
  }

  /**
   * Page title of a conversation.
   */
  public function viewTitle(SodaScsConversationInterface $soda_scs_conversation): string {
    return (string) $soda_scs_conversation->label();
  }

  /**
   * One chat bubble.
   */
  private function buildMessageItem(SodaScsConversationMessageInterface $message, AccountInterface $viewer): array {
    $author = $message->getAuthor();
    $name = $author instanceof UserInterface ? $author->getDisplayName() : (string) $this->t('Unknown sender');
    $isDeleted = $message->isDeleted();
    return [
      'id' => $message->id(),
      'is_own' => $message->getAuthorId() === (int) $viewer->id(),
      'author' => $name,
      'initials' => mb_strtoupper(mb_substr($name, 0, 1)),
      'picture' => $author instanceof UserInterface && $author->hasField('user_picture') && !$author->get('user_picture')->isEmpty()
        ? $author->get('user_picture')->view(['label' => 'hidden'])
        : NULL,
      'date' => $this->dateFormatter->format($message->getCreatedTime(), 'short'),
      'is_edited' => !$isDeleted && $message->isEdited(),
      'is_deleted' => $isDeleted,
      // Plain text only: escape, then keep the line breaks.
      'body' => $isDeleted ? '' : Markup::create(nl2br(Html::escape($message->getBody()))),
      'edit_url' => !$isDeleted && $message->access('update', $viewer)
        ? Url::fromRoute('soda_scs_manager.conversation_message.edit', ['soda_scs_conversation_message' => $message->id()])->toString()
        : '',
      'delete_url' => !$isDeleted && $message->access('delete', $viewer)
        ? Url::fromRoute('soda_scs_manager.conversation_message.delete', ['soda_scs_conversation_message' => $message->id()])->toString()
        : '',
    ];
  }

  /**
   * The bubbles of a conversation, oldest first.
   */
  private function buildMessageList(SodaScsConversationInterface $conversation): array {
    $viewer = $this->currentUser();
    $items = [];
    $versions = [];
    foreach ($this->conversationHelpers->loadMessages($conversation) as $message) {
      $items[] = $this->buildMessageItem($message, $viewer);
      $versions[] = $message->id() . ':' . $message->getChangedTime();
    }
    return [
      '#theme' => 'soda_scs_manager__conversation_messages',
      '#messages' => $items,
      // Changes with a new, edited or deleted message; the refresh compares it.
      '#signature' => hash('crc32b', implode(',', $versions)),
      '#cache' => ['max-age' => 0],
    ];
  }

}
