<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\CustomerRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$customerRepository = new CustomerRepository($pdo);
$csrfToken = Csrf::token();
$errors = [];
$formData = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'postal_code' => '',
    'city' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['general'] = __('messages.session_expired');
    } else {
        try {
            $formData['full_name'] = InputValidator::requireString($_POST, 'full_name', 191);
            $formData['email'] = InputValidator::requireEmail($_POST, 'email', 191);
            $formData['phone'] = InputValidator::requirePhone($_POST, 'phone', 32);
            $formData['address'] = InputValidator::requireString($_POST, 'address', 255);
            $formData['postal_code'] = InputValidator::requireString($_POST, 'postal_code', 16);
            $formData['city'] = InputValidator::requireString($_POST, 'city', 120);

            $customer = $customerRepository->create(
                $formData['full_name'],
                $formData['email'],
                $formData['phone'],
                $formData['address'],
                $formData['postal_code'],
                $formData['city']
            );

            if (!isset($customer['id'])) {
                throw new RuntimeException('Failed to create customer');
            }

            Response::redirect('customer.php?id=' . (int) $customer['id'] . '&created=1');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        } catch (Throwable $exception) {
            $errors['general'] = __('customers.messages.create_failed');
        }
    }
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('customers.create.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/customers.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="logo__subtitle"><?= htmlspecialchars(__('dashboard.header.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('customers'); ?>
      </nav>
    </div>
  </header>

  <main class="container customers-page">
    <div class="page-header">
      <div>
        <p class="page-eyebrow"><?= htmlspecialchars(__('customers.create.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars(__('customers.create.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro"><?= htmlspecialchars(__('customers.create.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger">
        <?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <form action="customer-new.php" method="post" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-field form-field--wide">
          <label for="customerFullName"><?= htmlspecialchars(__('customers.form.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerFullName" type="text" name="full_name" required maxlength="191" value="<?= htmlspecialchars($formData['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="name">
          <?php if (!empty($errors['full_name'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerEmail"><?= htmlspecialchars(__('customers.form.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerEmail" type="email" name="email" required maxlength="191" value="<?= htmlspecialchars($formData['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="email">
          <?php if (!empty($errors['email'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerPhone"><?= htmlspecialchars(__('customers.form.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerPhone" type="tel" name="phone" required maxlength="32" value="<?= htmlspecialchars($formData['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="tel">
          <?php if (!empty($errors['phone'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field form-field--wide">
          <label for="customerAddress"><?= htmlspecialchars(__('customers.form.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerAddress" type="text" name="address" required maxlength="255" value="<?= htmlspecialchars($formData['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="street-address">
          <?php if (!empty($errors['address'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerPostal"><?= htmlspecialchars(__('customers.form.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerPostal" type="text" name="postal_code" required maxlength="16" value="<?= htmlspecialchars($formData['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="postal-code">
          <?php if (!empty($errors['postal_code'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerCity"><?= htmlspecialchars(__('customers.form.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerCity" type="text" name="city" required maxlength="120" value="<?= htmlspecialchars($formData['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" autocomplete="address-level2">
          <?php if (!empty($errors['city'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-actions">
          <a href="customers.php" class="btn btn--ghost"><?= htmlspecialchars(__('customers.form.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('customers.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>