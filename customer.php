<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerCompanyRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Documents\DocumentRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$customerRepository = new CustomerRepository($pdo);
$customerCompanyRepository = new CustomerCompanyRepository($pdo);
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
$profileErrors = [];
$companyErrors = [];
$generalError = '';
$companyGeneralError = '';
$companyModalShouldOpen = false;
$personalModalShouldOpen = false;
$successMessage = '';
$suspiciousErrors = [];
$suspiciousGeneralError = '';
$suspiciousFormData = [
    'suspicious_reason' => (string) ($customer['suspicious_reason'] ?? ''),
];
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

$company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

$companyFormData = [
    'company_name' => (string) ($company['name'] ?? ''),
    'company_kvk' => (string) ($company['kvk'] ?? ''),
    'company_btw' => (string) ($company['btw'] ?? ''),
    'company_contact_person' => (string) ($company['contact_person'] ?? $fullName),
    'company_email' => (string) ($company['email'] ?? ($customer['email'] ?? '')),
    'company_phone' => (string) ($company['phone'] ?? ($customer['phone'] ?? '')),
    'company_address' => (string) ($company['address'] ?? ''),
    'company_postal_code' => (string) ($company['postal_code'] ?? ''),
    'company_city' => (string) ($company['city'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $generalError = __('messages.session_expired');
    } else {
        $formType = isset($_POST['form_type']) ? (string) $_POST['form_type'] : 'profile';

        if ($formType === 'company') {
            try {
                $companyFormData['company_name'] = InputValidator::requireString($_POST, 'company_name', 191);
                $companyFormData['company_kvk'] = InputValidator::requireString($_POST, 'company_kvk', 32);
                $companyFormData['company_btw'] = InputValidator::optionalString($_POST, 'company_btw', 32);
                $companyFormData['company_contact_person'] = InputValidator::requireString($_POST, 'company_contact_person', 191);
                $companyFormData['company_email'] = InputValidator::optionalEmail($_POST, 'company_email', 191);
                $companyFormData['company_phone'] = InputValidator::optionalPhone($_POST, 'company_phone', 32);
                $companyFormData['company_address'] = InputValidator::optionalString($_POST, 'company_address', 255);
                $companyFormData['company_postal_code'] = InputValidator::optionalString($_POST, 'company_postal_code', 16);
                $companyFormData['company_city'] = InputValidator::optionalString($_POST, 'company_city', 120);

                if ($companyFormData['company_address'] === '' && isset($customer['address'])) {
                    $companyFormData['company_address'] = (string) $customer['address'];
                }

                if ($companyFormData['company_postal_code'] === '' && isset($customer['postal_code'])) {
                    $companyFormData['company_postal_code'] = (string) $customer['postal_code'];
                }

                if ($companyFormData['company_city'] === '' && isset($customer['city'])) {
                    $companyFormData['company_city'] = (string) $customer['city'];
                }

                $company = $customerCompanyRepository->upsert(
                    (int) $customer['id'],
                    $companyFormData['company_name'],
                    $companyFormData['company_kvk'],
                    $companyFormData['company_btw'] !== '' ? $companyFormData['company_btw'] : null,
                    $companyFormData['company_contact_person'],
                    $companyFormData['company_email'] !== '' ? $companyFormData['company_email'] : null,
                    $companyFormData['company_phone'] !== '' ? $companyFormData['company_phone'] : null,
                    $companyFormData['company_address'] !== '' ? $companyFormData['company_address'] : null,
                    $companyFormData['company_postal_code'] !== '' ? $companyFormData['company_postal_code'] : null,
                    $companyFormData['company_city'] !== '' ? $companyFormData['company_city'] : null
                );

                $customerRepository->updateType((int) $customer['id'], 'business');
                $customer = $customerRepository->findById((int) $customer['id']);

                $successMessage = __('customers.messages.company_saved');
            } catch (ValidationException $exception) {
                $companyErrors = $exception->errors();
                $companyModalShouldOpen = true;
            } catch (Throwable $exception) {
                $companyGeneralError = __('customers.messages.company_failed');
                $companyModalShouldOpen = true;
            }
        } elseif ($formType === 'company_delete') {
            try {
                $deleted = $customerCompanyRepository->deleteByCustomerId((int) $customer['id']);

                if ($deleted) {
                    $company = null;
                    $companyFormData = [
                        'company_name' => '',
                        'company_kvk' => '',
                        'company_btw' => '',
                        'company_contact_person' => $fullName,
                        'company_email' => (string) ($customer['email'] ?? ''),
                        'company_phone' => (string) ($customer['phone'] ?? ''),
                        'company_address' => '',
                        'company_postal_code' => '',
                        'company_city' => '',
                    ];

                    $customerRepository->updateType((int) $customer['id'], 'private');
                    $customer = $customerRepository->findById((int) $customer['id']);

                    $successMessage = __('customers.messages.company_deleted');
                } else {
                    $companyGeneralError = __('customers.messages.company_delete_failed');
                }
            } catch (Throwable $exception) {
                $companyGeneralError = __('customers.messages.company_delete_failed');
            }
            } elseif ($formType === 'suspicious_mark') {
            try {
                $reason = InputValidator::requireString($_POST, 'suspicious_reason', 255);

                $customerRepository->markSuspicious((int) $customer['id'], $reason);
                $customer = $customerRepository->findById((int) $customer['id']);
                $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                $suspiciousFormData['suspicious_reason'] = (string) ($customer['suspicious_reason'] ?? '');
                $successMessage = __('customers.messages.suspicious_marked');
            } catch (ValidationException $exception) {
                $suspiciousErrors = $exception->errors();
                $suspiciousFormData['suspicious_reason'] = (string) ($_POST['suspicious_reason'] ?? '');
            } catch (Throwable $exception) {
                $suspiciousGeneralError = __('customers.messages.suspicious_failed');
                $suspiciousFormData['suspicious_reason'] = (string) ($_POST['suspicious_reason'] ?? '');
            }
        } elseif ($formType === 'suspicious_clear') {
            try {
                $customerRepository->clearSuspicious((int) $customer['id']);
                $customer = $customerRepository->findById((int) $customer['id']);
                $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                $suspiciousFormData['suspicious_reason'] = '';
                $successMessage = __('customers.messages.suspicious_cleared');
            } catch (Throwable $exception) {
                $suspiciousGeneralError = __('customers.messages.suspicious_clear_failed');
            }
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
                $company = $customerCompanyRepository->findByCustomerId((int) $customer['id']);

                $successMessage = __('customers.messages.updated');
            } catch (ValidationException $exception) {
                $profileErrors = $exception->errors();
                $personalModalShouldOpen = true;
            } catch (Throwable $exception) {
                $generalError = __('customers.messages.update_failed');
                $personalModalShouldOpen = true;
            }
        }
    }
}

