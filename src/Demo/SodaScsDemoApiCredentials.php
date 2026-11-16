<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Demo;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStack;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsJupyterHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers;
use Drupal\soda_scs_manager\Helpers\SodaScsSnapshotHelpers;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Gives each demo WissKI its own API password.
 *
 * The template database usually contains the source account api-import, so a
 * plain restore would leave every demo with the same password. After the
 * restore this creates the account if the template lacks it, sets a new random
 * password and writes it to the project notebook's
 * private home (~/.wisski-demo-api.env, mode 0600). The Team Folder and the
 * template never receive it, and it is not written to the log.
 */
class SodaScsDemoApiCredentials {

  /**
   * Local Drupal account the demo notebook uses for HTTP Basic auth.
   */
  public const USERNAME = 'api-import';

  /**
   * Roles a newly created API account gets; missing roles are skipped.
   *
   * An account that comes with the template keeps its roles.
   */
  public const ROLES = ['wisski_user', 'wisski_api_import'];

  /**
   * Line the WissKI script prints when it had to create the account.
   */
  protected const ACCOUNT_CREATED_MARKER = 'account created';

  /**
   * File in the Jupyter home. Excluded from template exports.
   */
  public const CREDENTIALS_FILE = '.wisski-demo-api.env';

  /**
   * Owner of the Jupyter home (jovyan).
   */
  protected const FILE_OWNER_UID = 1000;

  /**
   * Group of the Jupyter home (users).
   */
  protected const FILE_OWNER_GID = 100;

  /**
   * Constructs the credentials writer.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    protected SodaScsContainerHelpers $containerHelpers,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'logger.channel.soda_scs_manager')]
    protected LoggerInterface $logger,
    #[Autowire(service: 'soda_scs_manager.demo.project_apps')]
    protected SodaScsDemoProjectApps $projectApps,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    protected SodaScsServiceHelpers $serviceHelpers,
    #[Autowire(service: 'soda_scs_manager.snapshot.helpers')]
    protected SodaScsSnapshotHelpers $snapshotHelpers,
  ) {}

  /**
   * Sets a fresh API password and stores it in the project notebook home.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  public function install(SodaScsProjectInterface $project, SodaScsStackInterface $wisskiStack): void {
    if (!$wisskiStack instanceof SodaScsStack) {
      throw new SodaScsDemoException(sprintf('WissKI stack %s is not available.', $wisskiStack->id()));
    }
    $urls = $this->serviceHelpers->getStackServiceAndLoginUrls($wisskiStack) ?? [];
    $baseUrl = rtrim((string) ($urls['url'] ?? ''), '/');
    if ($baseUrl === '') {
      throw new SodaScsDemoException(sprintf('WissKI stack %s has no public URL.', $wisskiStack->id()));
    }

    $password = $this->generatePassword();
    $this->setApiPassword($password, $project, $this->wisskiComponent($wisskiStack));
    $this->writeCredentialsFile($project, $this->credentialsFileContents($baseUrl, $password));
    $this->logger->info('Set a new WissKI API password for demo project @project.', [
      '@project' => $project->id(),
    ]);
  }

  /**
   * Contents of the private credentials file.
   */
  protected function credentialsFileContents(string $baseUrl, string $password): string {
    return 'WISSKI_USER=' . self::USERNAME . "\n"
      . 'WISSKI_PASSWORD=' . $password . "\n"
      . 'WISSKI_BASE_URL=' . $baseUrl . "\n";
  }

  /**
   * Password that is safe as a single env-file value.
   */
  protected function generatePassword(): string {
    return rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
  }

