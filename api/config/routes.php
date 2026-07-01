<?php
use Cake\Routing\Router;
use Cake\Routing\RouteBuilder;

$builder = Router::createRouteBuilder('/');
$builder->scope('/api', function (RouteBuilder $routes) {
    $routes->connect('/workouts', 'Api/Workouts::index');
    $routes->connect('/auth/login', 'Api/Auth::login');
});
