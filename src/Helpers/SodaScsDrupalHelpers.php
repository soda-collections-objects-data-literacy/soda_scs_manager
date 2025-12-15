<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\ValueObject\SodaScsResult;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Helper class for SCS Drupal operations.
 */
class SodaScsDrupalHelpers {

  use StringTranslationTrait;
  /**
   * The container helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers
   */
  protected SodaScsContainerHelpers $sodaScsContainerHelpers;

  /**
   * SodaScsDrupalHelpers constructor.
   *
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsContainerHelpers $sodaScsContainerHelpers
   *   The container helpers.
   */
  public function __construct(
    #[Autowire(service: 'soda_scs_manager.container.helpers')]
    SodaScsContainerHelpers $sodaScsContainerHelpers,
  ) {
    $this->sodaScsContainerHelpers = $sodaScsContainerHelpers;
  }

  /**
   * Get the installed Drupal packages.
   *
   * Construct run request for composer list command and execute it,
   * get the output and return it as a Soda SCS result.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component.
   *
   * @return \Drupal\soda_scs_manager\ValueObject\SodaScsResult
   *   The Soda SCS result.
   */
  public function getInstalledDrupalPackages(SodaScsComponentInterface $component): SodaScsResult {
    try {
      // Get the packages via Composer.
      //
      // Use JSON output to avoid issues with column formatting and whitespace
      // collapsing when this response is rendered in a browser/JSON viewer.
      $dockerExecCommandResponse = $this->sodaScsContainerHelpers->executeDockerExecCommand([
        'cmd'           => [
          'composer',
          'show',
          '--format=json',
          '--no-ansi',
          '--no-interaction',
        ],
        'containerName' => (string) $component->get('containerId')->value,
        'user'          => 'www-data',
      ]);
      if (!$dockerExecCommandResponse->success) {
        return SodaScsResult::failure(
          error: $dockerExecCommandResponse->error,
          message: (string) $this->t('Failed to get installed Drupal packages.'),
        );
      }

      $rawOutput = (string) ($dockerExecCommandResponse->data['output'] ?? '');
      $composerData = json_decode($rawOutput, TRUE);
      if (!is_array($composerData) || !isset($composerData['installed']) || !is_array($composerData['installed'])) {
        return SodaScsResult::failure(
          error: $rawOutput ?: 'Invalid Composer output.',
          message: (string) $this->t('Failed to parse installed Drupal packages.'),
        );
      }

      $packages = [];
      foreach ($composerData['installed'] as $package) {
        if (!is_array($package)) {
          continue;
        }
        $packages[] = [
          'name'        => (string) ($package['name'] ?? ''),
          'version'     => (string) ($package['version'] ?? ''),
          'description' => (string) ($package['description'] ?? ''),
        ];
      }

      return SodaScsResult::success(
        message: (string) $this->t('Installed Drupal packages retrieved successfully.'),
        data: [
          'packages' => $packages,
          'exec'     => $dockerExecCommandResponse->data,
        ],
      );
    }
    catch (\Exception $e) {
      return SodaScsResult::failure(
        error: $e->getMessage(),
        message: (string) $this->t('Failed to get installed Drupal packages.'),
      );
    }
  }

}
