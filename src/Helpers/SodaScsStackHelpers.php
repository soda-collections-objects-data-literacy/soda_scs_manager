<?php

declare(strict_types=1);

namespace Drupal\soda_scs_manager\Helpers;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Messenger\MessengerTrait;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;
use Drupal\soda_scs_manager\Entity\SodaScsStackInterface;
use Drupal\soda_scs_manager\Service\SodaScsJupyterHubApiClient;
use GuzzleHttp\ClientInterface;

/**
 * Helper functions for SCS components.
 */
class SodaScsStackHelpers {
  use MessengerTrait;
  use StringTranslationTrait;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;


  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected ClientInterface $httpClient;

  /**
   * The Soda SCS component helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers
   */
  protected $sodaScsComponentHelpers;

  /**
   * The Soda SCS service helpers.
   *
   * @var \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers
   */
  protected SodaScsServiceHelpers $sodaScsServiceHelpers;

  /**
   * SodaScsStackHelpers constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsComponentHelpers $sodaScsComponentHelpers
   *   The Soda SCS component helpers.
   * @param \Drupal\soda_scs_manager\Helpers\SodaScsServiceHelpers $sodaScsServiceHelpers
   *   The Soda SCS service helpers.
   */
  public function __construct(
    EntityTypeManagerInterface $entityTypeManager,
    ClientInterface $httpClient,
    #[Autowire(service: 'soda_scs_manager.component.helpers')]
    SodaScsComponentHelpers $sodaScsComponentHelpers,
    #[Autowire(service: 'soda_scs_manager.service.helpers')]
    SodaScsServiceHelpers $sodaScsServiceHelpers,
    #[Autowire(service: 'soda_scs_manager.jupyter.helpers')]
    protected SodaScsJupyterHelpers $jupyterHelpers,
    #[Autowire(service: 'soda_scs_manager.project_application.helpers')]
    protected SodaScsProjectApplicationHelpers $projectApplicationHelpers,
  ) {
    $this->entityTypeManager = $entityTypeManager;
    $this->httpClient = $httpClient;
    $this->sodaScsComponentHelpers = $sodaScsComponentHelpers;
    $this->sodaScsServiceHelpers = $sodaScsServiceHelpers;
  }

  /**
   * Retrieves a referenced component of a given SODa SCS Stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   * @param string $bundle
   *   The bundle of the referenced component.
   *
   * @return \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface|null
   *   The referenced component, or NULL if the stack does not include a
   *   component of the given bundle (e.g. because it was never created).
   */
  public function retrieveIncludedComponent(SodaScsStackInterface $stack, string $bundle): ?SodaScsComponentInterface {

    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponents = $stack->getValue($stack, 'includedComponents');

    return array_values(array_filter($includedComponents, function ($includedComponent) use ($bundle) {
      $componentBundle = $includedComponent->bundle->get(0)->get('value')->getValue();
      return $componentBundle === $bundle;
    }))[0] ?? NULL;
  }

  /**
   * Remove value from included component field of a given SODa SCS stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Component.
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *   The component value to be deleted from field list.
   */
  public function removeIncludedComponentValue(SodaScsStackInterface $stack, SodaScsComponentInterface $component) {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();
    $filteredComponents = array_filter($includedComponents, function ($includedComponent) use ($component) {
      return $includedComponent->target_id != $component->id();
    });
    $stack->set('includedComponents', $filteredComponents);
    $stack->save();
  }

  /**
   * Remove non existing components from includedComponents field.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   */
  public function cleanIncludedComponents(SodaScsStackInterface $stack) {
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();
    $filteredComponents = array_filter($includedComponents, function ($includedComponent) {
      return $includedComponent->id() !== NULL;
    });
    $stack->set('includedComponents', $filteredComponents);
    $stack->save();
  }

