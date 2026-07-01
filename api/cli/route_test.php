<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/bootstrap.php';
\Cake\Routing\Router::reload();
require __DIR__ . '/../config/routes.php';
$path = $argv[1] ?? '/api/auth/login';
$method = $argv[2] ?? 'POST';
$req = \Cake\Http\ServerRequestFactory::fromGlobals([
    'REQUEST_METHOD' => $method,
    'REQUEST_URI' => $path,
]);
try {
    $res = \Cake\Routing\Router::parseRequest($req);
    echo json_encode($res, JSON_PRETTY_PRINT), PHP_EOL;
} catch (\Throwable $e) {
    echo json_encode(['error' => $e->getMessage()]), PHP_EOL;
}
