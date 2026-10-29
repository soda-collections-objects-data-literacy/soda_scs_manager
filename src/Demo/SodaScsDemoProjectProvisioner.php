<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\ComponentActions\SodaScsComponentActionsInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsDatabaseHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsTriplestoreHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsVolumeArchiveHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsWebprotegeHelpers;
use Drupal\soda_scs_manager\StackActions\SodaScsStackActionsInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Brings a user's demo project to the state of a demo template.
 *
 * apply() is idempotent: it creates only the applications that are missing
 * and then overwrites the data of all of them. First creation and reset are
 * therefore the same run.
 */
class SodaScsDemoProjectProvisioner {

  use StringTranslationTrait;

  /**
   * How many machine name variants to try before giving up.
   */
  protected const MACHINE_NAME_ATTEMPTS = 20;

  /**
   * Constructs the provisioner.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.demo.api_credentials')]
    protected SodaScsDemoApiCredentials $apiCredentials,
    #[Autowire(service: 'soda_scs_manager.component.actions')]
    protected SodaScsComponentActionsInterface $componentActions,
    #[Autowire(service: 'soda_scs_manager.database.helpers')]
    protected SodaScsDatabaseHelpers $databaseHelpers,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'soda_scs_manager.demo.label_builder')]
    protected SodaScsDemoLabelBuilder $labelBuilder,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
    #[Autowire(service: 'soda_scs_manager.stack.actions')]
    protected SodaScsStackActionsInterface $stackActions,
    #[Autowire(service: 'soda_scs_manager.demo.team_folder_copier')]
    protected SodaScsTeamFolderCopier $teamFolderCopier,
    #[Autowire(service: 'soda_scs_manager.triplestore_helpers')]
    protected SodaScsTriplestoreHelpers $triplestoreHelpers,
    #[Autowire(service: 'soda_scs_manager.volume_archive.helpers')]
    protected SodaScsVolumeArchiveHelpers $volumeArchiveHelpers,
    #[Autowire(service: 'soda_scs_manager.webprotege.helpers')]
    protected SodaScsWebprotegeHelpers $webprotegeHelpers,
    #[Autowire(service: 'soda_scs_manager.demo.wisski_transfer')]
    protected SodaScsDemoWisskiTransfer $wisskiTransfer,
  ) {}

  /**
   * Creates missing applications and loads the template data into all.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The user's demo project.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsDemoTemplateInterface $template
   *   A ready template.
   * @param callable|null $reportStep
   *   Receives a translated progress message per step.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoNotReadyException
   *   While a new WissKI is still installing; nothing was overwritten yet.
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   *   When a step fails.
   */
  public function apply(SodaScsProjectInterface $project, SodaScsDemoTemplateInterface $template, ?callable $reportStep = NULL): void {
    $reportStep ??= static function (string $message): void {};
    if (!$template->isReady()) {
      throw new SodaScsDemoException(sprintf('Demo template v%s is not ready.', $template->getVersion()));
    }
    $manifest = $template->getManifest();
    $directory = (string) $template->getArtifactDirectory();
    $this->verifyChecksums($manifest, $directory);

    $owner = $project->getOwner();
    if (!$owner instanceof UserInterface) {
      throw new SodaScsDemoException(sprintf('Demo project %s has no owner.', $project->id()));
    }
    $userName = (string) $owner->getAccountName();
    $apps = $manifest['apps'] ?? [];

    $reportStep((string) $this->t('Creating missing applications'));
    $wisskiStack = isset($apps[SodaScsDemoLabelBuilder::APP_WISSKI]) ? $this->ensureWisskiStack($project, $userName) : NULL;
    if (isset($apps[SodaScsDemoLabelBuilder::APP_JUPYTER])) {
      $this->ensureJupyterStack($project, $userName);
    }
    $databases = $this->ensureStandaloneComponents($project, $userName, SodaScsDemoLabelBuilder::APP_DATABASE, count($apps[SodaScsDemoLabelBuilder::APP_DATABASE] ?? []));
    $triplestores = $this->ensureStandaloneComponents($project, $userName, SodaScsDemoLabelBuilder::APP_TRIPLESTORE, count($apps[SodaScsDemoLabelBuilder::APP_TRIPLESTORE] ?? []));
    $hadOntology = trim((string) ($project->get('webprotegeProjectId')->value ?? '')) !== '';
    if (isset($apps[SodaScsDemoLabelBuilder::APP_WEBPROTEGE])) {
      $this->ensureWebprotegeComponent($project, $userName);
    }

    if ($wisskiStack !== NULL && !$this->wisskiTransfer->isInstallComplete($wisskiStack)) {
      $reportStep((string) $this->t('Waiting for the WissKI installation to finish'));
      throw new SodaScsDemoNotReadyException(sprintf('The WissKI of demo project %s is still being installed.', $project->id()));
    }

    $reportStep((string) $this->t('Copying files into the Team Folder'));
    $this->importTeamFolder($project, $manifest, $directory);

    if (isset($apps[SodaScsDemoLabelBuilder::APP_JUPYTER])) {
      $reportStep((string) $this->t('Restoring the Jupyter home directory'));
      SodaScsDemoException::assertSuccess($this->jupyterHelpers->stopProjectServerAndWait($project), 'Stop Jupyter server');
      SodaScsDemoException::assertSuccess(
        $this->volumeArchiveHelpers->importVolume(SodaScsJupyterHelpers::projectVolumeName($project), $directory, (string) $apps[SodaScsDemoLabelBuilder::APP_JUPYTER]['file']),
        'Import Jupyter home',
      );
    }

    $reportStep((string) $this->t('Restoring the databases'));
    foreach ($apps[SodaScsDemoLabelBuilder::APP_DATABASE] ?? [] as $position => $entry) {
      SodaScsDemoException::assertSuccess(
        $this->databaseHelpers->importDatabaseFromFile($this->machineName($databases[$position]), $directory . '/' . $entry['file'], TRUE),
        'Import database ' . $databases[$position]->id(),
      );
    }

    $reportStep((string) $this->t('Restoring the triplestores'));
    foreach ($apps[SodaScsDemoLabelBuilder::APP_TRIPLESTORE] ?? [] as $position => $entry) {
      $this->importTriplestore($triplestores[$position], $directory . '/' . $entry['file']);
    }

    if (isset($apps[SodaScsDemoLabelBuilder::APP_WEBPROTEGE])) {
      $reportStep((string) $this->t('Importing the ontology'));
      $this->importOntology($project, $hadOntology, $directory . '/' . $apps[SodaScsDemoLabelBuilder::APP_WEBPROTEGE]['file'], (string) $template->getVersion());
    }

    if ($wisskiStack !== NULL) {
      $reportStep((string) $this->t('Restoring the WissKI'));
      $this->wisskiTransfer->import(
        $wisskiStack,
        $directory . '/' . $apps[SodaScsDemoLabelBuilder::APP_WISSKI]['directory'],
        (string) $wisskiStack->label(),
      );
    }

    if ($wisskiStack !== NULL && isset($apps[SodaScsDemoLabelBuilder::APP_JUPYTER])) {
      $reportStep((string) $this->t('Setting the WissKI API password'));
      $this->apiCredentials->install($project, $wisskiStack);
    }

    $project->set('demoTemplate', $template->id());
    $project->set('demoStatus', SodaScsDemoProjectStatus::Ready->value);
    $project->save();
    $reportStep((string) $this->t('The demo project is ready.'));
  }

