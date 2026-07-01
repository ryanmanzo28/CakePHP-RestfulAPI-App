<?php
// Minimal bootstrap stub inside /api. Expand as needed for CakePHP.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

if (!defined('APP')) {
    define('APP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}

// Ensure a default application encoding is configured so Cake's Response
// constructor has a valid charset value when bootstrapping.
if (class_exists('\Cake\Core\Configure')) {
    \Cake\Core\Configure::write('App.encoding', 'UTF-8');
    // Enable debug during local development to show detailed errors
    \Cake\Core\Configure::write('debug', true);
    // Ensure the application namespace is configured so Cake can locate App classes
    if (\Cake\Core\Configure::read('App.namespace') === null) {
        \Cake\Core\Configure::write('App.namespace', 'App');
    }
    // If no Datasources config is present, populate it from environment variables
    if (\Cake\Core\Configure::read('Datasources.default') === null) {
        $dbHost = getenv('DB_HOST') ?: 'localhost';
        $dbUser = getenv('DB_USER') ?: 'root';
        $dbPass = getenv('DB_PASS') ?: '';
        $dbName = getenv('DB_NAME') ?: 'cakephp';
        $dbPort = getenv('DB_PORT') ?: '3306';

        \Cake\Core\Configure::write('Datasources.default', [
            'className' => \Cake\Database\Connection::class,
            'driver' => \Cake\Database\Driver\Mysql::class,
            'persistent' => false,
            'host' => $dbHost,
            'username' => $dbUser,
            'password' => $dbPass,
            'database' => $dbName,
            'port' => $dbPort,
            'encoding' => 'utf8mb4',
            'timezone' => 'UTC',
            'cacheMetadata' => true,
            'quoteIdentifiers' => false,
            'log' => false,
        ]);
    }
    // Ensure ConnectionManager knows about configured datasources
    if (class_exists('\Cake\\Datasource\\ConnectionManager')) {
        \Cake\Datasource\ConnectionManager::setConfig(\Cake\Core\Configure::read('Datasources'));
    }
    // Provide minimal cache configurations required by Cake's ORM
    if (class_exists('\Cake\\Cache\\Cache')) {
        $tmp = sys_get_temp_dir();
        \Cake\Cache\Cache::setConfig([
            '_cake_core_' => [
                'className' => \Cake\Cache\Engine\FileEngine::class,
                'path' => $tmp . DIRECTORY_SEPARATOR . 'cake_cache_core',
                'prefix' => 'cake_core_',
            ],
            '_cake_model_' => [
                'className' => \Cake\Cache\Engine\FileEngine::class,
                'path' => $tmp . DIRECTORY_SEPARATOR . 'cake_cache_model',
                'prefix' => 'cake_model_',
            ],
        ]);
    }
}
// Enable debug mode during local development to surface errors
if (class_exists('\Cake\Core\Configure')) {
    \Cake\Core\Configure::write('debug', true);
}
// Make sure PHP errors are displayed in this development environment
ini_set('display_errors', '1');
error_reporting(E_ALL);
