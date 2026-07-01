<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';

try {
    $locator = \Cake\ORM\TableRegistry::getTableLocator();
    $users = $locator->get('Users');
    echo "Got Users table\n";
} catch (Throwable $e) {
    echo "Exception: " . get_class($e) . " - " . $e->getMessage() . "\n";
    echo $e->getTraceAsString();
}
