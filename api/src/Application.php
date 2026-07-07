<?php
declare(strict_types=1);
namespace App;

use Cake\Http\BaseApplication;
use Cake\Http\MiddlewareQueue;
use App\Middleware\AuthenticationMiddleware;
use App\Middleware\JwtMiddleware;

// If the real Cake BaseApplication is available, extend it and register middleware.
if (class_exists('\\Cake\\Http\\BaseApplication')) {
    class Application extends BaseApplication
    {
        public function bootstrap(): void
        {
            parent::bootstrap();
            $this->addPlugin('Migrations');
        }

        public function routes(\Cake\Routing\RouteBuilder $routes): void
        {
            $file = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'routes.php';
            if (file_exists($file)) {
                $builder = require $file;
                if (is_callable($builder)) {
                    $builder($routes);
                }
            }
        }

        public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
        {
            // Build a minimal default middleware stack compatible with CakePHP
            $mq = new MiddlewareQueue();

            // Error handler
            $mq->add(new \Cake\Error\Middleware\ErrorHandlerMiddleware([], $this));

            // Asset middleware (serves plugin assets in dev)
            $mq->add(new \Cake\Routing\Middleware\AssetMiddleware());

            // Body parser to decode JSON requests into parsed body
            $mq->add(new \Cake\Http\Middleware\BodyParserMiddleware());

            // Routing middleware to load routes and dispatch controllers
            $mq->add(new \Cake\Routing\Middleware\RoutingMiddleware($this));

            // Register JWT authentication middleware after routing (will run when route middleware wraps)
            $mq->add(new JwtMiddleware());
            $mq->add(new AuthenticationMiddleware());

            return $mq;
        }
    }

} else {
    // Fallback stub so including this file doesn't fatally error when Cake is absent.
    class Application
    {
        public function bootstrap(): void {}
        public function middleware($middlewareQueue)
        {
            return $middlewareQueue;
        }
    }
}
