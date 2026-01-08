<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Exception\SodaScsComponentActionsException;
use Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Helper class for database related operations.
 */
class SodaScsDatabaseHelpers {

  use StringTranslationTrait;

  /**
   * The container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

  /**
   * The component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected SodaScsComponentHelpers $sodaScsComponentHelpers;

  /**
   * The stack helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers
   */
  protected SodaScsStackHelpers $sodaScsStackHelpers;

  /**
   * The service key actions.
   *
   * @var \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface
   */
  protected SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions;

  /**
   * The service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * Time service.
   *
   * @var \Drupal\Component\Datetime\TimeInterface
   */
  protected TimeInterface $time;

  /**
   * SodaScsDatabaseHelpers constructor.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $sodaScsContainerHelpers
   *   The container helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The component helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsStackHelpers $sodaScsStackHelpers
   *   The stack helpers.
   * @param \Drupal\soda_scs_manager\ServiceKeyActions\SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions
   *   The service key actions.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The service helpers.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $stringTranslation
   *   The string translation service.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.stack.helpers')]
    SodaScsStackHelpers $sodaScsStackHelpers,
    #[Autowire(service: 'soda_scs_manager.service_key.actions')]
    SodaScsServiceKeyActionsInterface $sodaScsServiceKeyActions,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'datetime.time')]
    TimeInterface $time,
    TranslationInterface $stringTranslation,
  ) {
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsStackHelpers = $sodaScsStackHelpers;
    $this->sodaScsServiceKeyActions = $sodaScsServiceKeyActions;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
    $this->time = $time;
    $this->stringTranslation = $stringTranslation;
  }

  /**
   * Dumps a database to a tar.gz file.
   *
   * 1. Build the SQL and Drupal component context.
   *   The subject can be either a stack or either a wisski or a sql component,
   *   so we need to build the context first:
   *   - If it is a stack, the included `soda_scs_sql_component` is used as
   *     database component and the included `soda_scs_wisski_component` as
   *     optional Drupal/WissKI component to run drush cache clear.
   *   - If it is a component and a `soda_scs_wisski_component`, the connected
   *     `soda_scs_sql_component` is resolved via connectedComponents.
   *   - If it is a component and a `soda_scs_sql_component`, it is used
   *   directly as database component. In this case drush cache clear is
   *   skipped because there is no Drupal/WissKI container.
   * 2. Ensure the backup directory exists.
   * 3. Dump the database to a tar file.
   *
   * @param string $backupPath
   *   The backup directory path where the dump tarball will be created.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|\Drupal\soda_scs_manager\Entity\SodaScsStackInterface $subject
   *   The component or stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function dumpDatabase(string $backupPath, SodaScsComponentInterface|SodaScsStackInterface $subject): SodaScsResult {
    try {
      $backupPath = rtrim($backupPath, '/');
      if ($backupPath === '') {
        return SodaScsResult::failure(
          'Backup path is empty.',
          message: (string) $this->t('Backup path can not be empty.'),
        );
      }

      // Get SQL and Drupal component.
      $contextResult = $this->buildDatabaseContext($subject);
      if (!$contextResult->success) {
        return SodaScsResult::failure(
          error: $contextResult->error ?? 'Failed to build database context.',
          message: (string) $this->t('Failed to build database context.'),
        );
      }

      // Ensure the backup directory exists.
      $backupDirectoryResult = $this->sodaScsContainerHelpers->ensureContainerDirectory(
        $contextResult->data['drupalComponent'],
        $backupPath,
        'www-data',
      );
      if (!$backupDirectoryResult->success) {
        return SodaScsResult::failure(
          error: $dumpResult->error ?? 'Failed to run database dump.',
          message: (string) $this->t('Failed to run database dump.'),
        );
      }

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $sqlComponent */
      $sqlComponent = $contextResult->data['sqlComponent'];

      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $drupalComponent */
      $drupalComponent = $contextResult->data['drupalComponent'];

      // Dump the database to a tar file.
      $dumpResult = $this->runDatabaseDump($sqlComponent, $backupPath, $drupalComponent);
      if (!$dumpResult->success) {
        return SodaScsResult::failure(
          error: $dumpResult->error ?? 'Failed to run database dump.',
          message: (string) $this->t('Failed to run database dump.'),
        );
      }

