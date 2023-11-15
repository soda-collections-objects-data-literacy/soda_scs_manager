<?php
namespace Drupal\wisski_cloud_account_manager\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Database\Connection;

class AccountRouteAccessCheck implements AccessInterface {

  protected $currentUser;
  protected $database;

  public function __construct(AccountInterface $currentUser, Connection $database) {
    $this->currentUser = $currentUser;
    $this->database = $database;
  }

  public function access($aid) {
    // If the user has the "Administer WissKI Cloud Account Manager" role, allow access.
    if ($this->currentUser->hasPermission('Administer WissKI Cloud Account Manager')) {
      return AccessResult::allowed()->cachePerUser();
    }
    // Otherwise, check if the aid matches the user's aid in the database.
    $uid = $this->currentUser->id();
    $query = $this->database->select('wisski_cloud_account_manager_accounts', 'w')
      ->fields('w', ['aid'])
      ->condition('uid', $uid)
      ->condition('aid', $aid)
      ->execute();

    $result = $query->fetchField();

    return AccessResult::allowedIf($result)->cachePerUser();
  }
}
