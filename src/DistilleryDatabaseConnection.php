<?php
// DatabaseConnection.php

namespace Drupal\soda_scs_manager;

use PDOException;
use Drupal\Core\Database\Database;

class DistilleryDatabaseConnection {    
    public $db;

    public function __construct() {
        $dbConfig = \Drupal::config('soda_scs_manager.settings');
        
        $connection = [
            'database' => $dbConfig->get('distilleryDatabaseName'),
            'username' => $dbConfig->get('distilleryDatabaseUser'),
            'password' => $dbConfig->get('distilleryDatabasePassword'),
            'host' => $dbConfig->get('distilleryDatabaseHost'),
            'port' => $dbConfig->get('distilleryDatabasePort'),
            'driver' => 'mysql',
            'prefix' => '',
            'collation' => $dbConfig->get('distilleryDatabaseCharset')
          ];
        Database::addConnectionInfo('external', 'default', $connection);

        try {
            $this->db = Database::getConnection('default', 'external');
        } catch (PDOException $e) { 
            \Drupal::logger('soda_scs_manager')->error($e->getMessage());
        }

    }

}