      return SodaScsResult::success(
        message: (string) $this->t('Database dumped and archived successfully.'),
        data: [
          'databaseDumpTar' => [
            'path' => $dumpResult->data['path'] ?? $dumpResult->data,
            'exec' => $dumpResult->data['exec'] ?? $dumpResult->data,
          ],
        ],
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: (string) $this->t('Failed to dump database.'),
      );
    }
  }

  /**
   * Builds the SQL and Drupal component context for the subject entity.
   *
   * - If the subject is a stack, the SQL component is retrieved from the stack.
   * - If the subject is a component, it check if the component is a SQL
   *   component or a WissKI component and returns the appropriate context.
   *   - If it is a SQL component, it returns the component as the SQL
   *     component and NULL as the Drupal component.
   * - If the subject is not a stack or a component, it returns an error.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|\Drupal\soda_scs_manager\Entity\SodaScsStackInterface $subject
   *   The component or stack.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function buildDatabaseContext(SodaScsComponentInterface|SodaScsStackInterface $subject): SodaScsResult {
    // If the subject is a stack, retrieve the SQL and WissKI components.
    if ($subject instanceof SodaScsStackInterface) {
      try {
        $sqlComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($subject, 'soda_scs_sql_component');
        $drupalComponent = $this->sodaScsStackHelpers->retrieveIncludedComponent($subject, 'soda_scs_wisski_component');
      }
      catch (SodaScsComponentActionsException $e) {
        return SodaScsResult::failure(
          error: $e->getMessage(),
          message: (string) $this->t('SQL or WissKI component not found in stack.'),
        );
      }

      return SodaScsResult::success(
        data: [
          'sqlComponent' => $sqlComponent,
          'drupalComponent' => $drupalComponent,
        ],
        message: (string) $this->t('Stack context resolved.'),
      );
    }

    // If the subject is a component, retrieve the SQL and WissKI components.
    if ($subject instanceof SodaScsComponentInterface) {
      $bundle = $subject->bundle();
      // If the subject is a SQL component, retrieve the WissKI component from
      // the connected components.
      if ($bundle === 'soda_scs_sql_component') {
        // Get wisski component from connected components.
        $wisskiComponent = $this->sodaScsComponentHelpers->resolveConnectedComponents($subject)['wisski'] ?? NULL;

        return SodaScsResult::success(
          data: [
            'sqlComponent' => $subject,
            'drupalComponent' => $wisskiComponent,
          ],
          message: (string) $this->t('SQL component context resolved with WissKI component.'),
        );
      }

      // If the subject is a WissKI component, retrieve the SQL component from
      // the connected components.
      if ($bundle === 'soda_scs_wisski_component') {
        $resolvedComponents = $this->sodaScsComponentHelpers->resolveConnectedComponents($subject);
        $sqlComponent = $resolvedComponents['sql'] ?? NULL;
        if (!$sqlComponent) {
          return SodaScsResult::failure(
            error: 'Connected SQL component not found.',
            message: (string) $this->t('Connected SQL component not found for the WissKI component.'),
          );
        }

        return SodaScsResult::success(
          data: [
            'sqlComponent' => $sqlComponent,
            'drupalComponent' => $subject,
          ],
          message: (string) $this->t('WissKI component context resolved.'),
        );
      }

      return SodaScsResult::failure(
        error: 'Unsupported component type.',
        message: (string) $this->t('Unsupported component type for database dump.'),
      );
    }

    return SodaScsResult::failure(
      error: 'Unsupported entity type.',
      message: (string) $this->t('Unsupported entity type for database dump.'),
    );
  }

  /**
   * Executes the database dump command.
   *
   * 1. Collect information about the database dump.
   * 2. Run the database dump command.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $sqlComponent
   *   The SQL component.
   * @param string $backupPath
   *   The dump file path.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null $clientComponent
   *   The client component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  private function runDatabaseDump(SodaScsComponentInterface $sqlComponent, string $backupPath, ?SodaScsComponentInterface $clientComponent = NULL): SodaScsResult {
    try {

      // 1. Collect information about the database dump.
      // The dump file path.
      $dumpFilePath = $backupPath . '/database.dump.sql.gz';
      // Get the database name.
      $databaseName = $sqlComponent->get('machineName')->value;

      // Get the client container name.
      $clientContainerName = $clientComponent ? $clientComponent->get('containerName')->value : 'database';

      // Get service key for the SQL component.
      $sqlComponentServiceKey = $this->sodaScsServiceKeyActions->getServiceKey([
        'bundle' => 'soda_scs_sql_component',
        'type' => 'password',
        'userId' => $sqlComponent->getOwnerId(),
      ]);
      if (!$sqlComponentServiceKey) {
        return SodaScsResult::failure(
          error: 'SQL component service key not found for user.',
          message: (string) $this->t('SQL component service key not found for user.'),
        );
      }

      $sqlComponentServiceKeyPassword = $sqlComponentServiceKey->get('servicePassword')->value;

      // 2. Run the database dump command.
      $dumpExecCommand = [
        'bash',
        '-c',
        'set -o pipefail && mariadb-dump -hdatabase -u' . $sqlComponent->getOwner()->getDisplayName() . ' -p' . $sqlComponentServiceKeyPassword . ' "' . $databaseName . '" | gzip > ' . $dumpFilePath,
      ];

      $dumpExecResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd' => $dumpExecCommand,
        'containerName' => $clientContainerName,
        'user' => 'www-data',
      ]);

      if (!$dumpExecResponse->success) {
        return SodaScsResult::failure(
          error: $dumpExecResponse->error ?? 'Failed to run database dump.',
          message: (string) $this->t('Failed to run database dump.'),
        );
      }

      return SodaScsResult::success(
        data: ['exec' => $dumpExecResponse->data],
        message: (string) $this->t('Database dump command executed successfully.'),
      );

    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: (string) $this->t('Failed to run database dump.'),
      );
    }
  }

}
