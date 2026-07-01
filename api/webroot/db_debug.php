<?php
require __DIR__ . '/../config/bootstrap.php';
header('Content-Type: text/plain');
echo "Datasources.default:\n";
var_export(\Cake\Core\Configure::read('Datasources.default'));
echo "\n";

?>