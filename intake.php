<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Lang\Translator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$csrfToken = Csrf::token();

$logoAriaLabel = __('dashboard.header.logo_aria');
$logoSubtitle = __('dashboard.header.subtitle');
$appName = __('app.name');

$heroTitle = __('intake.header.title');
$heroDescription = __('intake.header.description');
$heroAction = __('intake.header.action');
$heroStepsAria = __('intake.header.steps_aria');
$heroSteps = [
    [
        'number' => 1,
        'title' => __('intake.header.steps.capture.title'),
        'description' => __('intake.header.steps.capture.description'),
    ],
    [
        'number' => 2,
        'title' => __('intake.header.steps.schedule.title'),
        'description' => __('intake.header.steps.schedule.description'),
    ],
    [
        'number' => 3,
        'title' => __('intake.header.steps.confirm.title'),
        'description' => __('intake.header.steps.confirm.description'),
    ],
];

$infoCardTitle = __('intake.info_card.title');
$infoCardItems = [
    __('intake.info_card.items.email'),
    __('intake.info_card.items.cases'),
    __('intake.info_card.items.signature'),
];

$modalEyebrow = __('intake.modal.eyebrow');
$modalTitle = __('intake.modal.title');
$modalCloseLabel = __('common.close');

$customerPanelAria = __('intake.form.customer.aria');
$customerPanelTitle = __('intake.form.customer.title');
$stepperLabel = __('intake.form.stepper.aria');
$stepperBackLabel = __('intake.form.stepper.back');
$stepperSteps = [
    [
        'key' => 'customer',
        'title' => __('intake.form.stepper.steps.customer.title'),
        'description' => __('intake.form.stepper.steps.customer.description'),
    ],
    [
        'key' => 'visit',
        'title' => __('intake.form.stepper.steps.visit.title'),
        'description' => __('intake.form.stepper.steps.visit.description'),
    ],
];
$customerSearchLabel = __('intake.form.customer.search.label');
$customerSearchPlaceholder = __('intake.form.customer.search.placeholder');
$customerSearchHint = __('intake.form.customer.search.hint');
$customerSearchEmpty = __('intake.form.customer.search.empty');
$customerSelectedTitle = __('intake.form.customer.selected.title');
$customerSelectedFields = [
    'code' => __('intake.form.customer.selected.code'),
    'name' => __('intake.form.customer.selected.name'),
    'email' => __('intake.form.customer.selected.email'),
    'phone' => __('intake.form.customer.selected.phone'),
    'address' => __('intake.form.customer.selected.address'),
];
$customerSelectedEmpty = __('intake.form.customer.selected.empty');
$customerChangeLabel = __('intake.form.customer.selected.change');
$customerNextLabel = __('intake.form.customer.next');

