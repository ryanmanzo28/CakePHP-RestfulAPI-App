<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->scope('/api', function (RouteBuilder $routes): void {
        $routes->connect('/workouts', 'Api/Workouts::index', ['_method' => 'GET']);
        $routes->connect('/workouts/feed', 'Api/Workouts::feed', ['_method' => 'GET']);
        $routes->connect('/workouts', 'Api/Workouts::create', ['_method' => 'POST']);
        $routes->connect('/workouts/:id/complete', 'Api/Workouts::complete', [
            'id' => '\\d+',
            'pass' => ['id'],
            '_method' => 'POST',
        ]);
        $routes->connect('/workouts/:id', 'Api/Workouts::update', [
            'id' => '\\d+',
            'pass' => ['id'],
            '_method' => 'PUT',
        ]);
        $routes->connect('/workouts/:id', 'Api/Workouts::delete', [
            'id' => '\\d+',
            'pass' => ['id'],
            '_method' => 'DELETE',
        ]);
        $routes->connect('/auth/login', 'Api/Auth::login');
        $routes->connect('/auth/check', 'Api/Auth::check');
        $routes->connect('/auth/register', 'Api/Auth::register');
    });
};
