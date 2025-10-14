<?php

declare(strict_types=1);

namespace App\Security;

use PDO;

final class Auth
{
    public static function attempt(PDO $pdo, string $username, string $password): bool
    {
        $statement = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user === false) {
            return false;
        }

        if (!password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];

        return true;
    }

    public static function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function check(): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        return isset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        if (!self::check()) {
            return null;
        }

        return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    }

    public static function username(): string
    {
        if (!self::check()) {
            return 'Onbekend';
        }

        return (string) ($_SESSION['username'] ?? 'Onbekend');
    }

    public static function role(): string
    {
        if (!self::check()) {
            return 'gast';
        }

        return (string) ($_SESSION['role'] ?? 'gast');
    }

    public static function authorize(array $allowedRoles): bool
    {
        $role = self::role();

        if ($allowedRoles === []) {
            return true;
        }

        return in_array($role, $allowedRoles, true);
    }
}