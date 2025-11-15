<?php

declare(strict_types=1);

use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomersController;
use App\Http\Controllers\DashboardController;

$router->get('/', static function () use ($pdo): string {
    return (new DashboardController($pdo))->index();
});

$router->get('/index.php', static function () use ($pdo): string {
    return (new DashboardController($pdo))->index();
});

$router->get('/customers', static function () use ($pdo): string {
    return (new CustomersController($pdo))->index();
});

$router->get('/customers.php', static function () use ($pdo): string {
    return (new CustomersController($pdo))->index();
});

$router->get('/customer', static function () use ($pdo): string {
    return (new CustomerController($pdo))->show();
});

$router->get('/customer.php', static function () use ($pdo): string {
    return (new CustomerController($pdo))->show();
});

$router->post('/customer.php', static function () use ($pdo): string {
    return (new CustomerController($pdo))->show();
});

$router->post('/customer', static function () use ($pdo): string {
    return (new CustomerController($pdo))->show();
});