<?php

namespace Drupal\soda_scs_manager;

use Drupal\soda_scs_manager\Entity\SodaScsComponentInterface;

interface SodaScsServiceActionsInterface  {

   /**
   * Creates a new service.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   * 
   * @return array
   *  Success result.
   *
   * @throws MissingDataException
   */
  public function createService(SodaScsComponentInterface $component): array;

  /**
   * Checks if a service already exists.
   *
   * @param string $name
   *   The name of the service.
   *
   * @return array
   *  Command, execution status (0 = success >0 = failure) and last line of
   *
   */
  public function existService(string $name): array;
  
  /**
   * Updates a service.
   * 
   * @return void
   */
  public function updateService();

  /**
   * Deletes a service.
   *
   * @param \Drupal\soda_scs_manager\Entity\SodaScsComponentInterface $component
   *
   * @return array
   *  Success result.
   *
   * @throws MissingDataException
   */
  public function deleteService(SodaScsComponentInterface $component): array;
  /**
   * Creates a new service user.
   *
   * @param string $user
   *   The name of the service user.
   * @param string $userPassword
   *   The password of the service user.
   *
   * @return array
   *  Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   *
   */
  public function createServiceUser(string $user, string $userPassword): array;


  /**
   * Gets the user from the Drupal service and Distillery.
   * 
   * @param int $uid
   *   The id of the drupal user.
   * 
   * @return array
   */
  public function getServiceUser($uid = NULL): array;

  /**
   * Checks if a service user exists.
   *
   * @param string $user
   *   The name of the service user.
   *
   * @return array
   *  Command, execution status (0 = success >0 = failure) and last line of
   *   output as result.
   */
  public function existServiceUser(string $user): array;

  /**
   * Gets the users from the Drupal service and Distillery.
   *
   * @param int $uid
   * The user ID to get.
   *
   * @return array
   * The users.
   *
   * @throws \Exception
   * If the request fails.
   */


  /**
   * Grants rights to a service user.
   *
   * @param string $user
   * @param string $name
   * @param array $rights
   *
   * @return array
   */
  public function grantServiceRights(string $user, string $name, array $rights): array;

  /**
   * Flush the service privileges.
   *
   * @return array
   */
  public function flushPrivileges(): array;

  /**
   * Checks if a service user owns any serives.
   *
   * If the user does not own any services, the user will be deleted.
   *
   * @param string $user
   *
   * @return array
   */
  public function cleanServiceUsers(string $user);

  /**
   * Checks handle shell command failure.
   * 
   * @param array $commandResult
   *   The command result.
   * @param string $action
   *   The action.
   * @param string $entityName
   *   The entity name.
   * 
   * @return array
   *   The command result.
   */
  public function handleCommandFailure(array $commandResult, string $action, string $entityName): array;

}