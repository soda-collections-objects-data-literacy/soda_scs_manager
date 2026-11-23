<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Drush\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\soda_scs_manager\Demo\SodaScsDemoManager;
use Drupal\soda_scs_manager\Demo\SodaScsDemoTemplateArchive;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Lists, exports and imports demo versions.
 */
final class SodaScsDemoTemplateCommands extends DrushCommands {

  use AutowireTrait;

  /**
   * Constructs the commands.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.demo.manager')]
    protected SodaScsDemoManager $demoManager,
    #[Autowire(service: 'soda_scs_manager.demo.template_archive')]
    protected SodaScsDemoTemplateArchive $templateArchive,
  ) {
    parent::__construct();
  }

  /**
   * Packs a ready demo version into demo-v{version}.tar.gz and a .sha256 file.
   *
   * @param string $version
   *   The version, e.g. 1.2.5.
   * @param array{output: string|null} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:demo-export', aliases: ['scs:demo-export', 'scs-demo-export'])]
  #[CLI\Argument(name: 'version', description: 'The version, e.g. 1.2.5.')]
  #[CLI\Option(name: 'output', description: 'Output directory; default {snapshot root}/_demo-templates/_exports.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:demo-export 1.2.5', description: 'Write _demo-templates/_exports/demo-v1.2.5.tar.gz and its .sha256 file.')]
  public function demoExport(string $version, array $options = ['output' => NULL]): int {
    $template = $this->demoManager->templateWithVersion($version);
    if ($template === NULL) {
      $this->logger()->error(dt('Demo version @version does not exist.', ['@version' => $version]));
      return self::EXIT_FAILURE;
    }
    $output = is_string($options['output'] ?? NULL) && $options['output'] !== '' ? $options['output'] : NULL;
    $result = $this->templateArchive->export($template, $output);
    if (!$result->success) {
      $this->logger()->error($result->message);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($result->message);
    return self::EXIT_SUCCESS;
  }

  /**
   * Imports a demo version archive written by demo-export.
   *
   * @param string $archive
   *   Path of demo-v{version}.tar.gz; its .sha256 file must sit next to it.
   * @param array{activate: bool} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:demo-import', aliases: ['scs:demo-import', 'scs-demo-import'])]
  #[CLI\Argument(name: 'archive', description: 'Path of demo-v{version}.tar.gz.')]
  #[CLI\Option(name: 'activate', description: 'Use the imported version for new demos and resets.')]
  #[CLI\Usage(name: 'drush soda_scs_manager:demo-import /var/scs-manager/snapshots/_demo-templates/_incoming/demo-v1.2.5.tar.gz --activate', description: 'Import version 1.2.5 and make it active.')]
  public function demoImport(string $archive, array $options = ['activate' => FALSE]): int {
    $result = $this->templateArchive->import($archive, !empty($options['activate']));
    if (!$result->success) {
      $this->logger()->error($result->message);
      return self::EXIT_FAILURE;
    }
    $this->logger()->success($result->message);
    return self::EXIT_SUCCESS;
  }

  /**
   * Lists the demo versions, newest first.
   *
   * @param array{format: string} $options
   *   Command options.
   */
  #[CLI\Command(name: 'soda_scs_manager:demo-versions', aliases: ['scs:demo-versions', 'scs-demo-versions'])]
  #[CLI\FieldLabels(labels: [
    'version' => 'Version',
    'status' => 'Status',
    'origin' => 'Origin',
    'active' => 'Active',
    'demos' => 'Demos',
  ])]
  #[CLI\DefaultTableFields(fields: ['version', 'status', 'origin', 'active', 'demos'])]
  #[CLI\Usage(name: 'drush soda_scs_manager:demo-versions', description: 'List the demo versions.')]
  public function demoVersions(array $options = ['format' => 'table']): RowsOfFields {
    $rows = [];
    foreach ($this->demoManager->templatesByVersion() as $template) {
      $rows[] = [
        'active' => $this->demoManager->isActiveTemplate($template) ? 'yes' : '',
        'demos' => $this->demoManager->templateUsageCount($template),
        'origin' => $template->getOrigin(),
        'status' => $template->getStatus(),
        'version' => $template->getVersion(),
      ];
    }
    return new RowsOfFields($rows);
  }

}
