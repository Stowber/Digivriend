<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\PartnerRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$partnerRepository = new PartnerRepository($pdo);
$notificationService = new NotificationService($pdo);

$errors = [];
$successMessage = '';
$approvalMessage = '';
$statusFilter = isset($_GET['status']) ? (string) $_GET['status'] : 'all';

$buildActivationLink = static function (string $token): string {
    $scheme = isset($_SERVER['REQUEST_SCHEME']) ? (string) $_SERVER['REQUEST_SCHEME'] : 'http';
    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : 'localhost';
    $base = rtrim($scheme . '://' . $host, '/');

    return $base . '/partner-activate.php?token=' . urlencode($token);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!Csrf::validate((string) ($_POST['csrf_token'] ?? ''))) {
            throw new ValidationException(['general' => __('messages.session_expired')]);
        }

        if ($action === 'register') {
            $fullName = InputValidator::requireString($_POST, 'full_name', 191);
            $companyName = InputValidator::requireString($_POST, 'company_name', 191);
            $email = InputValidator::requireEmail($_POST, 'email', 191);
            $phone = InputValidator::requirePhone($_POST, 'phone', 32);
            $address = InputValidator::requireString($_POST, 'address', 255);
            $vatNumber = InputValidator::requireString($_POST, 'vat_number', 64);
            $kvkNumber = InputValidator::requireString($_POST, 'kvk_number', 64);

            $partner = $partnerRepository->create(
                $fullName,
                $companyName,
                $email,
                $phone,
                $address,
                $vatNumber,
                $kvkNumber
            );

            if (!is_array($partner) || ($partner['activation_token'] ?? '') === '') {
                throw new ValidationException(['general' => __('partners.messages.creation_failed')]);
            }

            $activationLink = $buildActivationLink((string) $partner['activation_token']);
            $notificationService->sendPartnerActivation($partner, $activationLink);

            $successMessage = __('partners.messages.created', ['code' => (string) $partner['partner_code']]);
        } elseif ($action === 'approve') {
            if (Auth::role() !== 'admin') {
                throw new ValidationException(['general' => __('partners.messages.not_authorized')]);
            }

            $partnerId = (int) ($_POST['partner_id'] ?? 0);
            $partner = $partnerRepository->find($partnerId);

            if (!is_array($partner)) {
                throw new ValidationException(['general' => __('partners.messages.not_found')]);
            }

            if (($partner['status'] ?? '') !== PartnerRepository::STATUS_PENDING_APPROVAL) {
                throw new ValidationException(['general' => __('partners.messages.cannot_approve')]);
            }

            $partnerRepository->approve($partnerId, Auth::id());
            $approvalMessage = __('partners.messages.approved', ['code' => (string) ($partner['partner_code'] ?? '')]);
        }
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

