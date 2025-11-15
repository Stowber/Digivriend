<?php

declare(strict_types=1);

namespace App\Http;

use Closure;
use RuntimeException;

final class Router
{
    /**
     * @var array<string, array<string, Closure>>
     */
    private array $routes = [];

    public function get(string $path, callable $handler): void
    {
        $this->map('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->map('POST', $path, $handler);
    }

    public function map(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        $normalizedPath = $this->normalizePath($path);
        $this->routes[$method][$normalizedPath] = $this->ensureClosure($handler);
    }

    public function dispatch(string $method, ?string $path): string
    {
        $method = strtoupper($method);
        $normalizedPath = $this->normalizePath($path ?? '/');

        $handler = $this->routes[$method][$normalizedPath] ?? null;
        if ($handler === null) {
            http_response_code(404);
            return '<h1>404 Not Found</h1>';
        }

        return $handler();
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return rtrim($path, '/') ?: '/';
    }

    private function ensureClosure(callable $handler): Closure
    {
        if ($handler instanceof Closure) {
            return static function () use ($handler): string {
                $result = $handler();

                if (!is_string($result)) {
                    throw new RuntimeException('Route handlers must return a string response.');
                }

                return $result;
            };
        }

        if (is_array($handler) && is_object($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            return static function () use ($handler): string {
                $result = $handler[0]->{$handler[1]}();

                if (!is_string($result)) {
                    throw new RuntimeException('Route handlers must return a string response.');
                }

                return $result;
            };
        }

        if (is_array($handler) && is_string($handler[0] ?? null) && is_string($handler[1] ?? null)) {
            $className = $handler[0];
            $methodName = $handler[1];

            return static function () use ($className, $methodName): string {
                if (!class_exists($className)) {
                    throw new RuntimeException(sprintf('Controller %s not found.', $className));
                }

                $controller = new $className();
                $result = $controller->{$methodName}();

                if (!is_string($result)) {
                    throw new RuntimeException('Route handlers must return a string response.');
                }

                return $result;
            };
        }

        return static function () use ($handler): string {
            $result = $handler();
            if (!is_string($result)) {
                throw new RuntimeException('Route handlers must return a string response.');
            }

            return $result;
        };
    }
}