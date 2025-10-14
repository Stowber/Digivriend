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
             (string) self::env(['DB_HOST', 'DB_HOSTNAME'], '127.0.0.1'),
            (int) self::env(['DB_PORT', 'DB_PORT_NUMBER'], 3306),
            (string) self::env(['DB_NAME', 'DB_DATABASE'], 'digivriend'),
            (string) self::env(['DB_USER', 'DB_USERNAME'], 'digivriend'),
            (string) self::env(['DB_PASSWORD', 'DB_PASS'], ''),
            (bool) self::env(['APP_DEBUG'], false)
        );
    }

    public function dsn(): string
    {
        return sprintf('%s;dbname=%s', $this->dsnWithoutDatabase(), $this->dbName);
    }

    public function dsnWithoutDatabase(): string
    {
        return sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $this->dbHost, $this->dbPort);
    }

    public function dbName(): string
    {
        return $this->dbName;
    }

    public function dbUser(): string
    {
        return $this->dbUser;
    }

    public function dbPassword(): string
    {
        return $this->dbPassword;
    }

    public function dbHost(): string
    {
        return $this->dbHost;
    }

    public function dbPort(): int
    {
        return $this->dbPort;
    }

    public function isDebug(): bool
    {
        return $this->debug;
    }
/**
     * Retrieve the first configured environment value for the provided keys.
     */
    private static function env(array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            $value = Env::get($key, null);

            if ($value !== null) {
                return $value;
            }
        }

        return $default;
    }
}