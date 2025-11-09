<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Documents\DocumentRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$customerRepository = new CustomerRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$documentRepository = new DocumentRepository($pdo);

$customerId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$customerId) {
    Response::redirect('customers.php');
}

$customer = $customerRepository->findById((int) $customerId);
if ($customer === null) {
    Response::redirect('customers.php');
}

$csrfToken = Csrf::token();
$errors = [];
$successMessage = '';
$created = isset($_GET['created']) && $_GET['created'] === '1';
if ($created) {
    $successMessage = __('customers.messages.created');
}

$customerCode = isset($customer['customer_code']) && trim((string) $customer['customer_code']) !== ''
    ? (string) $customer['customer_code']
    : __('customers.list.table.no_code');
  
$fullName = (string) ($customer['full_name'] ?? '');
$initials = 'DV';
$trimmedName = trim($fullName);
if ($trimmedName !== '') {
    $nameParts = preg_split('/\s+/u', $trimmedName) ?: [];
    $nameParts = array_values(array_filter($nameParts, static fn ($part) => $part !== ''));

    if ($nameParts !== []) {
        $firstPart = (string) ($nameParts[0] ?? '');
        $lastPart = (string) ($nameParts[count($nameParts) - 1] ?? '');

        $firstInitial = $firstPart !== '' ? mb_substr($firstPart, 0, 1, 'UTF-8') : '';
        $secondInitial = '';

        if (count($nameParts) > 1 && $lastPart !== '') {
            $secondInitial = mb_substr($lastPart, 0, 1, 'UTF-8');
        } elseif (mb_strlen($firstPart, 'UTF-8') > 1) {
            $secondInitial = mb_substr($firstPart, 1, 1, 'UTF-8');
        }

        $initialsCandidate = trim($firstInitial . $secondInitial);
        if ($initialsCandidate !== '') {
            $initials = mb_strtoupper($initialsCandidate, 'UTF-8');
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['general'] = __('messages.session_expired');
    } else {
        try {
            $fullName = InputValidator::requireString($_POST, 'full_name', 191);
            $email = InputValidator::requireEmail($_POST, 'email', 191);
            $phone = InputValidator::requirePhone($_POST, 'phone', 32);
            $address = InputValidator::requireString($_POST, 'address', 255);
            $postalCode = InputValidator::requireString($_POST, 'postal_code', 16);
            $city = InputValidator::requireString($_POST, 'city', 120);

            $customerRepository->updateProfile(
                (int) $customer['id'],
                $fullName,
                $email,
                $phone,
                $address,
                $postalCode,
                $city
            );

            $customer = $customerRepository->findById((int) $customer['id']);
            $successMessage = __('customers.messages.updated');
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
        } catch (Throwable $exception) {
            $errors['general'] = __('customers.messages.update_failed');
        }
    }
}

$cases = $caseRepository->forCustomer((int) $customer['id'], 25);
$documents = $documentRepository->forCustomer((int) $customer['id'], 25);

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('customers.profile.meta.title', ['name' => (string) ($customer['full_name'] ?? '')]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
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
    <section class="card profile-card profile-hero">
      <div class="profile-hero__header">
        <span class="profile-avatar" aria-hidden="true"><?= htmlspecialchars($initials, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <div class="profile-hero__text">
          <p class="profile-hero__eyebrow"><?= htmlspecialchars(__('customers.profile.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h1 class="profile-hero__title"><?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <p class="profile-hero__intro"><?= htmlspecialchars(__('customers.profile.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div class="profile-hero__actions">
          <a href="intake.php" class="btn btn--secondary profile-hero__action"><?= htmlspecialchars(__('customers.profile.actions.intake'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </div>
      </div>

    <dl class="profile-meta">
        <div class="profile-meta__item">
          <dt class="profile-meta__label"><?= htmlspecialchars(__('customers.profile.details.code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
          <dd class="profile-meta__value">
            <span class="profile-meta__pill"><?= htmlspecialchars($customerCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </dd>
        </div>
        <div class="profile-meta__item">
          <dt class="profile-meta__label"><?= htmlspecialchars(__('customers.profile.details.registered'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
          <dd class="profile-meta__value">
            <?= htmlspecialchars(isset($customer['created_at']) ? date('d-m-Y H:i', strtotime((string) $customer['created_at'])) : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </dd>
        </div>
        <div class="profile-meta__item">
          <dt class="profile-meta__label"><?= htmlspecialchars(__('customers.profile.details.last_interaction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
          <dd class="profile-meta__value">
            <?= htmlspecialchars(isset($customer['last_interaction_at']) && $customer['last_interaction_at'] !== null ? date('d-m-Y H:i', strtotime((string) $customer['last_interaction_at'])) : __('customers.profile.details.never'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </dd>
        </div>
      </dl>
    </section>

    <section class="card profile-card profile-card--form">
      <header class="card__header card__header--with-actions">
        <div class="card__header-content">
          <h2><?= htmlspecialchars(__('customers.profile.details.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(__('customers.profile.details.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div class="card__header-actions">
          <button type="button" class="btn btn--secondary" data-modal-target="customer-edit-modal" data-customer-edit-trigger>
            <?= htmlspecialchars(__('customers.profile.edit.button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        </div>
      </header>

      <?php if (!empty($successMessage)): ?>
        <div class="alert alert--success">
          <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert--danger">
          <?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <form action="customer.php?id=<?= (int) $customer['id'] ?>" method="post" class="form-grid" data-customer-profile-form>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-field form-field--wide">
          <label for="customerFullName"><?= htmlspecialchars(__('customers.form.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerFullName" type="text" name="full_name" value="<?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191" autocomplete="name" data-customer-editable disabled>
          <?php if (!empty($errors['full_name'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerEmail"><?= htmlspecialchars(__('customers.form.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerEmail" type="email" name="email" value="<?= htmlspecialchars((string) ($customer['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191" autocomplete="email" data-customer-editable disabled>>
          <?php if (!empty($errors['email'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerPhone"><?= htmlspecialchars(__('customers.form.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerPhone" type="tel" name="phone" value="<?= htmlspecialchars((string) ($customer['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="32" autocomplete="tel" data-customer-editable disabled>>
          <?php if (!empty($errors['phone'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field form-field--wide">
          <label for="customerAddress"><?= htmlspecialchars(__('customers.form.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerAddress" type="text" name="address" value="<?= htmlspecialchars((string) ($customer['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="255" autocomplete="street-address" data-customer-editable disabled>>
          <?php if (!empty($errors['address'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerPostal"><?= htmlspecialchars(__('customers.form.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerPostal" type="text" name="postal_code" value="<?= htmlspecialchars((string) ($customer['postal_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="16" autocomplete="postal-code" data-customer-editable disabled>>
          <?php if (!empty($errors['postal_code'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-field">
          <label for="customerCity"><?= htmlspecialchars(__('customers.form.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <input id="customerCity" type="text" name="city" value="<?= htmlspecialchars((string) ($customer['city'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="120" autocomplete="address-level2" data-customer-editable disabled>>
          <?php if (!empty($errors['city'])): ?>
            <p class="form-error"><?= htmlspecialchars((string) $errors['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>
        <div class="form-actions">
          <button type="button" class="btn btn--ghost" data-customer-edit-cancel hidden disabled>
            <?= htmlspecialchars(__('customers.profile.edit.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
          <a href="customers.php" class="btn btn--ghost"><?= htmlspecialchars(__('customers.form.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <button type="submit" class="btn btn--primary" data-customer-edit-save disabled>
            <?= htmlspecialchars(__('customers.form.save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        </div>
      </form>
    </section>

    <section class="card profile-card profile-card--table">
      <header class="card__header">
        <h2><?= htmlspecialchars(__('customers.profile.cases.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(__('customers.profile.cases.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </header>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.summary'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.cases.table.actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($cases === []): ?>
              <tr>
                <td colspan="6"><?= htmlspecialchars(__('customers.profile.cases.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($cases as $case): ?>
                <tr>
                  <td><code><?= htmlspecialchars((string) ($case['reference_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars((string) ($case['type'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="status-pill <?= htmlspecialchars('status-pill--' . strtolower((string) ($case['status'] ?? 'open')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) ($case['status'] ?? 'open'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td>
                    <div class="table__primary"><?= htmlspecialchars((string) ($case['summary'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if (!empty($case['device_brand']) || !empty($case['device_model'])): ?>
                      <div class="table__secondary"><?= htmlspecialchars(trim(($case['device_brand'] ?? '') . ' ' . ($case['device_model'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars(isset($case['updated_at']) ? date('d-m-Y H:i', strtotime((string) $case['updated_at'])) : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><a href="case.php?id=<?= (int) $case['id'] ?>" class="btn btn--link"><?= htmlspecialchars(__('customers.profile.cases.table.view'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card profile-card profile-card--table">
      <header class="card__header">
        <h2><?= htmlspecialchars(__('customers.profile.documents.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(__('customers.profile.documents.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </header>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th><?= htmlspecialchars(__('customers.profile.documents.table.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.documents.table.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.documents.table.created'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.profile.documents.table.actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($documents === []): ?>
              <tr>
                <td colspan="4"><?= htmlspecialchars(__('customers.profile.documents.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($documents as $document): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($document['type'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><code><?= htmlspecialchars((string) ($document['reference_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars(isset($document['created_at']) ? date('d-m-Y H:i', strtotime((string) $document['created_at'])) : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><a href="<?= htmlspecialchars((string) ($document['file_path'] ?? '#'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" class="btn btn--link" target="_blank" rel="noopener">
                    <?= htmlspecialchars(__('customers.profile.documents.table.open'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <section class="card profile-card">
      <header class="card__header">
        <h2><?= htmlspecialchars(__('customers.profile.invoices.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(__('customers.profile.invoices.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </header>
      <p><?= htmlspecialchars(__('customers.profile.invoices.placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </section>
  </main>
  <div class="modal" id="customer-edit-modal" role="dialog" aria-modal="true" aria-labelledby="customer-edit-modal-title">
    <div class="modal__panel">
      <div class="modal__header">
        <h2 id="customer-edit-modal-title"><?= htmlspecialchars(__('customers.profile.edit.modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
      </div>
      <div class="modal__body">
        <p><?= htmlspecialchars(__('customers.profile.edit.modal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="modal__footer">
        <button type="button" class="btn btn--ghost" data-modal-close><?= htmlspecialchars(__('customers.form.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        <button type="button" class="btn btn--primary" data-customer-edit-confirm><?= htmlspecialchars(__('customers.profile.edit.modal.confirm'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </div>
    </div>
  </div>

  <script src="js/modals.js"></script>
  <script src="js/customer-profile.js"></script>
</body>
</html>