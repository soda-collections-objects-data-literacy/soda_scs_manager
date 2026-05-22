<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsProjectMembershipHelpers;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form that lists project membership notifications: pending and handled.
 */
final class SodaScsProjectMembershipForm extends FormBase {

  /**
   * The date formatter service.
   *
   * Protected (not private) so DependencySerializationTrait::__wakeup() in
   * FormBase can reinject services when the form is unserialized from cache.
   */
  protected DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user account proxy.
   */
  protected AccountProxyInterface $currentUser;

  /**
   * The membership manager.
   */
  protected SodaScsProjectMembershipHelpers $membershipHelpers;

  /**
   * Constructs the form.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    DateFormatterInterface $dateFormatter,
    AccountProxyInterface $currentUser,
    SodaScsProjectMembershipHelpers $membershipHelpers,
    MessengerInterface $messenger,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->dateFormatter = $dateFormatter;
    $this->currentUser = $currentUser;
    $this->membershipHelpers = $membershipHelpers;
    $this->messenger = $messenger;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('current_user'),
      $container->get('soda_scs_manager.project_membership.helpers'),
      $container->get('messenger'),
    );
  }

  /**
   * Explicitly register service IDs for DependencySerializationTrait.
   *
   * Drupal caches the form object as part of the form-state cache. When it is
   * deserialized on a subsequent request, DependencySerializationTrait::__wakeup()
   * re-injects services listed in $_serviceIds. The default __sleep() discovers
   * services via ReverseContainer::getId(), which can silently fail for lazy/proxy
   * services, leaving typed non-nullable properties uninitialized after deserialization.
   * By overriding __sleep() we guarantee $_serviceIds is always correct.
   */
  public function __sleep(): array {
    $this->_serviceIds = [
      'entityTypeManager' => 'entity_type.manager',
      'dateFormatter' => 'date.formatter',
      'currentUser' => 'current_user',
      'membershipHelpers' => 'soda_scs_manager.project_membership.helpers',
      'messenger' => 'messenger',
    ];
    $vars = get_object_vars($this);
    foreach (array_keys($this->_serviceIds) as $key) {
      unset($vars[$key]);
    }
    return array_keys($vars);
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_project_membership_requests_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $pending = $this->loadPendingRequests();
    $handled = $this->loadHandledRequests();

    $form['#attributes']['class'][] = 'soda-scs-manager--membership-requests';

    if (empty($pending) && empty($handled)) {
      $form['empty'] = [
        '#type' => 'item',
        '#markup' => '<p class="text-stone-400 text-center py-4">' . $this->t('You have no project invitations.') . '</p>',
      ];
      return $form;
    }

    if (empty($pending)) {
      $form['no_pending'] = [
        '#type' => 'item',
        '#markup' => '<p class="text-stone-500 text-center py-2">' . $this->t('You have no pending project invitations.') . '</p>',
      ];
    }
    else {
      $form['table_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-members-table-wrapper']],
      ];

      $form['table_wrapper']['requests'] = [
        '#type' => 'table',
        '#header' => [
          'project' => $this->t('Project'),
          'requester' => $this->t('Invited by'),
          'created' => $this->t('Sent'),
          'actions' => $this->t('Actions'),
        ],
        '#empty' => $this->t('You have no pending project invitations.'),
        '#attributes' => ['class' => ['project-members-table', 'membership-requests-table']],
      ];

      foreach ($pending as $request) {
        $requestId = $request->id();

        $form['table_wrapper']['requests'][$requestId]['project'] = [
          '#markup' => '<strong>' . $request->getProject()->label() . '</strong>',
        ];

        $form['table_wrapper']['requests'][$requestId]['requester'] = [
          '#markup' => $request->getRequester()->getDisplayName(),
        ];

        $form['table_wrapper']['requests'][$requestId]['created'] = [
          '#markup' => $this->dateFormatter->format($request->getCreatedTime(), 'short'),
        ];

        $form['table_wrapper']['requests'][$requestId]['actions'] = [
          '#type' => 'operations',
          '#links' => [
            'accept' => [
              'title' => $this->t('Accept'),
              'url' => \Drupal\Core\Url::fromRoute('<current>'),
              'attributes' => [
                'class' => ['membership-action-accept'],
                'data-request-id' => $requestId,
                'onclick' => 'event.preventDefault(); this.closest("tr").querySelector(".membership-action-submit-accept").click();',
              ],
            ],
            'reject' => [
              'title' => $this->t('Reject'),
              'url' => \Drupal\Core\Url::fromRoute('<current>'),
              'attributes' => [
                'class' => ['membership-action-reject'],
                'data-request-id' => $requestId,
                'onclick' => 'event.preventDefault(); this.closest("tr").querySelector(".membership-action-submit-reject").click();',
              ],
            ],
          ],
        ];

        // Hidden submit buttons triggered by the operation links.
        $form['table_wrapper']['requests'][$requestId]['hidden_accept'] = [
          '#type' => 'submit',
          '#value' => $this->t('Accept'),
          '#name' => 'accept_request_' . $requestId,
          '#request_id' => $requestId,
          '#submit' => ['::acceptRequest'],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['js-hide', 'membership-action-submit-accept'],
            'style' => 'display:none;',
          ],
        ];

