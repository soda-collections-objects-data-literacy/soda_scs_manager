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
 * Form that lists pending project membership requests for the current user.
 */
final class SodaScsProjectMembershipForm extends FormBase {

  /**
   * The date formatter service.
   */
  private DateFormatterInterface $dateFormatter;

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * The current user account proxy.
   */
  private AccountProxyInterface $currentUser;

  /**
   * The membership manager.
   */
  private SodaScsProjectMembershipHelpers $membershipHelpers;

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
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'soda_scs_project_membership_requests_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $requests = $this->loadPendingRequests();

    $form['#attributes']['class'][] = 'soda-scs-manager--membership-requests';

    if (empty($requests)) {
      $form['empty'] = [
        '#type' => 'item',
        '#markup' => '<p class="text-stone-400 text-center py-4">' . $this->t('You have no pending project invitations.') . '</p>',
      ];
      return $form;
    }

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

    foreach ($requests as $request) {
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
    return $requests;
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


