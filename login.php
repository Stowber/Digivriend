<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
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
            throw new ValidationException(['general' => 'Ongeldige sessie.']);
        }

        $username = InputValidator::requireString($_POST, 'username', 120);
        $password = InputValidator::requireString($_POST, 'password', 120);

        if (!Auth::attempt($pdo, $username, $password)) {
            throw new ValidationException(['general' => 'Onjuiste combinatie van gebruikersnaam en wachtwoord.']);
        }

        Response::redirect($redirectTo !== '' ? $redirectTo : 'index.php');
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Inloggen - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/login.css">
</head>
<body class="auth-body">
  <main class="auth-container">
    <section class="auth-card">
      <h1>Inloggen</h1>
      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <form method="POST" class="auth-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-group">
          <label for="username">Gebruikersnaam</label>
          <input type="text" id="username" name="username" required autocomplete="username">
        </div>
        <div class="form-group">
          <label for="password">Wachtwoord</label>
          <input type="password" id="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn">Aanmelden</button>
      </form>
      <p class="auth-footer">Gebruik de beheerdersgegevens uit het .env-bestand om toegang te krijgen.</p>
    </section>
  </main>
</body>
</html>