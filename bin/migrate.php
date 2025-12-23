#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Config\AppConfig;
use App\Database\ConnectionFactory;
use App\Database\SchemaManager;
use App\Support\Env;

require __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/Support/helpers.php';

Env::load(__DIR__ . '/../.env');

$appConfig = AppConfig::load();

try {
    $pdo = ConnectionFactory::make($appConfig);
    SchemaManager::migrate($pdo);

    fwrite(STDOUT, "Database migrated successfully." . PHP_EOL);
    exit(0);
} catch (\Throwable $throwable) {
    fwrite(STDERR, 'Migration failed: ' . $throwable->getMessage() . PHP_EOL);
    exit(1);
}