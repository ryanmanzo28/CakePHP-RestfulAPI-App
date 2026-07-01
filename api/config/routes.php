<?php
use Cake\Routing\Router;
use Cake\Routing\RouteBuilder;

$builder = Router::createRouteBuilder('/');
$builder->scope('/api', function (RouteBuilder $routes) {
    $routes->connect('/workouts', 'Api/Workouts::index');
    $routes->connect('/workouts', ['controller' => 'Api/Workouts', 'action' => 'create', '_method' => 'POST']);
    $routes->connect('/auth/login', 'Api/Auth::login');
    $routes->connect('/auth/check', 'Api/Auth::check');
    $routes->connect('/auth/register', 'Api/Auth::register');
});