$visitPanelAria = __('intake.form.visit.aria');
$visitPanelTitle = __('intake.form.visit.title');
$visitFields = [
    ['name' => 'appointment_at', 'label' => __('intake.form.visit.fields.appointment_at'), 'type' => 'datetime', 'attributes' => ['required' => true]],
    ['name' => 'device_type', 'label' => __('intake.form.visit.fields.device_type'), 'type' => 'select'],
    ['name' => 'device_brand', 'label' => __('intake.form.visit.fields.device_brand'), 'type' => 'text', 'attributes' => ['maxlength' => 120]],
    ['name' => 'device_model', 'label' => __('intake.form.visit.fields.device_model'), 'type' => 'text', 'attributes' => ['maxlength' => 191]],
    ['name' => 'device_serial', 'label' => __('intake.form.visit.fields.device_serial'), 'type' => 'text', 'attributes' => ['maxlength' => 120]],
    ['name' => 'problem_description', 'label' => __('intake.form.visit.fields.problem_description'), 'type' => 'textarea', 'wide' => true, 'attributes' => ['rows' => 4, 'maxlength' => 500, 'placeholder' => __('intake.form.visit.problem_placeholder')]],
];
$appointmentDateLabel = __('intake.form.visit.fields.appointment_date');
$appointmentTimeLabel = __('intake.form.visit.fields.appointment_time');
$appointmentHint = __('intake.form.visit.appointment_hint');
$deviceTypePlaceholder = __('intake.form.visit.device_type_placeholder');
$deviceTypeOptions = [
    '' => $deviceTypePlaceholder,
    'Laptop' => __('intake.form.visit.device_types.laptop'),
    'PC' => __('intake.form.visit.device_types.pc'),
    'Desktop' => __('intake.form.visit.device_types.desktop'),
    'Phone' => __('intake.form.visit.device_types.phone'),
    'Tablet' => __('intake.form.visit.device_types.tablet'),
    'Console' => __('intake.form.visit.device_types.console'),
];
$quickIssuesTitle = __('intake.form.visit.quick_issues.title');
$quickIssuesHint = __('intake.form.visit.quick_issues.hint');
$quickIssueOptions = [
    ['key' => 'bsod', 'label' => __('intake.form.visit.quick_issues.options.bsod')],
    ['key' => 'screen', 'label' => __('intake.form.visit.quick_issues.options.screen')],
    ['key' => 'power', 'label' => __('intake.form.visit.quick_issues.options.power')],
    ['key' => 'battery', 'label' => __('intake.form.visit.quick_issues.options.battery')],
    ['key' => 'keyboard', 'label' => __('intake.form.visit.quick_issues.options.keyboard')],
    ['key' => 'data', 'label' => __('intake.form.visit.quick_issues.options.data')],
];
$visitBackLabel = __('intake.form.visit.back');
$visitSubmitLabel = __('intake.form.visit.submit');

$resultTitle = __('intake.result.title');
$resultDescription = __('intake.result.description');
$resultReferenceLabel = __('intake.result.reference');
$resultAppointmentLabel = __('intake.result.appointment');
$resultPlaceholder = __('intake.result.placeholder');
$resultCaseLabel = __('intake.result.actions.case');
$resultDownloadLabel = __('intake.result.actions.download');

