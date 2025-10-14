<?php

declare(strict_types=1);

namespace App\Config;

use App\Support\Env;
use InvalidArgumentException;
use RuntimeException;

final class AppConfig
{
    /**
     * @param array<string, string> $options
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $dbHost,
        private readonly int $dbPort,
        private readonly string $dbName,
        private readonly string $dbUser,
        private readonly string $dbPassword,
        private readonly bool $debug,
        private readonly array $options
    ) {
    }

    public static function load(): self
    {
        Env::load(__DIR__ . '/../../.env');

        $url = (string) Env::get('DB_URL', '');
        $fromUrl = $url !== '' ? self::parseDatabaseUrl($url) : [];

        $driver = $fromUrl['driver'] ?? strtolower((string) self::env(['DB_DRIVER', 'DB_CONNECTION'], 'mysql'));
        $driver = self::normaliseDriver($driver);

        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new RuntimeException(sprintf('Unsupported database driver "%s" configured.', $driver));
        }

        $defaultPort = $driver === 'pgsql' ? 5432 : 3306;

        $host = $fromUrl['host'] ?? (string) self::env(['DB_HOST', 'DB_HOSTNAME'], '127.0.0.1');
        $port = (int) ($fromUrl['port'] ?? self::env(['DB_PORT', 'DB_PORT_NUMBER'], $defaultPort));
        $database = $fromUrl['database'] ?? (string) self::env(['DB_NAME', 'DB_DATABASE'], 'digivriend');
        $user = $fromUrl['user'] ?? (string) self::env(['DB_USER', 'DB_USERNAME'], 'digivriend');
        $password = $fromUrl['password'] ?? (string) self::env(['DB_PASSWORD', 'DB_PASS'], '');
        $debug = (bool) self::env(['APP_DEBUG'], false);

        $options = $fromUrl['options'] ?? [];
        $options = self::mergeDriverDefaults($driver, $options);

        return new self(
            $driver,
            $host,
            $port,
            $database,
            $user,
            $password,
            $debug,
            $options
        );
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function dsn(): string
    {
        return $this->dsnForDatabase($this->dbName);
    }

    public function dsnWithoutDatabase(): string
    {
        return $this->dsnForDatabase(null);
    }

    public function dsnForDatabase(?string $database): string
    {
        $dsn = sprintf('%s:host=%s;port=%d', $this->driver, $this->dbHost, $this->dbPort);

        if ($database !== null) {
            $dsn .= sprintf(';dbname=%s', $database);
        }

        foreach ($this->options as $key => $value) {
            $dsn .= sprintf(';%s=%s', $key, $value);
        }

        return $dsn;
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
     * @return array<string, string>
     */
    public function options(): array
    {
        return $this->options;
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
/**
     * @return array{driver: string, host?: string, port?: int, database?: string, user?: string, password?: string, options?: array<string, string>}
     */
    private static function parseDatabaseUrl(string $url): array
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'])) {
            throw new InvalidArgumentException('The provided database URL is invalid.');
        }

        $driver = self::normaliseDriver($parts['scheme']);

        $database = isset($parts['path']) ? ltrim((string) $parts['path'], '/') : null;
        $options = [];

        if (isset($parts['query'])) {
            parse_str((string) $parts['query'], $options);
        }

        $options = array_map(static fn ($value) => (string) $value, $options);

        return array_filter(
            [
                'driver' => $driver,
                'host' => $parts['host'] ?? null,
                'port' => isset($parts['port']) ? (int) $parts['port'] : null,
                'database' => $database !== '' ? $database : null,
                'user' => $parts['user'] ?? null,
                'password' => $parts['pass'] ?? null,
                'options' => $options,
            ],
            static fn ($value) => $value !== null && $value !== []
        );
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    private static function mergeDriverDefaults(string $driver, array $options): array
    {
        $defaults = match ($driver) {
            'mysql' => ['charset' => 'utf8mb4'],
            'pgsql' => [],
            default => [],
        };

        return array_merge($defaults, $options);
    }

    private static function normaliseDriver(string $driver): string
    {
        return match (strtolower($driver)) {
            'mysql' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            default => strtolower($driver),
        };
    }
}