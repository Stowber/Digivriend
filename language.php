<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\EmployeeRepository;
use App\Support\Repositories\UserRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::redirect('index.php');
}

$redirect = (string) ($_POST['redirect'] ?? ($_SERVER['HTTP_REFERER'] ?? 'index.php'));

if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
    Response::redirect($redirect !== '' ? $redirect : 'index.php');
}

$requestedLocale = strtolower(trim((string) ($_POST['locale'] ?? '')));
$availableLocales = available_locales();

if (!in_array($requestedLocale, $availableLocales, true)) {
    $requestedLocale = Translator::locale();
}

$_SESSION['language'] = $requestedLocale;
Translator::setLocale($requestedLocale);

$employeeRepository = new EmployeeRepository($pdo);
$employeeRepository->updateLanguageByUsername(Auth::username(), $requestedLocale);

$userRepository = new UserRepository($pdo);
$userRepository->updateLanguageByUsername(Auth::username(), $requestedLocale);

Response::redirect($redirect !== '' ? $redirect : 'index.php');