if ($company !== null) {
    $companyFormData = [
        'company_name' => (string) ($company['name'] ?? ''),
        'company_kvk' => (string) ($company['kvk'] ?? ''),
        'company_btw' => (string) ($company['btw'] ?? ''),
        'company_contact_person' => (string) ($company['contact_person'] ?? $fullName),
        'company_email' => (string) ($company['email'] ?? ''),
        'company_phone' => (string) ($company['phone'] ?? ''),
        'company_address' => (string) ($company['address'] ?? ''),
        'company_postal_code' => (string) ($company['postal_code'] ?? ''),
        'company_city' => (string) ($company['city'] ?? ''),
    ];
} elseif (!isset($companyFormData['company_contact_person']) || $companyFormData['company_contact_person'] === '') {
    $companyFormData['company_contact_person'] = $fullName;
}

$companyAddressDefaults = [
    'address' => (string) ($customer['address'] ?? ''),
    'postal_code' => (string) ($customer['postal_code'] ?? ''),
    'city' => (string) ($customer['city'] ?? ''),
];

$cases = $caseRepository->forCustomer((int) $customer['id'], 25);
$documents = $documentRepository->forCustomer((int) $customer['id'], 25);

$isSuspicious = isset($customer['is_suspicious']) && (int) $customer['is_suspicious'] === 1;
$currentSuspiciousReason = $isSuspicious ? (string) ($customer['suspicious_reason'] ?? '') : '';

