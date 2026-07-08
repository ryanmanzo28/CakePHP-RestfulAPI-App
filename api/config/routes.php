<?php
declare(strict_types=1);

use Cake\Routing\RouteBuilder;

return static function (RouteBuilder $routes): void {
    $routes->scope('/api', function (RouteBuilder $routes): void {
        $routes->connect('/workouts', 'Api/Workouts::index')->setMethods(['GET']);
        $routes->connect('/workouts/feed', 'Api/Workouts::feed')->setMethods(['GET']);
        $routes->connect('/workouts', 'Api/Workouts::create')->setMethods(['POST']);
        $routes->connect('/workouts/{id}/complete', 'Api/Workouts::complete', [
            'id' => '\\d+',
            'pass' => ['id'],
        ])->setMethods(['POST']);
        $routes->connect('/workouts/{id}', 'Api/Workouts::view', [
            'id' => '\\d+',
            'pass' => ['id'],
        ])->setMethods(['GET', 'HEAD']);
        $routes->connect('/workouts/{id}', 'Api/Workouts::update', [
            'id' => '\\d+',
            'pass' => ['id'],
        ])->setMethods(['PUT', 'PATCH', 'POST']);
        $routes->connect('/workouts/{id}', 'Api/Workouts::delete', [
            'id' => '\\d+',
            'pass' => ['id'],
        ])->setMethods(['DELETE']);
        $routes->connect('/workouts/{id}/delete', 'Api/Workouts::delete', [
            'id' => '\\d+',
            'pass' => ['id'],
        ])->setMethods(['POST']);
        $routes->connect('/auth/login', 'Api/Auth::login')->setMethods(['POST']);
        $routes->connect('/auth/check', 'Api/Auth::check')->setMethods(['POST']);
        $routes->connect('/auth/register', 'Api/Auth::register')->setMethods(['POST']);
    });
};
