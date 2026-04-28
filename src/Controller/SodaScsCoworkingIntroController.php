<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Url;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\soda_scs_manager\Validation\WisskiIntroReservedBaseSlugs;
use Drupal\user\UserDataInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Co-working intro: mark complete and create a WissKI stack from the wizard.
 */
class SodaScsCoworkingIntroController extends ControllerBase {

  public function __construct(
    protected UserDataInterface $userData,
    protected EntityTypeBundleInfoInterface $entityTypeBundleInfo,
    protected SodaScsStackActionsInterface $stackActions,
    protected SodaScsServiceHelpers $serviceHelpers,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('user.data'),
      $container->get('entity_type.bundle.info'),
      $container->get('soda_scs_manager.stack.actions'),
      $container->get('soda_scs_manager.service.helpers'),
    );
  }

  /**
   * Persists completion so the intro modal is not shown again.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function complete(): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new JsonResponse(['error' => 'Unauthorized'], 401);
    }

    $this->userData->set('soda_scs_manager', (int) $account->id(), 'coworking_intro_completed', 1);

    return new JsonResponse(['success' => TRUE]);
  }

  /**
   * Checks whether a label can be used (format + no stack/component machine name collision).
   *
   * Query: label (string). Returns JSON: { "available": bool, "error"?: string }.
   */
  public function checkWisskiName(Request $request): JsonResponse {
    if ($this->currentUser()->isAnonymous()) {
      return new JsonResponse(['available' => FALSE, 'error' => 'Unauthorized'], 401);
    }

    $labelRaw = $request->query->get('label', '');
    if (!is_string($labelRaw)) {
      $labelRaw = '';
    }
    $labelRaw = trim(strip_tags($labelRaw));
    if ($labelRaw === '') {
      return new JsonResponse(['available' => FALSE]);
    }

    $validated = $this->wisskiIntroLabelValidation($labelRaw);
    if (!$validated['ok']) {
      return new JsonResponse([
        'available' => FALSE,
        'error' => $validated['error'],
      ]);
    }

    if ($this->wisskiQuickCreateNameFamilyInUse($validated['machineName'])) {
      return new JsonResponse([
        'available' => FALSE,
        'error' => $this->nameAlreadyUsedMessage(),
      ]);
    }

    return new JsonResponse(['available' => TRUE]);
  }

  /**
   * Creates a WissKI stack (MariaDB + triplestore + WissKI) like the stack add form.
   *
   * CSRF is enforced via route requirement _csrf_request_header_token.
   */
  public function createWisski(Request $request): JsonResponse {
    $account = $this->currentUser();
    if ($account->isAnonymous()) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Unauthorized'], 401);
    }

    $payload = json_decode($request->getContent(), TRUE);
    if (!is_array($payload)) {
      $payload = [];
    }
    $labelRaw = $payload['label'] ?? '';
    if (!is_string($labelRaw)) {
      $labelRaw = '';
    }
    $labelRaw = trim(strip_tags($labelRaw));
    if ($labelRaw === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => (string) $this->t('Please enter a name for your WissKI environment.'),
      ], 400);
    }

    $bundle = 'soda_scs_wisski_stack';
    $bundleInfo = $this->entityTypeBundleInfo->getBundleInfo('soda_scs_stack')[$bundle] ?? NULL;
    if (!$bundleInfo) {
      return new JsonResponse(['success' => FALSE, 'error' => 'Configuration error'], 500);
    }

    $validated = $this->wisskiIntroLabelValidation($labelRaw);
    if (!$validated['ok']) {
      return new JsonResponse(['success' => FALSE, 'error' => $validated['error']], $validated['status']);
    }
    $labelForStack = $validated['labelForStack'];
    $machineName = $validated['machineName'];

    if ($this->wisskiQuickCreateNameFamilyInUse($machineName)) {
      return new JsonResponse([
        'success' => FALSE,
        'error' => $this->nameAlreadyUsedMessage(),
      ], 409);
    }

    $userEntity = $this->entityTypeManager()->getStorage('user')->load($account->id());
    $defaultProject = NULL;
    if ($userEntity instanceof UserInterface && $userEntity->hasField('default_project')) {
      $defaultProject = $userEntity->get('default_project')->target_id ?: NULL;
    }

    $wisskiInstanceSettings = $this->serviceHelpers->initWisskiInstanceSettings();
    $defaultWisskiVersionLabel = (string) ($wisskiInstanceSettings['defaultVersion'] ?? '');
    if ($defaultWisskiVersionLabel === '' || ($wisskiInstanceSettings['wisskiStackProductionVersion'] ?? '') === '') {
      return new JsonResponse([
        'success' => FALSE,
        'error' => (string) $this->t('WissKI versions are not configured for this site. Ask an administrator to set a default WissKI component version under SCS settings.'),
      ], 503);
    }

    $stackStorage = $this->entityTypeManager()->getStorage('soda_scs_stack');
    $stack = $stackStorage->create([
      'bundle' => $bundle,
      'label' => $labelForStack,
      'machineName' => $machineName,
      'owner' => $account->id(),
      'health' => 'Unknown',
      'defaultLanguage' => 'en',
      'developmentInstance' => FALSE,
    ]);
    if ($defaultProject) {
      $stack->set('partOfProjects', [$defaultProject]);
    }

    $bundleLabel = (string) ($bundleInfo['label'] ?? 'WissKI stack');
    $stack->set('label', $stack->get('label')->value . ' (' . $bundleLabel . ')');

    $result = $this->stackActions->createStack($stack);
    if (empty($result['success'])) {
      $this->getLogger('soda_scs_manager')->error('Coworking intro WissKI stack create failed: @msg', [
        '@msg' => $result['message'] ?? $result['error'] ?? 'unknown',
      ]);
      return new JsonResponse([
        'success' => FALSE,
        'error' => (string) ($result['error'] ?? $result['message'] ?? $this->t('Cannot create WissKI stack. See logs for details.')),
      ], 500);
    }

    $this->userData->set('soda_scs_manager', (int) $account->id(), 'coworking_intro_completed', 1);

    $redirectUrl = Url::fromRoute('entity.soda_scs_stack.canonical', [
      'soda_scs_stack' => $stack->id(),
    ], ['absolute' => TRUE])->toString();

    return new JsonResponse([
      'success' => TRUE,
      'redirectUrl' => $redirectUrl,
      'id' => (string) $stack->id(),
      'label' => $stack->label(),
    ]);
  }

  /**
   * User-visible message when the derived name family is already in use.
   */
  private function nameAlreadyUsedMessage(): string {
    return (string) $this->t('A stack or application with this name already exists. Please choose a different name.');
  }

  /**
   * Whether WissKI quick-create would collide with existing stack or component names.
   *
   * A label is turned into a base slug (e.g. "My first WissKI" → "my-first-wisski"). The
   * WissKI stack path then materializes, matching SodaScsWisskiStackActions and
   * SodaScsSqlComponentActions / SodaScsTriplestoreComponentActions / SodaScsWisskiComponentActions:
   * stored machine names are stack-base, sql-base, ts-base, and wisski-base (each with a
   * single prefix: "stack-", "sql-", "ts-", "wisski-").
   *
   * The stack row uses the bare base until createStack() applies the "stack-" prefix, so
   * both bare base and stack-prefixed names are checked for stacks. Any component with the
   * bare base as machineName is a conflict, matching the stack add form rule.
   *
   * @param string $baseSlug
   *   The slug from stackMachineNameFromLabel() (not prefixed).
   */
  private function wisskiQuickCreateNameFamilyInUse(string $baseSlug): bool {
    $stackSlugs = ['stack-' . $baseSlug, $baseSlug];
    $componentSlugs = [
      'sql-' . $baseSlug,
      'ts-' . $baseSlug,
      'wisski-' . $baseSlug,
      $baseSlug,
    ];

    $stackQuery = $this->entityTypeManager()->getStorage('soda_scs_stack')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machineName', $stackSlugs, 'IN')
      ->range(0, 1);
    if (!empty($stackQuery->execute())) {
      return TRUE;
    }

    $componentQuery = $this->entityTypeManager()->getStorage('soda_scs_component')->getQuery()
      ->accessCheck(FALSE)
      ->condition('machineName', $componentSlugs, 'IN')
      ->range(0, 1);
    return !empty($componentQuery->execute());
  }

  /**
   * Validates a WissKI quick-create label; shared by check + create.
   *
   * The machineName value in the success array is the base slug (before stack-/sql-/ts-/wisski-).
   *
   * @return array{ok: TRUE, labelForStack: string, machineName: string}|array{ok: FALSE, error: string, status: int}
   */
  private function wisskiIntroLabelValidation(string $labelRaw): array {
    // Same limit as SodaScsStackCreateForm::validateForm().
    $labelForStack = mb_substr($labelRaw, 0, 25);
    if (!preg_match('/^[A-Za-z0-9 ]+$/u', $labelForStack)) {
      return [
        'ok' => FALSE,
        'error' => (string) $this->t('Use 1–25 characters: letters (A–Z, a–z), digits (0–9), and spaces only. No other symbols.'),
        'status' => 400,
      ];
    }

    $machineName = $this->stackMachineNameFromLabel($labelForStack);
    if ($machineName === '') {
      return [
        'ok' => FALSE,
        'error' => (string) $this->t('Could not derive a valid machine name from that title. Use letters and numbers.'),
        'status' => 400,
      ];
    }

    if (!preg_match('/^[a-z0-9-]+$/', $machineName)) {
      return [
        'ok' => FALSE,
        'error' => (string) $this->t('The machine name can only contain small letters, digits, and minus.'),
        'status' => 400,
      ];
    }

    if (strlen($machineName) > 30) {
      return [
        'ok' => FALSE,
        'error' => (string) $this->t('The machine name must not exceed 30 characters.'),
        'status' => 400,
      ];
    }

    if (WisskiIntroReservedBaseSlugs::isReserved($machineName)) {
      return [
        'ok' => FALSE,
        'error' => (string) $this->t('This name is reserved for system or infrastructure services. Please choose a different name.'),
        'status' => 400,
      ];
    }

    return [
      'ok' => TRUE,
      'labelForStack' => $labelForStack,
      'machineName' => $machineName,
    ];
  }

  /**
   * Machine name for stacks: a-z, 0-9, minus only; max 30 (stack create form).
   */
  private function stackMachineNameFromLabel(string $label): string {
    $lower = mb_strtolower($label);
    $replaced = preg_replace('/[^a-z0-9-]+/', '-', $lower);
    $replaced = preg_replace('/^[^a-z0-9]+/', '', (string) $replaced);
    $replaced = preg_replace('/-+$/', '', (string) $replaced);
    return mb_substr((string) $replaced, 0, 30);
  }

}
