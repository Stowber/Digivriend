<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Env;

final class AppConfig
{
    public function __construct(
        private readonly string $dbHost,
        private readonly int $dbPort,
        private readonly string $dbName,
        private readonly string $dbUser,
        private readonly string $dbPassword,
        private readonly bool $debug
    ) {
    }

    public static function load(): self
    {
        Env::load(__DIR__ . '/../../.env');

        return new self(
            (string) Env::get('DB_HOST', '127.0.0.1'),
            (int) Env::get('DB_PORT', 3306),
            (string) Env::get('DB_NAME', 'digivriend'),
            (string) Env::get('DB_USER', 'digivriend'),
            (string) Env::get('DB_PASSWORD', ''),
            (bool) Env::get('APP_DEBUG', false)
        );
    }

    public function dsn(): string
    {
        return sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $this->dbHost, $this->dbPort, $this->dbName);
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPassword(): string
    {
        return $this->dbPassword;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
}