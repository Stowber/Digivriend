<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';

if (Auth::check()) {
    Response::redirect('index.php');
}

$errors = [];
$redirectTo = (string) ($_GET['redirect'] ?? 'index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirectTo = (string) ($_POST['redirect'] ?? $redirectTo);
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => __('auth.login.error.invalid_session')]);
        }

        $username = InputValidator::requireString($_POST, 'username', 120);
        $password = InputValidator::requireString($_POST, 'password', 120);

        if (!Auth::attempt($pdo, $username, $password)) {
            throw new ValidationException(['general' => __('auth.login.error.invalid_credentials')]);
        }

        Response::redirect($redirectTo !== '' ? $redirectTo : 'index.php');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('auth.login.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/login.css">
</head>
<body class="auth-body">
  <main class="auth-container">
    <section class="auth-card">
      <h1><?= htmlspecialchars(__('auth.login.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="POST" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-group">
          <label for="username"><?= htmlspecialchars(__('auth.login.username'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div class="form-group">
          <label for="password"><?= htmlspecialchars(__('auth.login.password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn"><?= htmlspecialchars(__('auth.login.button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </form>
      <p class="auth-footer"><?= htmlspecialchars(__('auth.login.footer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </section>
  </main>
</body>
</html>