if ($isSuspicious && $suspiciousFormData['suspicious_reason'] === '') {
    $suspiciousFormData['suspicious_reason'] = $currentSuspiciousReason;
}

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

      <?php if ($isSuspicious): ?>
        <div class="profile-suspicious-banner" role="alert">
          <div>
            <p class="profile-suspicious-banner__title"><?= htmlspecialchars(__('customers.profile.suspicious.banner_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <p class="profile-suspicious-banner__reason">
              <?= htmlspecialchars(
                  $currentSuspiciousReason !== ''
                      ? __('customers.profile.suspicious.banner_reason', ['reason' => $currentSuspiciousReason])
                      : __('customers.profile.suspicious.banner_reason_empty'),
                  ENT_QUOTES | ENT_SUBSTITUTE,
                  'UTF-8'
              ) ?>
            </p>
          </div>
        </div>
      <?php endif; ?>

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
          <button type="button" class="btn btn--ghost" data-modal-target="customer-company-modal">
            <?= htmlspecialchars($company !== null ? __('customers.profile.company.edit_button') : __('customers.profile.company.add_button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        </div>
      </header>

      <?php if (!empty($successMessage)): ?>
        <div class="alert alert--success">
          <?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($generalError !== ''): ?>
        <div class="alert alert--danger">
          <?= htmlspecialchars($generalError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($companyGeneralError !== ''): ?>
        <div class="alert alert--danger">
          <?= htmlspecialchars($companyGeneralError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </div>
        <?php endif; ?>

      <section class="profile-suspicious<?= $isSuspicious ? ' profile-suspicious--active' : '' ?>" aria-labelledby="profile-suspicious-title">
        <div class="profile-suspicious__header">
          <div>
            <h3 id="profile-suspicious-title"><?= htmlspecialchars(__('customers.profile.suspicious.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(__('customers.profile.suspicious.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </div>

        <?php if ($suspiciousGeneralError !== ''): ?>
          <div class="alert alert--danger profile-suspicious__alert">
            <?= htmlspecialchars($suspiciousGeneralError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </div>
        <?php endif; ?>

        <div class="profile-suspicious__forms">
          <form method="post" class="profile-suspicious__form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="form_type" value="suspicious_mark">
            <label class="profile-suspicious__label" for="suspicious-reason">
              <?= htmlspecialchars(__('customers.profile.suspicious.reason_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </label>
            <textarea id="suspicious-reason" name="suspicious_reason" rows="3" class="profile-suspicious__input<?= isset($suspiciousErrors['suspicious_reason']) ? ' profile-suspicious__input--error' : '' ?>" placeholder="<?= htmlspecialchars(__('customers.profile.suspicious.reason_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($suspiciousFormData['suspicious_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <?php if (isset($suspiciousErrors['suspicious_reason'])): ?>
              <p class="profile-suspicious__error">
                <?= htmlspecialchars((string) $suspiciousErrors['suspicious_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
            <?php else: ?>
              <p class="profile-suspicious__hint">
                <?= htmlspecialchars(__('customers.profile.suspicious.reason_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
            <?php endif; ?>
            <div class="profile-suspicious__actions">
              <button type="submit" class="btn btn--danger">
                <?= htmlspecialchars(
                    $isSuspicious
                        ? __('customers.profile.suspicious.update_button')
                        : __('customers.profile.suspicious.mark_button'),
                    ENT_QUOTES | ENT_SUBSTITUTE,
                    'UTF-8'
                ) ?>
              </button>
            </div>
          </form>

          <?php if ($isSuspicious): ?>
            <form method="post" class="profile-suspicious__form profile-suspicious__form--clear">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="form_type" value="suspicious_clear">
              <div class="profile-suspicious__actions">
                <button type="submit" class="btn btn--ghost profile-suspicious__clear-button">
                  <?= htmlspecialchars(__('customers.profile.suspicious.clear_button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </button>
              </div>
            </form>
          <?php endif; ?>
        </div>
      </section>

      <div class="profile-personal">
        <div class="profile-personal__header">
          <div>
            <h3><?= htmlspecialchars(__('customers.profile.personal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(__('customers.profile.personal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          </div>
        <dl class="profile-personal__details">
          <div class="profile-personal__row">
            <dt><?= htmlspecialchars(__('customers.form.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd><?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="profile-personal__row profile-personal__row--split">
            <div>
              <dt><?= htmlspecialchars(__('customers.form.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($customer['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div>
              <dt><?= htmlspecialchars(__('customers.form.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($customer['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            </div>
          <div class="profile-personal__row">
            <dt><?= htmlspecialchars(__('customers.form.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd><?= htmlspecialchars((string) ($customer['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="profile-personal__row profile-personal__row--split">
            <div>
              <dt><?= htmlspecialchars(__('customers.form.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($customer['postal_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div>
              <dt><?= htmlspecialchars(__('customers.form.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($customer['city'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          </div>
        </dl>
        <div class="profile-personal__actions">
          <button type="button" class="btn btn--secondary" data-modal-target="customer-personal-modal">
            <?= htmlspecialchars(__('customers.profile.edit.button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </button>
        </div>
      </div>

      <div class="profile-company" data-company-panel>
        <div class="profile-company__header">
          <div>
            <h3><?= htmlspecialchars(__('customers.profile.company.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(__('customers.profile.company.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <span class="profile-company__badge<?= $company !== null ? '' : ' profile-company__badge--muted' ?>">
            <?= htmlspecialchars($company !== null ? __('customers.profile.company.linked') : __('customers.profile.company.unlinked'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </span>
        </div>

        <?php if ($company !== null): ?>
          <dl class="profile-company__details">
            <div class="profile-company__row">
              <dt><?= htmlspecialchars(__('customers.profile.company.company_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($company['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="profile-company__row">
              <dt><?= htmlspecialchars(__('customers.profile.company.contact_person'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars((string) ($company['contact_person'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="profile-company__row profile-company__row--split">
              <div>
                <dt><?= htmlspecialchars(__('customers.profile.company.kvk'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars((string) ($company['kvk'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <div>
                <dt><?= htmlspecialchars(__('customers.profile.company.btw'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars((string) (($company['btw'] ?? '') !== '' ? $company['btw'] : __('customers.profile.company.empty_value')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
            </div>
            <div class="profile-company__row profile-company__row--split">
              <div>
                <dt><?= htmlspecialchars(__('customers.profile.company.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars((string) (($company['email'] ?? '') !== '' ? $company['email'] : __('customers.profile.company.empty_value')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <div>
                <dt><?= htmlspecialchars(__('customers.profile.company.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= htmlspecialchars((string) (($company['phone'] ?? '') !== '' ? $company['phone'] : __('customers.profile.company.empty_value')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
            </div>
            <div class="profile-company__row">
              <dt><?= htmlspecialchars(__('customers.profile.company.private_address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd>
                <span><?= htmlspecialchars((string) ($customer['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><br>
                <span><?= htmlspecialchars(trim(((string) ($customer['postal_code'] ?? '')) . ' ' . ((string) ($customer['city'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </dd>
            </div>
            <div class="profile-company__row">
              <dt><?= htmlspecialchars(__('customers.profile.company.company_address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd>
                <span><?= htmlspecialchars((string) ($company['address'] ?? ($customer['address'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><br>
                <span><?= htmlspecialchars(trim(((string) (($company['postal_code'] ?? '') !== '' ? $company['postal_code'] : ($customer['postal_code'] ?? ''))) . ' ' . ((string) (($company['city'] ?? '') !== '' ? $company['city'] : ($customer['city'] ?? '')))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </dd>
            </div>
            <div class="profile-company__actions">
              <button type="button" class="btn btn--secondary" data-modal-target="customer-company-modal">
                <?= htmlspecialchars(__('customers.profile.company.edit_button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </button>
              <form action="customer.php?id=<?= (int) $customer['id'] ?>" method="post" class="profile-company__delete" onsubmit="return confirm('<?= addslashes(__('customers.profile.company.delete_confirm')) ?>');">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="form_type" value="company_delete">
                <button type="submit" class="btn btn--danger">
                  <?= htmlspecialchars(__('customers.profile.company.delete_button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </button>
              </form>
            </div>
          </dl>
        <?php else: ?>
          <div class="profile-company__empty">
            <p><?= htmlspecialchars(__('customers.profile.company.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <button type="button" class="btn btn--secondary" data-modal-target="customer-company-modal">
              <?= htmlspecialchars(__('customers.profile.company.add_button'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </button>
          </div>
        <?php endif; ?>
      </div>
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
  <div class="modal" id="customer-company-modal" role="dialog" aria-modal="true" aria-labelledby="customer-company-modal-title">
    <div class="modal__panel">
      <form action="customer.php?id=<?= (int) $customer['id'] ?>" method="post">
        <div class="modal__header">
          <h2 id="customer-company-modal-title"><?= htmlspecialchars(__('customers.profile.company_modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="modal__body">
          <p><?= htmlspecialchars(__('customers.profile.company_modal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="form_type" value="company">
          <div class="form-grid company-form">
            <div class="form-field form-field--wide">
              <label for="companyName"><?= htmlspecialchars(__('customers.profile.company_modal.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyName" type="text" name="company_name" value="<?= htmlspecialchars($companyFormData['company_name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191">
              <?php if (!empty($companyErrors['company_name'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyKvk"><?= htmlspecialchars(__('customers.profile.company_modal.kvk'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyKvk" type="text" name="company_kvk" value="<?= htmlspecialchars($companyFormData['company_kvk'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="32">
              <?php if (!empty($companyErrors['company_kvk'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_kvk'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyBtw"><?= htmlspecialchars(__('customers.profile.company_modal.btw'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyBtw" type="text" name="company_btw" value="<?= htmlspecialchars($companyFormData['company_btw'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="32">
              <?php if (!empty($companyErrors['company_btw'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_btw'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field form-field--wide">
              <label for="companyContactPerson"><?= htmlspecialchars(__('customers.profile.company_modal.contact_person'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyContactPerson" type="text" name="company_contact_person" value="<?= htmlspecialchars($companyFormData['company_contact_person'] ?? $fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191">
              <?php if (!empty($companyErrors['company_contact_person'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_contact_person'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyEmail"><?= htmlspecialchars(__('customers.profile.company_modal.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyEmail" type="email" name="company_email" value="<?= htmlspecialchars($companyFormData['company_email'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191">
              <?php if (!empty($companyErrors['company_email'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyPhone"><?= htmlspecialchars(__('customers.profile.company_modal.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyPhone" type="tel" name="company_phone" value="<?= htmlspecialchars($companyFormData['company_phone'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="32">
              <?php if (!empty($companyErrors['company_phone'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
          </div>

          <?php $companyAddressVisible = ($companyFormData['company_address'] ?? '') !== '' || ($companyFormData['company_postal_code'] ?? '') !== '' || ($companyFormData['company_city'] ?? '') !== ''; ?>
          <div class="company-address-toggle">
            <button
              type="button"
              class="btn btn--ghost"
              data-company-address-toggle
              data-label-add="<?= htmlspecialchars(__('customers.profile.company_modal.add_address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-label-hide="<?= htmlspecialchars(__('customers.profile.company_modal.hide_address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-address-expanded="<?= $companyAddressVisible ? 'true' : 'false' ?>"
              aria-expanded="<?= $companyAddressVisible ? 'true' : 'false' ?>"
            >
              <?= htmlspecialchars($companyAddressVisible ? __('customers.profile.company_modal.hide_address') : __('customers.profile.company_modal.add_address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </button>
          </div>

          <div
            class="company-address-fields<?= $companyAddressVisible ? ' is-visible' : '' ?>"
            data-company-address-fields<?= $companyAddressVisible ? '' : ' hidden' ?>
            data-default-address="<?= htmlspecialchars($companyAddressDefaults['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            data-default-postal="<?= htmlspecialchars($companyAddressDefaults['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            data-default-city="<?= htmlspecialchars($companyAddressDefaults['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          >
            <div class="form-grid">
              <div class="form-field form-field--wide">
                <label for="companyAddress"><?= htmlspecialchars(__('customers.profile.company_modal.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input id="companyAddress" type="text" name="company_address" value="<?= htmlspecialchars($companyFormData['company_address'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="255"<?= $companyAddressVisible ? '' : ' disabled' ?>>
                <?php if (!empty($companyErrors['company_address'])): ?>
                  <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>
              <div class="form-field">
                <label for="companyPostalCode"><?= htmlspecialchars(__('customers.profile.company_modal.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input id="companyPostalCode" type="text" name="company_postal_code" value="<?= htmlspecialchars($companyFormData['company_postal_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="16"<?= $companyAddressVisible ? '' : ' disabled' ?>>
                <?php if (!empty($companyErrors['company_postal_code'])): ?>
                  <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>
              <div class="form-field">
                <label for="companyCity"><?= htmlspecialchars(__('customers.profile.company_modal.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input id="companyCity" type="text" name="company_city" value="<?= htmlspecialchars($companyFormData['company_city'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="120"<?= $companyAddressVisible ? '' : ' disabled' ?>>
                <?php if (!empty($companyErrors['company_city'])): ?>
                  <p class="form-error"><?= htmlspecialchars((string) $companyErrors['company_city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>
        <div class="modal__footer">
          <button type="button" class="btn btn--ghost" data-modal-close><?= htmlspecialchars(__('customers.form.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('customers.profile.company_modal.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>
  <div class="modal" id="customer-personal-modal" role="dialog" aria-modal="true" aria-labelledby="customer-personal-modal-title">
    <div class="modal__panel">
      <form action="customer.php?id=<?= (int) $customer['id'] ?>" method="post">
        <div class="modal__header">
          <h2 id="customer-personal-modal-title"><?= htmlspecialchars(__('customers.profile.personal_modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="modal__body">
          <p><?= htmlspecialchars(__('customers.profile.personal_modal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="form_type" value="profile">
          <div class="form-grid">
            <div class="form-field form-field--wide">
              <label for="customerModalFullName"><?= htmlspecialchars(__('customers.form.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalFullName" type="text" name="full_name" value="<?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191" autocomplete="name">
              <?php if (!empty($profileErrors['full_name'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="customerModalEmail"><?= htmlspecialchars(__('customers.form.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalEmail" type="email" name="email" value="<?= htmlspecialchars((string) ($customer['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="191" autocomplete="email">
              <?php if (!empty($profileErrors['email'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="customerModalPhone"><?= htmlspecialchars(__('customers.form.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalPhone" type="tel" name="phone" value="<?= htmlspecialchars((string) ($customer['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="32" autocomplete="tel">
              <?php if (!empty($profileErrors['phone'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field form-field--wide">
              <label for="customerModalAddress"><?= htmlspecialchars(__('customers.form.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalAddress" type="text" name="address" value="<?= htmlspecialchars((string) ($customer['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="255" autocomplete="street-address">
              <?php if (!empty($profileErrors['address'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="customerModalPostal"><?= htmlspecialchars(__('customers.form.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalPostal" type="text" name="postal_code" value="<?= htmlspecialchars((string) ($customer['postal_code'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="16" autocomplete="postal-code">
              <?php if (!empty($profileErrors['postal_code'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="customerModalCity"><?= htmlspecialchars(__('customers.form.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="customerModalCity" type="text" name="city" value="<?= htmlspecialchars((string) ($customer['city'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required maxlength="120" autocomplete="address-level2">
              <?php if (!empty($profileErrors['city'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $profileErrors['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal__footer">
          <button type="button" class="btn btn--ghost" data-modal-close><?= htmlspecialchars(__('customers.profile.edit.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('customers.form.save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>

  <script src="js/modals.js"></script>
  <script src="js/customer-profile.js"></script>
  <?php if ($companyModalShouldOpen): ?>
    <script>
      (function () {
        const modal = document.getElementById('customer-company-modal');
        if (!modal) {
          return;
        }
        modal.classList.add('is-visible');
        document.body.classList.add('modal-open');
        const firstField = modal.querySelector('input, select, textarea, button:not([data-modal-close])');
        if (firstField) {
          firstField.focus({ preventScroll: true });
        }
      })();
    </script>
  <?php endif; ?>
  <?php if ($personalModalShouldOpen): ?>
    <script>
      (function () {
        const modal = document.getElementById('customer-personal-modal');
        if (!modal) {
          return;
        }
        modal.classList.add('is-visible');
        document.body.classList.add('modal-open');
        const firstField = modal.querySelector('input, select, textarea, button:not([data-modal-close])');
        if (firstField) {
          firstField.focus({ preventScroll: true });
        }
      })();
    </script>
  <?php endif; ?>
</body>
</html>