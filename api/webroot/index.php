<?php
declare(strict_types=1);
// API front controller - boots CakePHP when available, otherwise provides a minimal message.
chdir(dirname(__DIR__));

// Load Composer autoload if present
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require __DIR__ . '/../vendor/autoload.php';
}

// No lightweight fallback here — let CakePHP handle auth routes.

// If Cake's HTTP server and Application are available, use them to handle the request.
if (class_exists('\\Cake\\Http\\Server') && class_exists('\\App\\Application')) {
    // Ensure config is loaded and run the Cake server using a PSR-7 request/response flow
    try {
        if (file_exists(__DIR__ . '/../config/bootstrap.php')) {
            require __DIR__ . '/../config/bootstrap.php';
        }

        // Ensure routes are loaded so Cake can dispatch to our controllers
        if (class_exists('\\Cake\\Routing\\Router')) {
            // Initialize Router internal state before loading route definitions
            \Cake\Routing\Router::reload();
        }
        if (file_exists(__DIR__ . '/../config/routes.php')) {
            require __DIR__ . '/../config/routes.php';
        }

        $app = new \App\Application(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config');
        $server = new \Cake\Http\Server($app);
        $response = $server->run();
        if ($response instanceof \Psr\Http\Message\ResponseInterface) {
            $server->emit($response);
        }
        return;
    } catch (\Throwable $e) {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Application bootstrap failed', 'message' => $e->getMessage()]);
        return;
    }
}

// Fallback response when Cake isn't installed or available
header('Content-Type: text/plain');
echo "Hydracor API minimal front controller — CakePHP not installed.\n";
return;
