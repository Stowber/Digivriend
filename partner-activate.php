<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\PartnerRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';

$partnerRepository = new PartnerRepository($pdo);
$errors = [];
$successMessage = '';
$token = isset($_GET['token']) ? (string) $_GET['token'] : (string) ($_POST['token'] ?? '');
$partner = $token !== '' ? $partnerRepository->findByActivationToken($token) : null;

if ($partner === null) {
    $errors['general'] = __('partners.activation.invalid_token');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $partner !== null) {
    try {
        if (!Csrf::validate((string) ($_POST['csrf_token'] ?? ''))) {
            throw new ValidationException(['general' => __('messages.session_expired')]);
        }

        $password = InputValidator::requireString($_POST, 'password', 120);
        $passwordConfirm = InputValidator::requireString($_POST, 'password_confirm', 120);

        if (mb_strlen($password) < 8) {
            throw new ValidationException(['password' => __('partners.activation.password_length')]);
        }

        if ($password !== $passwordConfirm) {
            throw new ValidationException(['password_confirm' => __('partners.activation.password_mismatch')]);
        }

        $partnerRepository->activate((int) $partner['id'], password_hash($password, PASSWORD_DEFAULT));
        $successMessage = __('partners.activation.success');
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
  <title><?= htmlspecialchars(__('partners.activation.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/login.css">
</head>
<body class="auth-body">
  <main class="auth-container">
    <section class="auth-card">
      <h1><?= htmlspecialchars(__('partners.activation.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($successMessage !== ''): ?>
        <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>

      <?php if ($partner !== null && $successMessage === ''): ?>
        <form method="POST" class="auth-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="form-group">
            <label for="partner_code"><?= htmlspecialchars(__('partners.activation.partner_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="partner_code" name="partner_code" value="<?= htmlspecialchars((string) ($partner['partner_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" readonly class="readonly-field">
          </div>
          <div class="form-group">
            <label for="password"><?= htmlspecialchars(__('partners.activation.password'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="password" id="password" name="password" required autocomplete="new-password">
            <?php if (!empty($errors['password'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['password'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="password_confirm"><?= htmlspecialchars(__('partners.activation.password_confirm'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="password" id="password_confirm" name="password_confirm" required autocomplete="new-password">
            <?php if (!empty($errors['password_confirm'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['password_confirm'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <button type="submit" class="btn"><?= htmlspecialchars(__('partners.activation.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </form>
        <p class="auth-footer muted"><?= htmlspecialchars(__('partners.activation.helper'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php elseif ($successMessage !== ''): ?>
        <p class="auth-footer muted"><?= htmlspecialchars(__('partners.activation.awaiting_approval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>