  /**
   * Replaces the password of the API account inside the WissKI.
   *
   * The password travels as an environment variable of the exec, not as a
   * command argument. The script prints only the created marker, never the
   * password.
   *
   * @param string $password
   *   The new password.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsProjectInterface $project
   *   The demo project, named in the log when the account had to be created.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $wisskiComponent
   *   The demo WissKI component.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function setApiPassword(string $password, SodaScsProjectInterface $project, SodaScsComponentInterface $wisskiComponent): void {
    $script = <<<'PHP'
$username = getenv('SCS_DEMO_API_USER') ?: '';
$password = getenv('SCS_DEMO_API_PASSWORD') ?: '';
if ($username === '' || $password === '') {
  fwrite(STDERR, "credentials missing\n");
  exit(1);
}
$roles = array_filter(explode(',', getenv('SCS_DEMO_API_ROLES') ?: ''));
$storage = \Drupal::entityTypeManager()->getStorage('user');
$accounts = $storage->loadByProperties(['name' => $username]);
$account = reset($accounts);
$created = FALSE;
if (!$account) {
  $account = $storage->create(['name' => $username, 'status' => 1]);
  $existingRoles = \Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple($roles);
  foreach (array_keys($existingRoles) as $role) {
    $account->addRole($role);
  }
  $created = TRUE;
}
$account->setPassword($password);
$account->save();
if ($created) {
  print "account created\n";
}
PHP;
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['drush', 'php:eval', $script],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'env' => [
        'SCS_DEMO_API_USER=' . self::USERNAME,
        'SCS_DEMO_API_PASSWORD=' . $password,
        'SCS_DEMO_API_ROLES=' . implode(',', self::ROLES),
      ],
      'timeout' => 120,
      'user' => 'www-data',
    ]);
    if (!$result->success) {
      // The script's stderr holds no password: it only travels in the env.
      throw new SodaScsDemoException(sprintf(
        'Could not set the API password in WissKI %s for the account %s: %s',
        $wisskiComponent->id(),
        self::USERNAME,
        trim((string) $result->error) ?: 'no output',
      ));
    }
    if (str_contains((string) ($result->data['output'] ?? ''), self::ACCOUNT_CREATED_MARKER)) {
      $this->logger->warning('The demo template @template lacks the account @account, so it was created in WissKI @component of demo project @project. Add the account to the source WissKI of the template and build a new template version.', [
        '@account' => self::USERNAME,
        '@component' => $wisskiComponent->id(),
        '@project' => $project->id(),
        '@template' => $project->get('demoTemplate')->target_id ?? '-',
      ]);
    }
  }

  /**
   * Directory for the short-lived secret file, outside any template version.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function stagingDirectory(): string {
    $directory = $this->snapshotHelpers->getSnapshotFilesystemRoot() . '/.demo-credential-staging';
    if (!is_dir($directory) && !@mkdir($directory, 0700, TRUE) && !is_dir($directory)) {
      throw new SodaScsDemoException('Could not create the credentials staging directory.');
    }
    return $directory;
  }

  /**
   * WissKI component of a stack.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function wisskiComponent(SodaScsStackInterface $stack): SodaScsComponentInterface {
    $component = $this->projectApps->stackComponent($stack, SodaScsDemoProjectApps::WISSKI_COMPONENT_BUNDLE);
    if ($component === NULL) {
      throw new SodaScsDemoException(sprintf('WissKI stack %s has no WissKI component.', $stack->id()));
    }
    return $component;
  }

  /**
   * Copies the credentials file into the project notebook volume.
   *
   * The secret is a mode-0600 file on the snapshot bind, mounted read-only
   * into a helper container. The container command does not contain it.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function writeCredentialsFile(SodaScsProjectInterface $project, string $contents): void {
    $stagingFile = $this->stagingDirectory() . '/' . bin2hex(random_bytes(16));
    if (file_put_contents($stagingFile, $contents) === FALSE) {
      throw new SodaScsDemoException(sprintf('Could not stage the API credentials for project %s.', $project->id()));
    }
    @chmod($stagingFile, 0600);

    $volumeName = SodaScsJupyterHelpers::projectVolumeName($project);
    $hostFile = $this->snapshotHelpers->convertContainerPathToHostPath($stagingFile);
    try {
      $result = $this->containerHelpers->runShortLivedContainer(
        'demo-api-credentials--' . $project->id() . '__' . $this->snapshotHelpers->generateRandomSuffix(),
        [
          'sh',
          '-c',
          'install -o ' . self::FILE_OWNER_UID . ' -g ' . self::FILE_OWNER_GID . ' -m 600 /secret/credentials /volume/' . self::CREDENTIALS_FILE,
        ],
        [
          $volumeName . ':/volume',
          $hostFile . ':/secret/credentials:ro',
        ],
        120,
      );
      if (!$result->success) {
        throw new SodaScsDemoException(sprintf('Could not write the API credentials into the notebook home of project %s.', $project->id()));
      }
    }
    finally {
      @unlink($stagingFile);
    }
  }

}
