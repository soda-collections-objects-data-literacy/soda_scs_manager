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
 * The template database contains the source account api-import, so a plain
 * restore would leave every demo with the same password. After the restore
 * this sets a new random password and writes it to the project notebook's
 * private home (~/.wisski-demo-api.env, mode 0600). The Team Folder and the
 * template never receive it, and it is not written to the log.
 */
class SodaScsDemoApiCredentials {

  /**
   * Local Drupal account the demo notebook uses for HTTP Basic auth.
   */
  public const USERNAME = 'api-import';

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
    $this->setApiPassword($this->wisskiComponent($wisskiStack), $password);
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
   * command argument, and the script prints nothing.
   *
   * @throws \Drupal\soda_scs_manager\Demo\SodaScsDemoException
   */
  protected function setApiPassword(SodaScsComponentInterface $wisskiComponent, string $password): void {
    $script = <<<'PHP'
$username = getenv('SCS_DEMO_API_USER') ?: '';
$password = getenv('SCS_DEMO_API_PASSWORD') ?: '';
if ($username === '' || $password === '') {
  fwrite(STDERR, "credentials missing\n");
  exit(1);
}
$accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $username]);
$account = reset($accounts);
if (!$account) {
  fwrite(STDERR, "user missing\n");
  exit(1);
}
$account->setPassword($password);
$account->save();
PHP;
    $result = $this->containerHelpers->executeDockerExecCommand([
      'cmd' => ['drush', 'php:eval', $script],
      'containerName' => $this->containerHelpers->getComponentContainerName($wisskiComponent),
      'env' => [
        'SCS_DEMO_API_USER=' . self::USERNAME,
        'SCS_DEMO_API_PASSWORD=' . $password,
      ],
      'timeout' => 120,
      'user' => 'www-data',
    ]);
    if (!$result->success) {
      throw new SodaScsDemoException(sprintf(
        'Could not set the API password in WissKI %s. The account %s must exist.',
        $wisskiComponent->id(),
        self::USERNAME,
      ));
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
