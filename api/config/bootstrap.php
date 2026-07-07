<?php
// Minimal bootstrap stub inside /api. Expand as needed for CakePHP.
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

if (!defined('APP')) {
    define('APP', dirname(__DIR__) . DIRECTORY_SEPARATOR);
}


if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!defined('ROOT')) {
    define('ROOT', dirname(__DIR__));
}

if (!defined('CONFIG')) {
    define('CONFIG', ROOT . DS . 'config' . DS);
}

if (!defined('WWW_ROOT')) {
    define('WWW_ROOT', ROOT . DS . 'webroot' . DS);
}

if (!defined('TMP')) {
    define('TMP', sys_get_temp_dir() . DS . 'hydracor' . DS);
}

if (!defined('LOGS')) {
    define('LOGS', TMP . 'logs' . DS);
}

if (!defined('CACHE')) {
    define('CACHE', TMP . 'cache' . DS);
}

if (!is_dir(TMP)) {
    @mkdir(TMP, 0775, true);
}

if (!is_dir(LOGS)) {
    @mkdir(LOGS, 0775, true);
}

if (!is_dir(CACHE)) {
    @mkdir(CACHE, 0775, true);
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

if (class_exists('\Cake\Routing\Router')) {
    \Cake\Routing\Router::reload();
}
// Enable debug mode during local development to surface errors
if (class_exists('\Cake\Core\Configure')) {
    \Cake\Core\Configure::write('debug', true);
}
// Make sure PHP errors are displayed in this development environment
// Turn off display of errors in responses and log them instead to avoid polluting JSON output
ini_set('display_errors', '0');
ini_set('log_errors', '1');
// Exclude deprecation notices from reported errors to silence Cake deprecation warnings
error_reporting(E_ALL & ~E_USER_DEPRECATED & ~E_DEPRECATED);

if (class_exists('\Cake\Core\Configure')) {
    // Inform CakePHP error handler to ignore user deprecations
    \Cake\Core\Configure::write('Error.errorLevel', E_ALL & ~E_USER_DEPRECATED & ~E_DEPRECATED);
    // Optionally ignore known deprecation paths to be very specific
    $ignored = \Cake\Core\Configure::read('Error.ignoredDeprecationPaths') ?: [];
    $ignored[] = 'vendor/cakephp/cakephp/src/I18n/I18n.php';
    \Cake\Core\Configure::write('Error.ignoredDeprecationPaths', array_values(array_unique($ignored)));
}
