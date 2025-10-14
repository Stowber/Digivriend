<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Database\ConnectionFactory;
use App\Database\SchemaManager;
use App\Support\Env;

require __DIR__ . '/vendor/autoload.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

Env::load(__DIR__ . '/.env');

$appConfig = AppConfig::load();
$pdo = null;
$connectionError = null;

try {
    $pdo = ConnectionFactory::make($appConfig);
    SchemaManager::migrate($pdo);
} catch (RuntimeException $exception) {
    if ($appConfig->isDebug()) {
        throw $exception;
    }

    error_log($exception->getMessage());
$connectionError = $exception;
}

if (!$pdo instanceof \PDO) {
    http_response_code(500);
    $databaseErrorMessage = 'Er is een fout opgetreden bij het verbinden met de database.';

    if ($connectionError instanceof RuntimeException && $appConfig->isDebug()) {
        throw $connectionError;
    }

    require __DIR__ . '/templates/errors/database-error.php';
    exit;
}