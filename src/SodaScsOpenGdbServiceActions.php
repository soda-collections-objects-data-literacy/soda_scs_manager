<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\Core\TypedData\Exception\MissingDataException;

/**
 * Handles the communication with the SCS user manager daemon.
 */
class SodaScsOpenGdbServiceActions implements SodaScsServiceRequestInterface
{

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $options
   *
   * @return array
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $options): array
  {
    return [];
  }

  /**
   * Builds the create request for the OpenGDB service API.
   *
   * @param array $options
   *
   * @return array
   *
   */
  public function buildReadRequest(array $options): array
  {
    return [];
  }

  /**
   * Build request to get all stacks.
   *
   * @param $options
   *
   * @return array
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($options): array
  {
    return [];
  }
  /**
   * Builds the update request for the OpenGDB service API.
   *
   * @param array $options
   *
   * @return array
   */
  public function buildUpdateRequest(array $options): array
  {
    return [];
  }

  /**
   * Builds the delete request for the OpenGDB service API.
   *
   * @param array $options
   *
   * @return array
   *
   * @throws MissingDataException
   */
  public function buildDeleteRequest(array $options): array
  {
    return [];
  }

  /**
   * @param $request
   *
   * @return array
   * 
   * @throws SodaScsRequestException
   */
  public function makeRequest($request): array
  {
    return [];
  }
}
