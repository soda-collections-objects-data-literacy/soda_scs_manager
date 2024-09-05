<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Exception\SodaScsRequestException;
use Drupal\Core\TypedData\Exception\MissingDataException;
use Psr\Http\Message\ResponseInterface;

/**
 * Handles the communication with the SCS user manager daemon.
 */
interface SodaScsServiceRequestInterface
{

  /**
   * Builds the create request for the REST service API.
   *
   * @param array $options
   *
   * @return array
   *
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildCreateRequest(array $options): array;

  /**
   * Builds the create request for the REST service API.
   *
   * @param array $options
   *
   * @return array
   *
   */
  public function buildReadRequest(array $options): array;

  /**
   * Build request to get all stacks.
   *
   * @param $options
   *
   * @return array
   * @throws \Drupal\Core\TypedData\Exception\MissingDataException
   */
  public function buildGetRequest($options): array;
  /**
   * Builds the update request for the REST service API.
   *
   * @param array $options
   *
   * @return array
   */
  public function buildUpdateRequest(array $options): array;

  /**
   * Builds the delete request for the REST service API.
   *
   * @param array $options
   *
   * @return array
   *
   * @throws MissingDataException
   */
  public function buildDeleteRequest(array $options): array;

  /**
   * Make the API request.
   * 
   * @param $request
   *
   * @return array
   * 
   * @throws SodaScsRequestException
   */
  public function makeRequest($request): array;
}