$partners = $partnerRepository->all($statusFilter);
$csrfToken = Csrf::token();
$isAdmin = Auth::role() === 'admin';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('partners.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/partners.css">
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
        <?php render_main_nav('partners'); ?>
      </nav>
    </div>
  </header>
  <main class="container partners-page">
    <div class="page-header">
      <div>
        <p class="page-eyebrow"><?= htmlspecialchars(__('partners.hero.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars(__('partners.hero.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro"><?= htmlspecialchars(__('partners.hero.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--error">
        <?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert--success">
        <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <?php if ($approvalMessage !== ''): ?>
      <div class="alert alert--success">
        <?= htmlspecialchars($approvalMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section class="card partners-card" aria-labelledby="partners-register-title">
      <header class="partners-card__header">
        <div>
          <p class="partners-eyebrow"><?= htmlspecialchars(__('partners.register.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h2 id="partners-register-title"><?= htmlspecialchars(__('partners.register.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p class="partners-card__intro"><?= htmlspecialchars(__('partners.register.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
      </header>
      <form method="POST" class="partners-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="register">
        <div class="form-grid">
          <div class="form-group">
            <label for="full_name"><?= htmlspecialchars(__('partners.fields.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="full_name" name="full_name" required>
            <?php if (!empty($errors['full_name'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="company_name"><?= htmlspecialchars(__('partners.fields.company_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="company_name" name="company_name" required>
            <?php if (!empty($errors['company_name'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="email"><?= htmlspecialchars(__('partners.fields.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="email" id="email" name="email" required autocomplete="email">
            <?php if (!empty($errors['email'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="phone"><?= htmlspecialchars(__('partners.fields.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="tel" id="phone" name="phone" required autocomplete="tel">
            <?php if (!empty($errors['phone'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="address"><?= htmlspecialchars(__('partners.fields.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="address" name="address" required autocomplete="street-address">
            <?php if (!empty($errors['address'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="vat_number"><?= htmlspecialchars(__('partners.fields.vat_number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="vat_number" name="vat_number" required>
            <?php if (!empty($errors['vat_number'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['vat_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
          <div class="form-group">
            <label for="kvk_number"><?= htmlspecialchars(__('partners.fields.kvk_number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <input type="text" id="kvk_number" name="kvk_number" required>
            <?php if (!empty($errors['kvk_number'])): ?>
              <p class="form-error"><?= htmlspecialchars((string) $errors['kvk_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
        </div>
        <div class="form-actions">
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('partners.register.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <p class="muted"><?= htmlspecialchars(__('partners.register.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
      </form>
    </section>

    <section class="partners-list" aria-labelledby="partners-list-title">
      <header class="partners-card__header">
        <div>
          <p class="partners-eyebrow"><?= htmlspecialchars(__('partners.list.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h2 id="partners-list-title"><?= htmlspecialchars(__('partners.list.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p class="partners-card__intro"><?= htmlspecialchars(__('partners.list.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <form method="GET" class="partners-filter">
          <label for="status" class="sr-only"><?= htmlspecialchars(__('partners.list.filter_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select id="status" name="status" onchange="this.form.submit()">
            <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>><?= htmlspecialchars(__('partners.list.filter_all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <option value="pending_activation" <?= $statusFilter === PartnerRepository::STATUS_PENDING_ACTIVATION ? 'selected' : '' ?>><?= htmlspecialchars(__('partners.status.pending_activation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <option value="pending_approval" <?= $statusFilter === PartnerRepository::STATUS_PENDING_APPROVAL ? 'selected' : '' ?>><?= htmlspecialchars(__('partners.status.pending_approval'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <option value="active" <?= $statusFilter === PartnerRepository::STATUS_ACTIVE ? 'selected' : '' ?>><?= htmlspecialchars(__('partners.status.active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          </select>
        </form>
      </header>

      <?php if ($partners === []): ?>
        <p class="panel__empty"><?= htmlspecialchars(__('partners.list.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php else: ?>
        <div class="partners-grid" role="list">
          <?php foreach ($partners as $partner): ?>
            <?php $status = (string) ($partner['status'] ?? ''); ?>
            <article class="partner-card" role="listitem">
              <header class="partner-card__header">
                <div>
                  <p class="partner-card__code">#<?= htmlspecialchars((string) ($partner['partner_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <h3><?= htmlspecialchars((string) ($partner['company_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <p class="muted"><?= htmlspecialchars((string) ($partner['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                <span class="status-badge status-badge--<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?= htmlspecialchars(__('partners.status.' . $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </span>
              </header>
              <dl class="partner-card__details">
                <div>
                  <dt><?= htmlspecialchars(__('partners.fields.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                  <dd><?= htmlspecialchars((string) ($partner['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <div>
                  <dt><?= htmlspecialchars(__('partners.fields.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                  <dd><?= htmlspecialchars((string) ($partner['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <div>
                  <dt><?= htmlspecialchars(__('partners.fields.vat_number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                  <dd><?= htmlspecialchars((string) ($partner['vat_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <div>
                  <dt><?= htmlspecialchars(__('partners.fields.kvk_number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                  <dd><?= htmlspecialchars((string) ($partner['kvk_number'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
              </dl>

              <?php if ($status === PartnerRepository::STATUS_PENDING_APPROVAL && $isAdmin): ?>
                <form method="POST" class="partner-approval">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="approve">
                  <input type="hidden" name="partner_id" value="<?= htmlspecialchars((string) ($partner['id'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('partners.list.approve'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                  <p class="muted">
                    <?= htmlspecialchars(__('partners.list.approve_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </p>
                </form>
              <?php elseif ($status === PartnerRepository::STATUS_PENDING_ACTIVATION): ?>
                <p class="muted partner-awaiting"><?= htmlspecialchars(__('partners.list.awaiting_activation'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php else: ?>
                <p class="muted partner-active">
                  <?= htmlspecialchars(__('partners.list.active_since'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </p>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>