  /**
   * Throws when a stack or component action reports a failure.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function assertActionSucceeded(array $result, string $step): void {
    if (!empty($result['success'])) {
      return;
    }
    $error = $result['error'] ?? $result['message'] ?? 'unknown error';
    if ($error instanceof \Throwable) {
      $error = $error->getMessage();
    }
    throw new SodaScsDemoException(sprintf('%s: %s', $step, (string) $error));
  }

  /**
   * Creates the Jupyter stack of the demo project unless it exists.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function ensureJupyterStack(SodaScsProjectInterface $project, string $userName): SodaScsStackInterface {
    $existing = $this->projectApps->jupyterStack($project);
    if ($existing !== NULL) {
      return $existing;
    }
    $stack = $this->newStack(
      SodaScsDemoProjectApps::JUPYTER_STACK_BUNDLE,
      $this->labelBuilder->appLabel(SodaScsDemoLabelBuilder::APP_JUPYTER, $userName),
      $this->freeMachineName(SodaScsDemoLabelBuilder::APP_JUPYTER, $userName, 1, ['%s'], []),
      $project,
    );
    $this->assertActionSucceeded($this->stackActions->createStack($stack), 'Create Jupyter stack');
    return $stack;
  }

  /**
   * Creates standalone SQL or triplestore components until there are enough.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface[]
   *   The first $count components of that kind, in project order.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function ensureStandaloneComponents(SodaScsProjectInterface $project, string $userName, string $appKey, int $count): array {
    $isDatabase = $appKey === SodaScsDemoLabelBuilder::APP_DATABASE;
    $bundle = $isDatabase ? SodaScsDemoProjectApps::SQL_COMPONENT_BUNDLE : SodaScsDemoProjectApps::TRIPLESTORE_COMPONENT_BUNDLE;
    $prefix = $isDatabase ? 'sql-' : 'ts-';
    $resultKey = $isDatabase ? 'sqlComponent' : 'triplestoreComponent';

    $components = $this->projectApps->standaloneComponents($project, $bundle);
    for ($index = count($components) + 1; $index <= $count; $index++) {
      $component = $this->entityTypeManager->getStorage('soda_scs_component')->create([
        'bundle' => $bundle,
        'health' => 'Unknown',
        'label' => $this->labelBuilder->appLabel($appKey, $userName, $index),
        'machineName' => $this->freeMachineName($appKey, $userName, $index, [], [$prefix . '%s', '%s']),
        'owner' => $project->getOwnerId(),
        'partOfProjects' => [$project->id()],
      ]);
      $result = $this->componentActions->createComponent($component);
      $this->assertActionSucceeded($result, 'Create ' . $appKey);
      $created = $result['data'][$resultKey] ?? NULL;
      if (!$created instanceof SodaScsComponentInterface) {
        throw new SodaScsDemoException(sprintf('Create %s: no component returned.', $appKey));
      }
      $components[] = $created;
    }
    return array_slice($components, 0, $count);
  }

  /**
   * Creates the WebProtégé component unless the project already has one.
   *
   * Creating the component also creates the empty ontology project.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function ensureWebprotegeComponent(SodaScsProjectInterface $project, string $userName): SodaScsComponentInterface {
    $existing = $this->projectApps->webprotegeComponent($project);
    if ($existing !== NULL) {
      return $existing;
    }
    $component = $this->entityTypeManager->getStorage('soda_scs_component')->create([
      'bundle' => SodaScsDemoProjectApps::WEBPROTEGE_COMPONENT_BUNDLE,
      'health' => 'Unknown',
      'label' => $this->labelBuilder->appLabel(SodaScsDemoLabelBuilder::APP_WEBPROTEGE, $userName),
      'machineName' => $this->freeMachineName(SodaScsDemoLabelBuilder::APP_WEBPROTEGE, $userName, 1, [], ['%s']),
      'owner' => $project->getOwnerId(),
      'partOfProjects' => [$project->id()],
    ]);
    $result = $this->componentActions->createComponent($component);
    $this->assertActionSucceeded($result, 'Create WebProtégé');
    $created = $result['data']['component'] ?? NULL;
    if (!$created instanceof SodaScsComponentInterface) {
      throw new SodaScsDemoException('Create WebProtégé: no component returned.');
    }
    return $created;
  }

  /**
   * Creates the WissKI stack of the demo project unless it exists.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function ensureWisskiStack(SodaScsProjectInterface $project, string $userName): SodaScsStackInterface {
    $existing = $this->projectApps->wisskiStack($project);
    if ($existing !== NULL) {
      return $existing;
    }
    $stack = $this->newStack(
      SodaScsDemoProjectApps::WISSKI_STACK_BUNDLE,
      $this->labelBuilder->appLabel(SodaScsDemoLabelBuilder::APP_WISSKI, $userName),
      $this->freeMachineName(SodaScsDemoLabelBuilder::APP_WISSKI, $userName, 1, ['stack-%s', '%s'], ['sql-%s', 'ts-%s', 'wisski-%s', '%s']),
      $project,
    );
    $stack->set('defaultLanguage', 'en');
    $stack->set('developmentInstance', FALSE);
    $this->assertActionSucceeded($this->stackActions->createStack($stack), 'Create WissKI stack');
    return $stack;
  }

  /**
   * First machine name base whose derived stack and component names are free.
   *
   * @param string $appKey
   *   One of the SodaScsDemoLabelBuilder::APP_* constants.
   * @param string $userName
   *   Account name of the owner.
   * @param int $index
   *   1-based position among applications of the same kind.
   * @param string[] $stackPatterns
   *   sprintf patterns of stack machine names derived from the base.
   * @param string[] $componentPatterns
   *   sprintf patterns of component machine names derived from the base.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function freeMachineName(string $appKey, string $userName, int $index, array $stackPatterns, array $componentPatterns): string {
    for ($attempt = 1; $attempt <= self::MACHINE_NAME_ATTEMPTS; $attempt++) {
      $base = $this->labelBuilder->appMachineName($appKey, $userName, $index, $attempt);
      $isTaken = $this->isMachineNameUsed('soda_scs_stack', $stackPatterns, $base)
        || $this->isMachineNameUsed('soda_scs_component', $componentPatterns, $base);
      if (!$isTaken) {
        return $base;
      }
    }
    throw new SodaScsDemoException(sprintf('No free machine name for %s of %s.', $appKey, $userName));
  }

  /**
   * Loads the demo ontology into the project's WebProtégé project.
   *
   * A project that already had an ontology is recreated first: WebProtégé
   * only adds axioms, so a reset would otherwise duplicate them.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function importOntology(SodaScsProjectInterface $project, bool $hadOntology, string $archiveFile, string $version): void {
    if ($hadOntology) {
      SodaScsDemoException::assertSuccess($this->webprotegeHelpers->recreateOntologyProject($project), 'Recreate WebProtégé project');
    }
    SodaScsDemoException::assertSuccess(
      $this->webprotegeHelpers->importOntologyArchive($archiveFile, (string) $this->t('Demo version @version', ['@version' => $version]), $project),
      'Import WebProtégé ontology',
    );
  }

  /**
   * Fills the Team Folder; tolerates a missing mount when there are no files.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function importTeamFolder(SodaScsProjectInterface $project, array $manifest, string $directory): void {
    $teamFolder = $manifest['teamFolder'] ?? NULL;
    if (!is_array($teamFolder)) {
      return;
    }
    $result = $this->teamFolderCopier->importTeamFolder($project, $directory . '/' . $teamFolder['directory']);
    if (!$result->success && (int) ($teamFolder['fileCount'] ?? 0) > 0) {
      SodaScsDemoException::assertSuccess($result, 'Import Team Folder');
    }
  }

  /**
   * Replaces the content of a standalone triplestore.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function importTriplestore(SodaScsComponentInterface $component, string $file): void {
    try {
      $result = $this->triplestoreHelpers->importTriplestoreRepository($this->machineName($component), $file, 'nq');
    }
    catch (\Throwable $e) {
      throw new SodaScsDemoException(sprintf('Import triplestore %s: %s', $component->id(), $e->getMessage()), 0, $e);
    }
    SodaScsDemoException::assertSuccess($result, 'Import triplestore ' . $component->id());
  }

  /**
   * Whether any entity uses a machine name derived from the base.
   */
  protected function isMachineNameUsed(string $entityTypeId, array $patterns, string $base): bool {
    if ($patterns === []) {
      return FALSE;
    }
    $names = array_map(static fn (string $pattern): string => sprintf($pattern, $base), $patterns);
    return $this->entityTypeManager->getStorage($entityTypeId)->getQuery()
      ->accessCheck(FALSE)
      ->condition('machineName', $names, 'IN')
      ->range(0, 1)
      ->execute() !== [];
  }

  /**
   * Machine name of a component (database name, repository id).
   */
  protected function machineName(SodaScsComponentInterface $component): string {
    return (string) $component->get('machineName')->value;
  }

  /**
   * Builds an unsaved stack that belongs to the demo project.
   */
  protected function newStack(string $bundle, string $label, string $machineName, SodaScsProjectInterface $project): SodaScsStackInterface {
    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    $stack = $this->entityTypeManager->getStorage('soda_scs_stack')->create([
      'bundle' => $bundle,
      'health' => 'Unknown',
      'label' => $label,
      'machineName' => $machineName,
      'owner' => $project->getOwnerId(),
      'partOfProjects' => [$project->id()],
    ]);
    return $stack;
  }

  /**
   * Compares the template files with the checksums in the manifest.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function verifyChecksums(array $manifest, string $directory): void {
    foreach ($manifest['checksums'] ?? [] as $relativePath => $expected) {
      $path = $directory . '/' . $relativePath;
      if (!is_file($path) || hash_file('sha256', $path) !== $expected) {
        throw new SodaScsDemoException(sprintf('Template file %s is missing or damaged.', $relativePath));
      }
    }
  }

}