$feedbackLoading = __('intake.feedback.loading');
$feedbackError = __('intake.feedback.error');
$feedbackSuccess = __('intake.feedback.success');
$feedbackException = __('intake.feedback.exception');
$feedbackUnknown = __('intake.feedback.unknown');
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('intake.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/intake.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars($logoAriaLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title"><?= htmlspecialchars($appName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="logo__subtitle"><?= htmlspecialchars($logoSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('intake'); ?>
      </nav>
    </div>
  </header>

  <main class="container intake-page">
    <section class="page-hero">
      <div class="page-hero__content">
        <h1><?= htmlspecialchars($heroTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-hero__intro">
          <?= htmlspecialchars($heroDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </p>
        <button class="btn btn--primary" type="button" data-intake-open>
          <?= htmlspecialchars($heroAction, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
      </div>
      <ul class="page-hero__steps" aria-label="<?= htmlspecialchars($heroStepsAria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php foreach ($heroSteps as $step): ?>
          <li>
            <span class="page-hero__step-number"><?= (int) $step['number'] ?></span>
            <div>
              <strong><?= htmlspecialchars($step['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <p><?= htmlspecialchars($step['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>

    <section class="info-card">
      <h2><?= htmlspecialchars($infoCardTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      <ol class="info-card__list">
        <?php foreach ($infoCardItems as $item): ?>
          <li><?= htmlspecialchars($item, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ol>
    </section>
  </main>

  <div
    class="intake-modal"
    data-intake-modal
    hidden
    data-loading-message="<?= htmlspecialchars($feedbackLoading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-error-message="<?= htmlspecialchars($feedbackError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-success-message="<?= htmlspecialchars($feedbackSuccess, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-exception-message="<?= htmlspecialchars($feedbackException, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
    data-unknown-value="<?= htmlspecialchars($feedbackUnknown, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
  >
    <div class="intake-modal__backdrop" data-intake-close aria-hidden="true"></div>
    <div class="intake-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="intakeModalTitle">
      <header class="intake-modal__header">
        <p class="intake-modal__eyebrow"><?= htmlspecialchars($modalEyebrow, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h2 id="intakeModalTitle"><?= htmlspecialchars($modalTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="intake-modal__close" data-intake-close aria-label="<?= htmlspecialchars($modalCloseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
      </header>
      <div class="intake-modal__body">
        <form class="intake-form" id="intakeForm" novalidate>
          <nav class="intake-stepper" aria-label="<?= htmlspecialchars($stepperLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <ol class="intake-stepper__list" data-stepper>
              <?php foreach ($stepperSteps as $step): ?>
                <li
                  class="intake-stepper__item"
                  data-stepper-item
                  data-step="<?= htmlspecialchars($step['key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                >
                  <span class="intake-stepper__bullet" aria-hidden="true"></span>
                  <div class="intake-stepper__content">
                    <span class="intake-stepper__title"><?= htmlspecialchars($step['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <span class="intake-stepper__description"><?= htmlspecialchars($step['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                </li>
              <?php endforeach; ?>
            </ol>
            <button
              type="button"
              class="intake-stepper__back btn btn--ghost"
              data-stepper-back
              hidden
            >
              <?= htmlspecialchars($stepperBackLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </button>
          </nav>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <section class="intake-form__panel" data-step="customer" aria-label="<?= htmlspecialchars($customerPanelAria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <h3><?= htmlspecialchars($customerPanelTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <input type="hidden" name="customer_id" value="" data-customer-id data-customer-required-message="<?= htmlspecialchars(__('intake.form.customer.errors.required'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <div class="customer-picker">
              <label class="form-field form-field--wide">
                <span><?= htmlspecialchars($customerSearchLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input
                  type="search"
                  name="customer_search"
                  autocomplete="off"
                  placeholder="<?= htmlspecialchars($customerSearchPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-customer-search
                  aria-autocomplete="list"
                  aria-expanded="false"
                >
              </label>
              <p class="customer-picker__hint"><?= htmlspecialchars($customerSearchHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <div class="customer-picker__results" data-customer-results role="listbox" aria-live="polite"></div>
              <p class="customer-picker__empty" data-customer-empty hidden><?= htmlspecialchars($customerSearchEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <section class="customer-picker__selected" data-customer-selected hidden aria-live="polite" data-selected-empty="<?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <div class="customer-picker__selected-header">
                  <h4><?= htmlspecialchars($customerSelectedTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                  <button type="button" class="btn btn--ghost btn--small" data-customer-clear><?= htmlspecialchars($customerChangeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
                <dl class="customer-picker__details">
                  <div>
                    <dt><?= htmlspecialchars($customerSelectedFields['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd data-selected-code><?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                  <div>
                    <dt><?= htmlspecialchars($customerSelectedFields['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd data-selected-name><?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                  <div>
                    <dt><?= htmlspecialchars($customerSelectedFields['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd data-selected-email><?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                  <div>
                    <dt><?= htmlspecialchars($customerSelectedFields['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd data-selected-phone><?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                  <div>
                    <dt><?= htmlspecialchars($customerSelectedFields['address'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                    <dd data-selected-address><?= htmlspecialchars($customerSelectedEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                </dl>
              </section>
            </div>
            <footer class="intake-form__actions">
              <button type="button" class="btn btn--primary" data-next-step><?= htmlspecialchars($customerNextLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </footer>
          </section>

          <section class="intake-form__panel" data-step="visit" hidden aria-label="<?= htmlspecialchars($visitPanelAria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <h3><?= htmlspecialchars($visitPanelTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <div class="form-grid">
              <?php foreach ($visitFields as $field): ?>
                <?php $attributes = $field['attributes'] ?? []; ?>
                <?php if ($field['type'] === 'select'): ?>
                  <label class="form-field">
                    <span><?= htmlspecialchars($field['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <select name="<?= htmlspecialchars($field['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <?php foreach ($deviceTypeOptions as $value => $label): ?>
                        <option value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                      <?php endforeach; ?>
                    </select>
                  </label>
                  <?php elseif ($field['type'] === 'datetime'): ?>
                  <div class="form-field form-field--wide">
                    <span><?= htmlspecialchars($field['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <div class="schedule-picker" data-appointment-picker>
                      <div class="schedule-picker__fields">
                        <label class="schedule-picker__field">
                          <span><?= htmlspecialchars($appointmentDateLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <input
                            type="date"
                            name="appointment_date"
                            data-appointment-date
                            required
                          >
                        </label>
                        <label class="schedule-picker__field">
                          <span><?= htmlspecialchars($appointmentTimeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <input
                            type="time"
                            name="appointment_time"
                            data-appointment-time
                            required
                          >
                        </label>
                      </div>
                      <input type="hidden" name="<?= htmlspecialchars($field['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-appointment-target>
                      <?php if (!empty($appointmentHint)): ?>
                        <p class="schedule-picker__hint"><?= htmlspecialchars($appointmentHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php elseif ($field['type'] === 'textarea'): ?>
                  <label class="form-field<?= !empty($field['wide']) ? ' form-field--wide' : '' ?>">
                    <span><?= htmlspecialchars($field['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <textarea
                      name="<?= htmlspecialchars($field['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      <?php foreach ($attributes as $attr => $value): ?>
                        <?= ' ' . htmlspecialchars((string) $attr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>="<?= htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      <?php endforeach; ?>
                    ></textarea>
                    <?php if ($field['name'] === 'problem_description'): ?>
                      <div class="quick-issues" data-issue-list>
                        <div class="quick-issues__header">
                          <span class="quick-issues__title"><?= htmlspecialchars($quickIssuesTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <?php if (!empty($quickIssuesHint)): ?>
                            <p class="quick-issues__hint"><?= htmlspecialchars($quickIssuesHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                          <?php endif; ?>
                        </div>
                        <div class="quick-issues__chips">
                          <?php foreach ($quickIssueOptions as $issue): ?>
                            <button
                              type="button"
                              class="quick-issues__chip"
                              data-issue-value="<?= htmlspecialchars($issue['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                            >
                              <?= htmlspecialchars($issue['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </button>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </label>
                <?php else: ?>
                  <label class="form-field<?= !empty($field['wide']) ? ' form-field--wide' : '' ?>">
                    <span><?= htmlspecialchars($field['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <input
                      type="<?= htmlspecialchars($field['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      name="<?= htmlspecialchars($field['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      <?php foreach ($attributes as $attr => $value): ?>
                        <?php if (is_bool($value)): ?>
                          <?= $value ? ' ' . htmlspecialchars((string) $attr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '' ?>
                        <?php else: ?>
                          <?= ' ' . htmlspecialchars((string) $attr, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>="<?= htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        <?php endif; ?>
                      <?php endforeach; ?>
                    >
                  </label>
                <?php endif; ?>
              <?php endforeach; ?>
            </div>
            <footer class="intake-form__actions">
              <button type="button" class="btn btn--ghost" data-prev-step><?= htmlspecialchars($visitBackLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <button type="submit" class="btn btn--primary"><?= htmlspecialchars($visitSubmitLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </footer>
          </section>
        </form>

        <section class="intake-result" data-result hidden aria-live="polite" data-placeholder="<?= htmlspecialchars($resultPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="intake-result__icon" aria-hidden="true">✅</div>
          <h3><?= htmlspecialchars($resultTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
          <p><?= htmlspecialchars($resultDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <dl class="intake-result__summary">
            <div>
              <dt><?= htmlspecialchars($resultReferenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd data-result-reference><?= htmlspecialchars($resultPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div>
              <dt><?= htmlspecialchars($resultAppointmentLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd data-result-appointment><?= htmlspecialchars($resultPlaceholder, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          </dl>
          <div class="intake-result__actions">
            <a class="btn btn--primary" data-result-case href="#"><?= htmlspecialchars($resultCaseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
            <a class="btn btn--secondary" data-result-pdf href="#" target="_blank" rel="noopener"><?= htmlspecialchars($resultDownloadLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          </div>
        </section>

        <div class="intake-feedback" data-feedback hidden></div>
      </div>
    </div>
  </div>

  <script src="js/intake.js" defer></script>
</body>
</html>
