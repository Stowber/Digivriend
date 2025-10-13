<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Database\ConnectionFactory;
use App\Support\Env;

require __DIR__ . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

Env::load(__DIR__ . '/.env');

$appConfig = AppConfig::load();
$pdo = null;

try {
    $pdo = ConnectionFactory::make($appConfig);
} catch (RuntimeException $exception) {
    if ($appConfig->isDebug()) {
        throw $exception;
    }

    error_log($exception->getMessage());
}