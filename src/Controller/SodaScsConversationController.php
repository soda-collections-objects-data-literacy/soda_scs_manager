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
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Entity\SodaScsConversationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsConversationMessageInterface;
use Drupal\soda_scs_manager\Form\SodaScsConversationReplyForm;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationBodyFormatter;
use Drupal\soda_scs_manager\Helpers\SodaScsConversationHelpers;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conversation thread and the admin list of conversations.
 */
final class SodaScsConversationController extends ControllerBase {

  /**
   * Conversations per page of the admin list.
   */
  private const TEAM_PAGE_SIZE = 25;

  /**
   * Sortable columns of the admin list and their default direction.
   */
  private const TEAM_SORTS = [
    'user' => 'ASC',
    'label' => 'ASC',
    'lastMessageAt' => 'DESC',
    'teamUnread' => 'DESC',
  ];

  public function __construct(
    private readonly SodaScsConversationHelpers $conversationHelpers,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly PagerManagerInterface $pagerManager,
    private readonly RendererInterface $renderer,
    private readonly RequestStack $requestStack,
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
      $container->get('request_stack'),
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

    $filters = $this->teamFilters();
    [$sort, $direction] = $this->teamSort();
    $total = $this->conversationHelpers->countForTeam($filters);
    $pager = $this->pagerManager->createPager($total, self::TEAM_PAGE_SIZE);
    $conversations = $this->conversationHelpers->loadForTeam(
      self::TEAM_PAGE_SIZE,
      $pager->getCurrentPage() * self::TEAM_PAGE_SIZE,
      $filters,
      $sort,
      $direction,
    );

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
          $conversation->isUnreadForTeam() ? $this->t('New reply') : $this->t('Read'),
          [
            'data' => [
              '#type' => 'operations',
              '#links' => [
                'view' => [
                  'title' => $this->t('View'),
                  'url' => $conversation->toUrl(),
                ],
                'delete' => [
                  'title' => $this->t('Delete'),
                  'url' => Url::fromRoute('soda_scs_manager.conversation.delete', [
                    'soda_scs_conversation' => $conversation->id(),
                  ]),
                ],
              ],
            ],
          ],
        ],
        'class' => $conversation->isUnreadForTeam() ? ['scs-manager--messages-row--unread'] : [],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['scs-manager--conversation-team']],
      'new' => [
        '#type' => 'link',
        '#title' => $this->t('New message'),
        '#url' => Url::fromRoute('soda_scs_manager.conversations.start'),
        '#attributes' => ['class' => ['button', 'button--primary', 'scs-manager--conversation-team__new']],
      ],
      'filters' => $this->buildTeamFilterForm($filters),
      'table' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['soda-scs-table-list-wrapper']],
        'list' => [
          '#type' => 'table',
          '#header' => [
            'user' => $this->buildSortableHeader('user', $this->t('User'), $sort, $direction),
            'label' => $this->buildSortableHeader('label', $this->t('Subject'), $sort, $direction),
            'lastMessageAt' => $this->buildSortableHeader('lastMessageAt', $this->t('Last activity'), $sort, $direction),
            'teamUnread' => $this->buildSortableHeader('teamUnread', $this->t('Status'), $sort, $direction),
            'operations' => $this->t('Operations'),
          ],
          '#rows' => $rows,
          '#empty' => $this->t('No conversations yet.'),
          '#attributes' => ['class' => ['soda-scs-table-list']],
        ],
      ],
      'pager' => ['#type' => 'pager'],
      '#attached' => ['library' => ['soda_scs_manager/globalStyling', 'soda_scs_manager/table-list']],
      '#cache' => [
        'contexts' => ['url.query_args', 'user.permissions'],
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
      // Escaped text with Markdown/bare http(s) links and line breaks.
      'body' => $isDeleted ? '' : Markup::create(SodaScsConversationBodyFormatter::toHtml($message->getBody())),
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

  /**
   * Sortable header cell for the admin conversation table.
   */
  private function buildSortableHeader(string $field, TranslatableMarkup $label, string $currentSort, string $currentDirection): array {
    $request = $this->requestStack->getCurrentRequest();
    $query = $request !== NULL ? $request->query->all() : [];
    unset($query['page']);

    $isActive = $currentSort === $field;
    if ($isActive && $currentDirection === 'ASC') {
      $query['sort'] = $field;
      $query['order'] = 'desc';
    }
    elseif ($isActive) {
      unset($query['sort'], $query['order']);
    }
    else {
      $query['sort'] = $field;
      $query['order'] = strtolower(self::TEAM_SORTS[$field] ?? 'asc');
    }

    $linkClasses = ['sortable-header'];
    $thClasses = [];
    if ($isActive) {
      $thClasses[] = 'is-active';
      $linkClasses[] = 'is-active';
      $linkClasses[] = 'sort-' . strtolower($currentDirection);
    }

    return [
      'data' => [
        '#type' => 'link',
        '#title' => $label,
        '#url' => Url::fromRoute('soda_scs_manager.conversations.team', [], ['query' => $query]),
        '#attributes' => [
          'class' => $linkClasses,
          'title' => $this->t('Sort by @field', ['@field' => $label]),
        ],
      ],
      'class' => $thClasses,
    ];
  }

  /**
   * GET filter form above the admin conversation table.
   *
   * @param array{subject: string, user: string, status: string} $filters
   *   Current filter values.
   */
  private function buildTeamFilterForm(array $filters): array {
    $hasFilters = $filters['subject'] !== '' || $filters['user'] !== '' || $filters['status'] !== '';
    $action = Url::fromRoute('soda_scs_manager.conversations.team')->toString();

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['soda-scs-component-filters', 'scs-manager--conversation-team__filters']],
      'form' => [
        '#type' => 'html_tag',
        '#tag' => 'form',
        '#attributes' => [
          'method' => 'get',
          'action' => $action,
          'class' => ['soda-scs-filters-form'],
          'accept-charset' => 'UTF-8',
        ],
        'details' => [
          '#type' => 'html_tag',
          '#tag' => 'details',
          '#attributes' => $hasFilters ? ['open' => 'open', 'class' => ['soda-scs-filter-details']] : ['class' => ['soda-scs-filter-details']],
          'summary' => [
            '#type' => 'html_tag',
            '#tag' => 'summary',
            '#value' => $this->t('Filters'),
            '#attributes' => ['class' => ['filter-summary']],
          ],
          'fields' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['filter-fields']],
            'user' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['form-item']],
              'label' => [
                '#type' => 'html_tag',
                '#tag' => 'label',
                '#attributes' => ['for' => 'scs-conversation-filter-user'],
                '#value' => $this->t('User'),
              ],
              'input' => [
                '#type' => 'html_tag',
                '#tag' => 'input',
                '#attributes' => [
                  'type' => 'text',
                  'id' => 'scs-conversation-filter-user',
                  'name' => 'user',
                  'value' => $filters['user'],
                  'class' => ['form-text'],
                  'placeholder' => (string) $this->t('Contains…'),
                ],
              ],
            ],
            'subject' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['form-item']],
              'label' => [
                '#type' => 'html_tag',
                '#tag' => 'label',
                '#attributes' => ['for' => 'scs-conversation-filter-subject'],
                '#value' => $this->t('Subject'),
              ],
              'input' => [
                '#type' => 'html_tag',
                '#tag' => 'input',
                '#attributes' => [
                  'type' => 'text',
                  'id' => 'scs-conversation-filter-subject',
                  'name' => 'subject',
                  'value' => $filters['subject'],
                  'class' => ['form-text'],
                  'placeholder' => (string) $this->t('Contains…'),
                ],
              ],
            ],
            'status' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['form-item']],
              'label' => [
                '#type' => 'html_tag',
                '#tag' => 'label',
                '#attributes' => ['for' => 'scs-conversation-filter-status'],
                '#value' => $this->t('Status'),
              ],
              'select' => [
                '#markup' => Markup::create($this->statusFilterSelect($filters['status'])),
              ],
            ],
          ],
          'actions' => [
            '#type' => 'container',
            '#attributes' => ['class' => ['form-actions']],
            'submit' => [
              '#type' => 'html_tag',
              '#tag' => 'button',
              '#value' => $this->t('Apply'),
              '#attributes' => [
                'type' => 'submit',
                'class' => ['form-submit', 'button', 'button--primary'],
              ],
            ],
            'reset' => [
              '#type' => 'link',
              '#title' => $this->t('Reset'),
              '#url' => Url::fromRoute('soda_scs_manager.conversations.team'),
              '#attributes' => ['class' => ['button']],
            ],
          ],
        ],
      ],
    ];
  }

  /**
   * HTML for the status select of the filter form.
   */
  private function statusFilterSelect(string $current): string {
    $options = [
      '' => (string) $this->t('- Any -'),
      'unread' => (string) $this->t('New reply'),
      'read' => (string) $this->t('Read'),
    ];
    $html = '<select id="scs-conversation-filter-status" name="status" class="form-select">';
    foreach ($options as $value => $label) {
      $selected = $current === $value ? ' selected="selected"' : '';
      $html .= '<option value="' . Html::escape($value) . '"' . $selected . '>' . Html::escape($label) . '</option>';
    }
    return $html . '</select>';
  }

  /**
   * Filter values from the current request.
   *
   * @return array{subject: string, user: string, status: string}
   *   Sanitized filters.
   */
  private function teamFilters(): array {
    $request = $this->requestStack->getCurrentRequest();
    $status = (string) ($request?->query->get('status') ?? '');
    if (!in_array($status, ['', 'unread', 'read'], TRUE)) {
      $status = '';
    }
    return [
      'subject' => trim((string) ($request?->query->get('subject') ?? '')),
      'user' => trim((string) ($request?->query->get('user') ?? '')),
      'status' => $status,
    ];
  }

  /**
   * Active sort field and direction for the admin list.
   *
   * @return array{0: string, 1: string}
   *   Field name and ASC|DESC.
   */
  private function teamSort(): array {
    $request = $this->requestStack->getCurrentRequest();
    $sort = (string) ($request?->query->get('sort') ?? 'lastMessageAt');
    if (!isset(self::TEAM_SORTS[$sort])) {
      $sort = 'lastMessageAt';
    }
    $order = strtolower((string) ($request?->query->get('order') ?? ''));
    if ($order !== 'asc' && $order !== 'desc') {
      $order = strtolower(self::TEAM_SORTS[$sort]);
    }
    return [$sort, strtoupper($order)];
  }

}
