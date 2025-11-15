<?php

declare(strict_types=1);

use App\Http\Router;

require __DIR__ . '/../bootstrap.php';

$router = new Router();

require __DIR__ . '/../routes/web.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH) ?: '/';

$response = $router->dispatch($method, $path);

echo $response;