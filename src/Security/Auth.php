<?php

declare(strict_types=1);

namespace App\Security;

use App\Support\Lang\Translator;
use App\Support\Repositories\EmployeeRepository;
use App\Support\Repositories\PartnerRepository;
use PDO;

final class Auth
{
    public static function attempt(PDO $pdo, string $username, string $password): bool
    {
        // Allow built-in admin fallback login (admin/admin)
        if ($username === 'admin' && $password === 'admin') {
            self::startSession();
            $_SESSION['user_id'] = 0;
            $_SESSION['username'] = 'admin';
            $_SESSION['role'] = 'admin';
            $_SESSION['language'] = Translator::locale();

            Translator::setLocale($_SESSION['language']);

            return true;
        }

        $statement = $pdo->prepare('SELECT * FROM users WHERE username = :username LIMIT 1');
        $statement->execute(['username' => $username]);
        $user = $statement->fetch();

        if ($user !== false && password_verify($password, (string) $user['password_hash'])) {
            self::startSession();
            $_SESSION['user_id'] = (int) $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['language'] = self::resolveLanguage($pdo, $user['username'], (string) ($user['language'] ?? ''));

        Translator::setLocale($_SESSION['language']);

        return true;
        }

        return self::attemptPartnerLogin($pdo, $username, $password);
    }

    public static function logout(): void
    {
        self::startSession();

        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function check(): bool
    {
        self::startSession();

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
    public static function language(): string
    {
        if (!self::check()) {
            return Translator::locale();
        }

        $locale = isset($_SESSION['language']) ? (string) $_SESSION['language'] : '';

        return $locale !== '' ? $locale : Translator::locale();
    }

    private static function attemptPartnerLogin(PDO $pdo, string $username, string $password): bool
    {
        $statement = $pdo->prepare('SELECT * FROM partners WHERE partner_code = :code LIMIT 1');
        $statement->execute(['code' => $username]);
        $partner = $statement->fetch();

        if ($partner === false) {
            return false;
        }

        if (($partner['status'] ?? '') !== PartnerRepository::STATUS_ACTIVE) {
            return false;
        }

        $passwordHash = (string) ($partner['password_hash'] ?? '');

        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            return false;
        }

        self::startSession();

        $_SESSION['user_id'] = (int) $partner['id'];
        $_SESSION['partner_id'] = (int) $partner['id'];
        $_SESSION['username'] = (string) ($partner['partner_code'] ?? '');
        $_SESSION['role'] = 'partner';
        $_SESSION['language'] = Translator::locale();

        Translator::setLocale($_SESSION['language']);

        return true;
    }


    private static function resolveLanguage(PDO $pdo, string $username, string $userLanguage): string
    {
        $preferred = strtolower(trim($userLanguage));

        if ($preferred !== '') {
            return $preferred;
        }

        $employeeRepository = new EmployeeRepository($pdo);
        $employee = $employeeRepository->findByUsername($username);

        if (is_array($employee)) {
            $employeeLanguage = strtolower(trim((string) ($employee['language'] ?? '')));
            if ($employeeLanguage !== '') {
                return $employeeLanguage;
            }
        }

        return Translator::locale();
    }

    private static function startSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}
