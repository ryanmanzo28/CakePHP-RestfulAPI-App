<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->scope('/api', function (RouteBuilder $routes): void {
        $routes->connect('/workouts', 'Api/Workouts::index');
        $routes->connect('/workouts/feed', 'Api/Workouts::feed');
        $routes->connect('/workouts', ['controller' => 'Api/Workouts', 'action' => 'create', '_method' => 'POST']);
        $routes->connect('/workouts/:id', ['controller' => 'Api/Workouts', 'action' => 'update', '_method' => 'PUT'], [
            'id' => '\\d+',
            'pass' => ['id'],
        ]);
        $routes->connect('/workouts/:id', ['controller' => 'Api/Workouts', 'action' => 'delete', '_method' => 'DELETE'], [
            'id' => '\\d+',
            'pass' => ['id'],
        ]);
        $routes->connect('/auth/login', 'Api/Auth::login');
        $routes->connect('/auth/check', 'Api/Auth::check');
        $routes->connect('/auth/register', 'Api/Auth::register');
    });
};