        $form['table_wrapper']['requests'][$requestId]['hidden_reject'] = [
          '#type' => 'submit',
          '#value' => $this->t('Reject'),
          '#name' => 'reject_request_' . $requestId,
          '#request_id' => $requestId,
          '#submit' => ['::rejectRequest'],
          '#limit_validation_errors' => [],
          '#attributes' => [
            'class' => ['js-hide', 'membership-action-submit-reject'],
            'style' => 'display:none;',
          ],
        ];
      }
    }

    if (!empty($handled)) {
      $handledHeadingClasses = [
        'text-lg',
        'font-semibold',
        'text-stone-800',
        'mb-3',
      ];
      if (!empty($pending)) {
        $handledHeadingClasses = array_merge($handledHeadingClasses, [
          'mt-6',
          'pt-2',
          'border-t',
          'border-stone-200',
        ]);
      }

      $form['handled_heading'] = [
        '#type' => 'html_tag',
        '#tag' => 'h2',
        '#value' => $this->t('Handled invitations'),
        '#attributes' => [
          'class' => $handledHeadingClasses,
        ],
        '#weight' => 5,
      ];

      $form['handled_wrapper'] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['project-members-table-wrapper', 'soda-scs-manager--handled-invitations']],
        '#weight' => 6,
      ];

      $form['handled_wrapper']['handled'] = [
        '#type' => 'table',
        '#header' => [
          'project' => $this->t('Project'),
          'requester' => $this->t('Invited by'),
          'created' => $this->t('Sent'),
          'status' => $this->t('Result'),
          'decided' => $this->t('Decided'),
          'actions' => $this->t('Actions'),
        ],
        '#attributes' => [
          'class' => [
            'project-members-table',
            'membership-requests-table',
            'membership-handled-table',
          ],
        ],
      ];

      foreach ($handled as $request) {
        $rid = $request->id();
        $form['handled_wrapper']['handled'][$rid]['project'] = [
          '#markup' => '<strong>' . $request->getProject()->label() . '</strong>',
        ];
        $form['handled_wrapper']['handled'][$rid]['requester'] = [
          '#markup' => $request->getRequester()->getDisplayName(),
        ];
        $form['handled_wrapper']['handled'][$rid]['created'] = [
          '#markup' => $this->dateFormatter->format($request->getCreatedTime(), 'short'),
        ];
        $form['handled_wrapper']['handled'][$rid]['status'] = [
          '#markup' => $this->getHandledStatusLabel($request->getStatus()),
        ];
        $decided = $request->getDecisionTime() ?? $request->getChangedTime();
        $form['handled_wrapper']['handled'][$rid]['decided'] = [
          '#markup' => $this->dateFormatter->format($decided, 'short'),
        ];

        $confirmMessage = (string) $this->t('Remove this invitation from your list?');
        $form['handled_wrapper']['handled'][$rid]['actions'] = [
          '#type' => 'container',
          'operations' => [
            '#type' => 'operations',
            '#links' => [
              'remove' => [
                'title' => $this->t('Remove'),
                'url' => \Drupal\Core\Url::fromRoute('<current>'),
                'attributes' => [
                  'class' => ['membership-handled-remove'],
                  'onclick' => 'event.preventDefault(); if(confirm(' . json_encode($confirmMessage) . ')) { this.closest("td").querySelector(".membership-handled-delete-submit").click(); }',
                ],
              ],
            ],
          ],
          'hidden_delete_handled' => [
            '#type' => 'submit',
            '#value' => $this->t('Remove'),
            '#name' => 'delete_handled_' . $rid,
            '#request_id' => $rid,
            '#submit' => ['::deleteHandledRequest'],
            '#limit_validation_errors' => [],
            '#attributes' => [
              'class' => ['js-hide', 'membership-handled-delete-submit'],
              'style' => 'display:none;',
            ],
          ],
        ];
      }
    }

    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * Accept button submit handler.
   */
  public function acceptRequest(array &$form, FormStateInterface $form_state): void {
    $request = $this->loadRequestFromTrigger($form_state);
    if (!$request) {
      return;
    }

    $actor = $this->loadCurrentUserEntity();
    if (!$actor) {
      return;
    }

    $result = $this->membershipHelpers->approveRequest($request, $actor);
    $this->reportResult($result);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Reject button submit handler.
   */
  public function rejectRequest(array &$form, FormStateInterface $form_state): void {
    $request = $this->loadRequestFromTrigger($form_state);
    if (!$request) {
      return;
    }

    $actor = $this->loadCurrentUserEntity();
    if (!$actor) {
      return;
    }

    $result = $this->membershipHelpers->rejectRequest($request, $actor);
    $this->reportResult($result);
    $form_state->setRebuild(TRUE);
  }

  /**
   * Submit handler: remove a handled invitation record from the recipient's list.
   */
  public function deleteHandledRequest(array &$form, FormStateInterface $form_state): void {
    $request = $this->loadHandledRequestForDeletion($form_state);
    if (!$request) {
      return;
    }

    try {
      $request->delete();
      $this->messenger->addStatus($this->t('The invitation has been removed from your list.'));
    }
    catch (\Throwable) {
      $this->messenger->addError($this->t('Could not remove the invitation. Please try again.'));
    }

    $form_state->setRebuild(TRUE);
  }

  /**
   * Load pending requests for the current user.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[]
   *   The requests keyed by entity ID.
   */
  private function loadPendingRequests(): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $ids = $storage->getQuery()
      ->condition('recipient', $this->currentUser->id())
      ->condition('status', SodaScsProjectMembershipInterface::STATUS_PENDING)
      ->sort('created', 'DESC')
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[] $requests */
    $requests = $storage->loadMultiple($ids);
    return $this->membershipHelpers->purgeOrphanedAndFilterRequests($requests);
  }

  /**
   * Load accepted and rejected membership requests for the current user (as recipient).
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[]
   *   Newest first, limited to 50.
   */
  private function loadHandledRequests(): array {
    $storage = $this->entityTypeManager->getStorage('soda_scs_project_membership');
    $ids = $storage->getQuery()
      ->condition('recipient', $this->currentUser->id())
      ->condition('status', [
        SodaScsProjectMembershipInterface::STATUS_ACCEPTED,
        SodaScsProjectMembershipInterface::STATUS_REJECTED,
      ], 'IN')
      ->sort('decisionTime', 'DESC')
      ->accessCheck(FALSE)
      ->range(0, 50)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface[] $requests */
    $requests = $storage->loadMultiple($ids);
    return $this->membershipHelpers->purgeOrphanedAndFilterRequests($requests);
  }

  /**
   * Translated label for a handled membership request status.
   */
  private function getHandledStatusLabel(string $status): string {
    return match ($status) {
      SodaScsProjectMembershipInterface::STATUS_ACCEPTED => (string) $this->t('Accepted'),
      SodaScsProjectMembershipInterface::STATUS_REJECTED => (string) $this->t('Rejected'),
      default => (string) $this->t('Unknown'),
    };
  }

  /**
   * Load the request entity referenced by the triggering element.
   */
  private function loadRequestFromTrigger(FormStateInterface $form_state): SodaScsProjectMembershipInterface|null {
    $trigger = $form_state->getTriggeringElement();
    $requestId = (int) ($trigger['#request_id'] ?? 0);
    if ($requestId <= 0) {
      return NULL;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface|null $request */
    $request = $this->entityTypeManager
      ->getStorage('soda_scs_project_membership')
      ->load($requestId);

    if (!$request) {
      $this->messenger->addError($this->t('The selected request no longer exists.'));
      return NULL;
    }

    if ((int) $request->getRecipient()->id() !== (int) $this->currentUser->id()) {
      $this->messenger->addError($this->t('You are not allowed to process this request.'));
      return NULL;
    }

    return $request;
  }

  /**
   * Load a handled request entity for deletion (recipient-only, non-pending).
   */
  private function loadHandledRequestForDeletion(FormStateInterface $form_state): SodaScsProjectMembershipInterface|null {
    $trigger = $form_state->getTriggeringElement();
    $requestId = (int) ($trigger['#request_id'] ?? 0);
    if ($requestId <= 0) {
      return NULL;
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsProjectMembershipInterface|null $request */
    $request = $this->entityTypeManager
      ->getStorage('soda_scs_project_membership')
      ->load($requestId);

    if (!$request) {
      $this->messenger->addError($this->t('The selected request no longer exists.'));
      return NULL;
    }

    if ((int) $request->getRecipient()->id() !== (int) $this->currentUser->id()) {
      $this->messenger->addError($this->t('You are not allowed to remove this invitation.'));
      return NULL;
    }

    $status = $request->getStatus();
    if (!in_array($status, [
      SodaScsProjectMembershipInterface::STATUS_ACCEPTED,
      SodaScsProjectMembershipInterface::STATUS_REJECTED,
    ], TRUE)) {
      $this->messenger->addError($this->t('Only handled invitations can be removed.'));
      return NULL;
    }

    return $request;
  }

  /**
   * Load the full user entity for the current account.
   */
  private function loadCurrentUserEntity(): UserInterface|null {
    /** @var \Drupal\user\UserInterface|null $user */
    $user = $this->entityTypeManager
      ->getStorage('user')
      ->load($this->currentUser->id());

    if (!$user) {
      $this->messenger->addError($this->t('Unable to load your user account. Please try again.'));
    }

    return $user;
  }

  /**
   * Post the result to the messenger service.
   */
  private function reportResult(SodaScsResult $result): void {
    if ($result->success) {
      $this->messenger->addStatus($result->message);
    }
    else {
      $this->messenger->addError($result->message);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Submission is handled per-row via dedicated handlers.
  }

}


