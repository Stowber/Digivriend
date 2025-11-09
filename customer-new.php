<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\CustomerCompanyRepository;
use App\Support\Repositories\CustomerRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$customerRepository = new CustomerRepository($pdo);
$customerCompanyRepository = new CustomerCompanyRepository($pdo);
$csrfToken = Csrf::token();
$errors = [];

$showTypeDialog = true;
$selectedType = 'private';

if (isset($_GET['type'])) {
    $candidateType = strtolower((string) $_GET['type']);
    if (in_array($candidateType, ['private', 'business'], true)) {
        $selectedType = $candidateType;
        $showTypeDialog = false;
    }
}

$formData = [
    'customer_type' => $selectedType,
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'postal_code' => '',
    'city' => '',
    'company_name' => '',
    'company_kvk' => '',
    'company_btw' => '',
    'company_contact_person' => '',
    'company_email' => '',
    'company_phone' => '',
    'company_address' => '',
    'company_postal_code' => '',
    'company_city' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $showTypeDialog = false;

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['general'] = __('messages.session_expired');
    } else {
      $postedType = isset($_POST['customer_type']) ? strtolower((string) $_POST['customer_type']) : '';
        if (!in_array($postedType, ['private', 'business'], true)) {
            $postedType = 'private';
        }
        $formData['customer_type'] = $postedType;

        try {
            $formData['full_name'] = InputValidator::requireString($_POST, 'full_name', 191);
            $formData['email'] = InputValidator::requireEmail($_POST, 'email', 191);
            $formData['phone'] = InputValidator::requirePhone($_POST, 'phone', 32);
            $formData['address'] = InputValidator::requireString($_POST, 'address', 255);
            $formData['postal_code'] = InputValidator::requireString($_POST, 'postal_code', 16);
            $formData['city'] = InputValidator::requireString($_POST, 'city', 120);

            if ($formData['customer_type'] === 'business') {
                $formData['company_name'] = InputValidator::requireString($_POST, 'company_name', 191);
                $formData['company_kvk'] = InputValidator::requireString($_POST, 'company_kvk', 32);
                $formData['company_btw'] = InputValidator::optionalString($_POST, 'company_btw', 32);
                $formData['company_contact_person'] = InputValidator::requireString($_POST, 'company_contact_person', 191);
                $formData['company_email'] = InputValidator::optionalEmail($_POST, 'company_email', 191);
                $formData['company_phone'] = InputValidator::optionalPhone($_POST, 'company_phone', 32);
                $formData['company_address'] = InputValidator::optionalString($_POST, 'company_address', 255);
                $formData['company_postal_code'] = InputValidator::optionalString($_POST, 'company_postal_code', 16);
                $formData['company_city'] = InputValidator::optionalString($_POST, 'company_city', 120);
            } else {
                $formData['company_name'] = '';
                $formData['company_kvk'] = '';
                $formData['company_btw'] = '';
                $formData['company_contact_person'] = '';
                $formData['company_email'] = '';
                $formData['company_phone'] = '';
                $formData['company_address'] = '';
                $formData['company_postal_code'] = '';
                $formData['company_city'] = '';
            }

            $pdo->beginTransaction();

            $customer = $customerRepository->create(
                $formData['full_name'],
                $formData['email'],
                $formData['phone'],
                $formData['address'],
                $formData['postal_code'],
                $formData['city'],
                $formData['customer_type']
            );

            if (!isset($customer['id'])) {
                throw new RuntimeException('Failed to create customer');
            }

            if ($formData['customer_type'] === 'business') {
                $customerCompanyRepository->upsert(
                    (int) $customer['id'],
                    $formData['company_name'],
                    $formData['company_kvk'],
                    $formData['company_btw'] !== '' ? $formData['company_btw'] : null,
                    $formData['company_contact_person'],
                    $formData['company_email'] !== '' ? $formData['company_email'] : null,
                    $formData['company_phone'] !== '' ? $formData['company_phone'] : null,
                    $formData['company_address'] !== '' ? $formData['company_address'] : null,
                    $formData['company_postal_code'] !== '' ? $formData['company_postal_code'] : null,
                    $formData['company_city'] !== '' ? $formData['company_city'] : null
                );
            }

            $pdo->commit();

            Response::redirect('customer.php?id=' . (int) $customer['id'] . '&created=1');
        } catch (ValidationException $exception) {
          if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            $errors = $exception->errors();
        } catch (Throwable $exception) {
          if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

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
  <script src="js/customer-new.js" defer></script>
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

  <main class="container customers-page customers-page--new">
    <div class="page-header">
      <div>
        <p class="page-eyebrow"><?= htmlspecialchars(__('customers.create.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars(__('customers.create.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro"><?= htmlspecialchars(__('customers.create.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="page-header__hint">
        <span class="chip" data-customer-type-label data-private-label="<?= htmlspecialchars(__('customers.create.type.private.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-business-label="<?= htmlspecialchars(__('customers.create.type.business.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?= htmlspecialchars(
              $formData['customer_type'] === 'business'
                  ? __('customers.create.type.business.label')
                  : __('customers.create.type.private.label'),
              ENT_QUOTES | ENT_SUBSTITUTE,
              'UTF-8'
          ) ?>
        </span>
        <button type="button" class="link-button" data-customer-type-open>
          <?= htmlspecialchars(__('customers.create.type.change'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
      </div>
    </div>

    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger">
        <?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section class="card customer-create-card">
      <header class="customer-create-card__header">
        <div>
          <p class="customers-eyebrow"><?= htmlspecialchars(__('customers.create.sections.personal.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h2><?= htmlspecialchars(__('customers.create.sections.personal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p class="customer-create-card__intro"><?= htmlspecialchars(__('customers.create.sections.personal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div class="customer-type-toggle" data-customer-type-toggle>
          <span class="customer-type-toggle__label"><?= htmlspecialchars(__('customers.create.type.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <div class="customer-type-toggle__buttons" role="radiogroup">
            <button type="button" class="customer-type-toggle__button" data-customer-type-option="private" role="radio" aria-checked="<?= $formData['customer_type'] === 'private' ? 'true' : 'false' ?>">
              <span class="customer-type-toggle__title"><?= htmlspecialchars(__('customers.create.type.private.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="customer-type-toggle__description"><?= htmlspecialchars(__('customers.create.type.private.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </button>
            <button type="button" class="customer-type-toggle__button" data-customer-type-option="business" role="radio" aria-checked="<?= $formData['customer_type'] === 'business' ? 'true' : 'false' ?>">
              <span class="customer-type-toggle__title"><?= htmlspecialchars(__('customers.create.type.business.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="customer-type-toggle__description"><?= htmlspecialchars(__('customers.create.type.business.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </button>
          </div>
        </div>
        </header>

      <form action="customer-new.php" method="post" class="customer-form" data-customer-form data-customer-type="<?= htmlspecialchars($formData['customer_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="customer_type" value="<?= htmlspecialchars($formData['customer_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-customer-type-input>

        <section class="form-section">
          <header class="form-section__header">
            <h3><?= htmlspecialchars(__('customers.create.sections.personal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(__('customers.create.sections.personal.help'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </header>
          <div class="form-grid">
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
          </div>
        </section>

        <section class="form-section form-section--company" data-company-fields>
          <header class="form-section__header">
            <h3><?= htmlspecialchars(__('customers.create.sections.company.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(__('customers.create.sections.company.help'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </header>
          <div class="form-grid form-grid--balanced">
            <div class="form-field form-field--wide">
              <label for="companyName"><?= htmlspecialchars(__('customers.profile.company_modal.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyName" type="text" name="company_name" maxlength="191" value="<?= htmlspecialchars($formData['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_name'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyKvk"><?= htmlspecialchars(__('customers.profile.company_modal.kvk'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyKvk" type="text" name="company_kvk" maxlength="32" value="<?= htmlspecialchars($formData['company_kvk'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_kvk'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_kvk'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyBtw"><?= htmlspecialchars(__('customers.profile.company_modal.btw'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyBtw" type="text" name="company_btw" maxlength="32" value="<?= htmlspecialchars($formData['company_btw'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_btw'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_btw'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyContactPerson"><?= htmlspecialchars(__('customers.profile.company_modal.contact_person'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyContactPerson" type="text" name="company_contact_person" maxlength="191" value="<?= htmlspecialchars($formData['company_contact_person'] !== '' ? $formData['company_contact_person'] : $formData['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_contact_person'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_contact_person'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyEmail"><?= htmlspecialchars(__('customers.profile.company_modal.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyEmail" type="email" name="company_email" maxlength="191" value="<?= htmlspecialchars($formData['company_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_email'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyPhone"><?= htmlspecialchars(__('customers.profile.company_modal.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyPhone" type="tel" name="company_phone" maxlength="32" value="<?= htmlspecialchars($formData['company_phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_phone'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field form-field--wide">
              <label for="companyAddress"><?= htmlspecialchars(__('customers.profile.company_modal.address'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyAddress" type="text" name="company_address" maxlength="255" value="<?= htmlspecialchars($formData['company_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_address'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyPostalCode"><?= htmlspecialchars(__('customers.profile.company_modal.postal_code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyPostalCode" type="text" name="company_postal_code" maxlength="16" value="<?= htmlspecialchars($formData['company_postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_postal_code'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="form-field">
              <label for="companyCity"><?= htmlspecialchars(__('customers.profile.company_modal.city'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
              <input id="companyCity" type="text" name="company_city" maxlength="120" value="<?= htmlspecialchars($formData['company_city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['company_city'])): ?>
                <p class="form-error"><?= htmlspecialchars((string) $errors['company_city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
          </div>
        </section>
        <div class="form-actions">
          <a href="customers.php" class="btn btn--ghost"><?= htmlspecialchars(__('customers.form.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('customers.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </section>
  </main>
  <div class="customer-type-dialog<?= $showTypeDialog ? ' is-visible' : '' ?>" data-customer-type-dialog aria-hidden="<?= $showTypeDialog ? 'false' : 'true' ?>">
    <div class="customer-type-dialog__content" role="dialog" aria-modal="true" aria-labelledby="customer-type-dialog-title" aria-describedby="customer-type-dialog-description">
      <h2 id="customer-type-dialog-title"><?= htmlspecialchars(__('customers.create.type.dialog_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      <p id="customer-type-dialog-description"><?= htmlspecialchars(__('customers.create.type.dialog_description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <div class="customer-type-dialog__options">
        <button type="button" class="customer-type-dialog__option" data-customer-type-choice="private">
          <span class="customer-type-dialog__label"><?= htmlspecialchars(__('customers.create.type.private.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customer-type-dialog__hint"><?= htmlspecialchars(__('customers.create.type.private.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </button>
        <button type="button" class="customer-type-dialog__option" data-customer-type-choice="business">
          <span class="customer-type-dialog__label"><?= htmlspecialchars(__('customers.create.type.business.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customer-type-dialog__hint"><?= htmlspecialchars(__('customers.create.type.business.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </button>
      </div>
    </div>
  </div>
</body>
</html>