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

    $errorTemplate = __DIR__ . '/templates/error/database-error.php';

    if (!is_file($errorTemplate)) {
        echo '<h1>Databasefout</h1>';
        echo '<p>' . htmlspecialchars($databaseErrorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
        exit;
    }

    require $errorTemplate;
    exit;
}