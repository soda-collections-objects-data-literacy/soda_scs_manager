<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\soda_scs_manager\Demo\SodaScsDemoServiceAccount;
use Drupal\soda_scs_manager\Entity\SodaScsProjectInterface;
use Drupal\soda_scs_manager\Service\SodaScsProjectOwnershipTransfer;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Project ownership transfer and the demo service account.
 */
final class SodaScsProjectOwnershipCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    #[Autowire(service: 'soda_scs_manager.demo.service_account')]
    protected SodaScsDemoServiceAccount $demoServiceAccount,
    protected EntityTypeManagerInterface $entityTypeManager,
    #[Autowire(service: 'soda_scs_manager.project_ownership_transfer')]
    protected SodaScsProjectOwnershipTransfer $ownershipTransfer,
  ) {
    parent::__construct();
  }

  /**
   * Creates or updates the account scs_demo in Drupal, Keycloak and Nextcloud.
   *
   * Reads the password from SCS_DEMO_ACCOUNT_PASSWORD (and the optional
   * e-mail address from SCS_DEMO_ACCOUNT_EMAIL) in the container environment.
   */
  #[CLI\Command(name: 'soda_scs_manager:demo-account', aliases: ['scs-demo-account'])]
  #[CLI\Usage(name: 'drush soda_scs_manager:demo-account', description: 'Bootstrap the demo service account after install or a password change.')]
  public function demoAccount(): int {
    $result = $this->demoServiceAccount->ensure();
    if (!$result->success) {
      $this->logger()->error($result->message);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($result->message);
    return self::EXIT_SUCCESS;
  }

  /**
   * Hands a project and its applications to another account.
   *
   * Running WissKIs of the project are redeployed with the database,
   * triplestore and Nextcloud accounts of the new owner.
   *
   * @param string $projectId
   *   The project entity id.
   * @param string $accountName
   *   The account name of the new owner.
   * @param array{remove-previous-owner: bool} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:project-transfer', aliases: ['scs-project-transfer'])]
  #[CLI\Argument(name: 'projectId', description: 'The project entity id.')]
  #[CLI\Argument(name: 'accountName', description: 'The account name of the new owner.')]
  #[CLI\Option(name: 'remove-previous-owner', description: 'Do not keep the previous owner as a member with the WissKI admin role.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:project-transfer 110 scs_demo', description: 'Hand project 110 to scs_demo; the previous owner stays a member.')]
  public function projectTransfer(string $projectId, string $accountName, array $options = ['remove-previous-owner' => FALSE]): int {
    $project = $this->entityTypeManager->getStorage('soda_scs_project')->load((int) $projectId);
    if (!$project instanceof SodaScsProjectInterface) {
      $this->logger()->error(dt('Project @id does not exist.', ['@id' => $projectId]));
      return self::EXIT_FAILURE;
    }
    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['name' => $accountName]);
    $newOwner = reset($accounts);
    if (!$newOwner instanceof UserInterface) {
      $this->logger()->error(dt('Account @name does not exist.', ['@name' => $accountName]));
      return self::EXIT_FAILURE;
    }
    if (!$this->io()->confirm(dt('Hand project @project to @name? Its WissKIs restart once.', [
      '@name' => $newOwner->getAccountName(),
      '@project' => $project->label(),
    ]))) {
      return self::EXIT_FAILURE;
    }

    $result = $this->ownershipTransfer->transfer($project, $newOwner, empty($options['remove-previous-owner']));
    if (!$result->success) {
      $this->logger()->error($result->message);
      return self::EXIT_FAILURE;
    }
    foreach ($result->data['warnings'] ?? [] as $warning) {
      $this->logger()->warning($warning);
    }
    $this->logger()->success($result->message);
    return self::EXIT_SUCCESS;
  }

}
