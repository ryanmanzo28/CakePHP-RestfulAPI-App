<?php
declare(strict_types=1);

use App\Application;
use Cake\Http\Server;

chdir(dirname(__DIR__));

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Composer autoload file not found. Run composer install in api/.\n";

    return;
}

require $autoload;

if (PHP_SAPI === 'cli') {
    fwrite(STDOUT, "HydraCor API front controller is intended for HTTP requests.\n");

    return;
}

require dirname(__DIR__) . '/config/bootstrap.php';

try {
    $server = new Server(new Application(dirname(__DIR__) . '/config'));
    $server->emit($server->run());
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'error' => 'Application bootstrap failed',
        'message' => $e->getMessage(),
    ]);
}
