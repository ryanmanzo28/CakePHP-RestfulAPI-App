<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';
echo "Cake Controller exists: " . (class_exists('\\Cake\\Controller\\Controller') ? 'yes' : 'no') . PHP_EOL;
echo "App\\Controller\\AppController exists: " . (class_exists('App\\\\Controller\\\\AppController') ? 'yes' : 'no') . PHP_EOL;
if (class_exists('App\\Controller\\AppController')) {
    $r = new ReflectionClass('App\\Controller\\AppController');
    $p = $r->getParentClass();
    echo "AppController parent: " . ($p ? $p->getName() : '(none)') . PHP_EOL;
}
echo "Datasources.default: ";
var_export(\Cake\Core\Configure::read('Datasources.default'));
echo PHP_EOL;