  /**
   * Check the health of a WissKI stack.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   *
   * @return array
   *   The health of the WissKI stack.
   */
  public function checkWisskiHealth(SodaScsStackInterface $stack) {
    // Get the stack entity by machine name.
    $stackStorage = $this->entityTypeManager->getStorage('soda_scs_stack');
    $stackEntities = $stackStorage->loadByProperties(['machineName' => $stack->get('machineName')->value]);

    if (empty($stackEntities)) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        'No stack found with machine name: ' . $stack->get('machineName')->value,
        404,
      );
    }

    /** @var \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack */
    $stack = reset($stackEntities);

    // Get included components.
    /** @var \Drupal\Core\Field\EntityReferenceFieldItemListInterface $includedComponentsItemList */
    $includedComponentsItemList = $stack->get('includedComponents');
    $includedComponents = $includedComponentsItemList->referencedEntities();

    // Find the WissKI component.
    $wisskiComponent = NULL;
    foreach ($includedComponents as $component) {
      /** @var \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component */
      if ($component->get('bundle')->value === 'soda_scs_wisski_component') {
        $wisskiComponent = $component;
        break;
      }
    }

    if (!$wisskiComponent) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        'No WissKI component found in stack: ' . $stack->get('machineName')->value,
        404,
      );
    }

    // Check the health of the WissKI component.
    return $this->sodaScsComponentHelpers->drupalHealthCheck($wisskiComponent);
  }

  /**
   * Check the health of a Jupyter stack: the state of the project's server.
   *
   * The server runs on demand, so "stopped" is its normal idle state.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   *
   * @return array
   *   The health check result.
   */
  public function checkJupyterHealth(SodaScsStackInterface $stack) {
    $project = $this->projectApplicationHelpers->loadPartOfProject($stack);
    if ($project === NULL) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        NULL,
        'Jupyter stack has no project.',
        404,
      );
    }

    return match ($this->jupyterHelpers->projectServerState($project)) {
      SodaScsJupyterHubApiClient::STATE_RUNNING => SodaScsHealthStatus::payload(SodaScsHealthStatus::RUNNING),
      SodaScsJupyterHubApiClient::STATE_STARTING => SodaScsHealthStatus::payload(SodaScsHealthStatus::STARTING, NULL, '', 202),
      SodaScsJupyterHubApiClient::STATE_STOPPING,
      SodaScsJupyterHubApiClient::STATE_STOPPED => SodaScsHealthStatus::payload(SodaScsHealthStatus::STOPPED),
      SodaScsJupyterHubApiClient::STATE_MISSING => SodaScsHealthStatus::payload(
        SodaScsHealthStatus::PROBLEM,
        (string) $this->t('JupyterHub is not set up for this project.'),
        'Hub user missing.',
        404,
      ),
      default => SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        NULL,
        'JupyterHub API is unreachable.',
        503,
      ),
    };
  }

  /**
   * Check the health of a Nextcloud stack.
   *
   * Performs an HTTP GET request to the Nextcloud status endpoint to verify
   * the service is reachable and responding.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsStackInterface $stack
   *   The SODa SCS Stack.
   *
   * @return array
   *   The health check result.
   */
  public function checkNextcloudHealth(SodaScsStackInterface $stack) {
    try {
      $nextcloudSettings = $this->sodaScsServiceHelpers->initNextcloudSettings();
      $url = rtrim($nextcloudSettings['baseUrl'], '/') . '/status.php';

      if (empty($nextcloudSettings['baseUrl'])) {
        return SodaScsHealthStatus::payload(
          SodaScsHealthStatus::UNKNOWN,
          (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
          'Nextcloud base URL is not configured in settings.',
          500,
        );
      }

      $response = $this->httpClient->request('GET', $url, [
        'timeout' => 5,
        'connect_timeout' => 3,
        'http_errors' => FALSE,
      ]);

      $statusCode = $response->getStatusCode();
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::fromHttpCode($statusCode),
        NULL,
        $statusCode === 200 ? '' : 'Service returned HTTP ' . $statusCode,
        $statusCode,
      );
    }
    catch (\Exception $e) {
      return SodaScsHealthStatus::payload(
        SodaScsHealthStatus::UNKNOWN,
        (string) SodaScsHealthStatus::translatableLabel(SodaScsHealthStatus::UNKNOWN),
        $e->getMessage(),
        (int) $e->getCode(),
      );
    }
  }

}
