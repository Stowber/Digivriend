<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Clock;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (Auth::role() !== 'partner') {
    Response::error(__('partner_case.errors.access_denied'), 403);
}

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error(__('partner_case.errors.invalid_case_id'), 400);
}

$caseRepository = new CaseRepository($pdo);
$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$errors = [];
$successMessage = '';

$statusLabels = [
    'awaiting_acceptance' => __('partner_case.status.awaiting_acceptance'),
    'diagnosis' => __('partner_case.status.diagnosis'),
    'estimate_submitted' => __('partner_case.status.estimate_submitted'),
    'counter_review' => __('partner_case.status.counter_review'),
    'estimate_declined' => __('partner_case.status.estimate_declined'),
    'repair_ready' => __('partner_case.status.repair_ready'),
    'repair_in_progress' => __('partner_case.status.repair_in_progress'),
    'correction_review' => __('partner_case.status.correction_review'),
    'archived' => __('partner_case.status.archived'),
];

$case = $caseRepository->findById((int) $caseId);

if ($case === null) {
    Response::error(__('partner_case.errors.case_not_found'), 404);
}

$details = [];
if (!empty($case['details'])) {
    $decoded = json_decode((string) $case['details'], true);
    if (is_array($decoded)) {
        $details = $decoded;
    }
}

$partnerId = isset($details['partner_id']) ? (int) $details['partner_id'] : null;
if ($partnerId !== Auth::id()) {
    Response::error(__('partner_case.errors.not_linked'), 403);
}

$workflow = [];
if (isset($details['partner_workflow']) && is_array($details['partner_workflow'])) {
    $workflow = $details['partner_workflow'];
}
$partnerStatus = isset($workflow['status']) ? (string) $workflow['status'] : 'awaiting_acceptance';
$archivedAt = isset($workflow['archived_at']) ? (string) $workflow['archived_at'] : '';
$archiveReason = isset($workflow['archive_reason']) ? (string) $workflow['archive_reason'] : '';
$partnerStatusLabel = $statusLabels[$partnerStatus] ?? ucfirst(str_replace('_', ' ', $partnerStatus));

$partnerEstimate = isset($workflow['estimate']) && is_array($workflow['estimate']) ? $workflow['estimate'] : null;
$partnerDecision = isset($workflow['decision']) && is_array($workflow['decision']) ? $workflow['decision'] : null;
$partnerEstimateHistory = isset($workflow['estimate_history']) && is_array($workflow['estimate_history'])
    ? $workflow['estimate_history']
    : [];
$correctionRequest = isset($workflow['correction_request']) && is_array($workflow['correction_request'])
    ? $workflow['correction_request']
    : null;
$isEstimateLocked = $partnerStatus === 'repair_ready';
$isEditingEstimate = filter_input(INPUT_GET, 'edit_estimate', FILTER_VALIDATE_BOOLEAN) === true;
$csrfToken = Csrf::token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => __('partner_case.form.errors.invalid_session')]);
        }

        $action = $_POST['action'] ?? '';

        if (!in_array($action, ['submit-estimate', 'submit-correction', 'archive-case'], true)) {
            throw new ValidationException(['general' => __('partner_case.form.errors.unknown_action')]);
        }

        if ($action === 'submit-estimate') {
            if ($partnerStatus === 'repair_ready') {
                throw new ValidationException(['general' => __('partner_case.form.errors.estimate_locked')]);
            }

            $amountRaw = InputValidator::requireString($_POST, 'estimate_amount', 32);
            $normalizedAmount = str_replace(',', '.', $amountRaw);
            $amount = filter_var($normalizedAmount, FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) {
                throw new ValidationException(['estimate_amount' => __('partner_case.form.errors.amount_positive')]);
            }

            $description = InputValidator::requireString($_POST, 'estimate_description', 500);

            $history = $partnerEstimateHistory;
            if ($partnerEstimate !== null) {
                $history[] = array_merge($partnerEstimate, [
                    'replaced_at' => Clock::nowFormatted(),
                    'replaced_by' => Auth::username(),
                ]);
            }

            $workflow['status'] = 'awaiting_acceptance';
            $workflow['estimate_history'] = $history;
            $workflow['estimate'] = [
                'amount' => round($amount, 2),
                'currency' => 'EUR',
                'description' => $description,
                'submitted_at' => Clock::nowFormatted(),
                'submitted_by' => Auth::username(),
            ];
            unset($workflow['decision'], $workflow['correction_request']);

            $details['partner_workflow'] = $workflow;
            $caseRepository->updateDetails((int) $caseId, $details);

            Response::redirect('partner-case.php?id=' . (int) $caseId . '&estimate_submitted=1');
        }

        if ($action === 'submit-correction') {
            if ($partnerStatus !== 'repair_ready') {
               throw new ValidationException(['general' => __('partner_case.form.errors.correction_requires_approval')]);
            }

            if (is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'pending') {
                throw new ValidationException(['general' => __('partner_case.form.errors.correction_pending')]);
            }

            $amountRaw = InputValidator::requireString($_POST, 'estimate_amount', 32);
            $normalizedAmount = str_replace(',', '.', $amountRaw);
            $amount = filter_var($normalizedAmount, FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) {
                throw new ValidationException(['estimate_amount' => __('partner_case.form.errors.amount_positive')]);
            }

            $description = InputValidator::requireString($_POST, 'estimate_description', 500);
            $errorDetails = InputValidator::optionalString($_POST, 'error_details', 300);

            $workflow['status'] = 'correction_review';
            $workflow['correction_request'] = [
                'status' => 'pending',
                'amount' => round($amount, 2),
                'currency' => 'EUR',
                'description' => $description,
                'submitted_at' => Clock::nowFormatted(),
                'submitted_by' => Auth::username(),
                'error_details' => $errorDetails !== '' ? $errorDetails : null,
            ];

            $details['partner_workflow'] = $workflow;
            $caseRepository->updateDetails((int) $caseId, $details);

            Response::redirect('partner-case.php?id=' . (int) $caseId . '&correction_submitted=1');
        }

        if ($action === 'archive-case') {
            if ($partnerStatus === 'archived') {
                throw new ValidationException(['general' => __('partner_case.form.errors.already_archived')]);
            }

            $archiveReasonInput = InputValidator::optionalString($_POST, 'archive_reason', 300);

            $workflow['status'] = 'archived';
            $workflow['archived_at'] = Clock::nowFormatted();
            $workflow['archive_reason'] = $archiveReasonInput !== '' ? $archiveReasonInput : null;
            $workflow['archived_by'] = Auth::username();

            $details['partner_workflow'] = $workflow;
            $caseRepository->updateDetails((int) $caseId, $details);

            Response::redirect('partner-case.php?id=' . (int) $caseId . '&archived=1');
        }
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

if (filter_input(INPUT_GET, 'estimate_submitted', FILTER_VALIDATE_BOOLEAN)) {
    $successMessage = __('partner_case.messages.estimate_submitted');
}

if (filter_input(INPUT_GET, 'correction_submitted', FILTER_VALIDATE_BOOLEAN)) {
    $successMessage = __('partner_case.messages.correction_submitted');
}

if (filter_input(INPUT_GET, 'archived', FILTER_VALIDATE_BOOLEAN)) {
    $successMessage = __('partner_case.messages.archived');
}

            $customer = $customerRepository->findById((int) ($case['customer_id'] ?? 0));
if ($customer === null) {
    Response::error(__('partner_case.errors.customer_not_found'), 404);
}

            $device = null;
if (!empty($case['device_id'])) {
    $device = $deviceRepository->findById((int) $case['device_id']);
}

$deviceBrand = $details['device_brand'] ?? ($device['brand'] ?? null);
$deviceModel = $details['device_model'] ?? ($device['model'] ?? null);
$deviceSerial = $details['device_serial'] ?? ($device['serial_number'] ?? null);
$deviceType = $details['device_type'] ?? ($device['device_type'] ?? null);
$deviceNotes = $details['device_notes'] ?? ($device['notes'] ?? null);

$deviceLabel = __('partner_case.device.unknown');
$deviceLabelParts = array_filter([$deviceBrand, $deviceModel], static fn ($part) => is_string($part) && trim((string) $part) !== '');
if ($deviceLabelParts !== []) {
    $deviceLabel = trim(implode(' ', $deviceLabelParts));
}

$problemDescription = '';
if (isset($details['problem_description']) && is_string($details['problem_description'])) {
    $problemDescription = trim($details['problem_description']);
}

$problemDescriptionText = $problemDescription;
if ($problemDescriptionText === '' && isset($case['summary']) && is_string($case['summary'])) {
    $problemDescriptionText = trim((string) $case['summary']);
}

$deviceNotesText = '';
if (is_string($deviceNotes)) {
    $deviceNotesText = trim((string) $deviceNotes);
}

$archiveLabel = $partnerStatus === 'archived' ? '' : __('partner_case.archive.badge');

$canEditEstimate = in_array($partnerStatus, ['awaiting_acceptance', 'estimate_submitted', 'counter_review'], true);
if ($partnerEstimate === null) {
    $canEditEstimate = true;
}

if (!$canEditEstimate) {
    $isEditingEstimate = false;
}

$showEstimateForm = $partnerEstimate === null || $isEditingEstimate;
$amountValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-estimate'
    ? (string) ($_POST['estimate_amount'] ?? '')
    : ($partnerEstimate['amount'] ?? '');
$descriptionValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-estimate'
    ? (string) ($_POST['estimate_description'] ?? '')
    : ($partnerEstimate['description'] ?? '');
$progressSteps = [
    1 => __('partner_case.progress.steps.reported'),
    2 => __('partner_case.progress.steps.estimate'),
    3 => __('partner_case.progress.steps.customer_decision'),
    4 => __('partner_case.progress.steps.repair'),
    5 => __('partner_case.progress.steps.archive'),
];
$progressPosition = [
    'awaiting_acceptance' => 2,
    'diagnosis' => 2,
    'estimate_submitted' => 2,
    'counter_review' => 2,
    'estimate_declined' => 3,
    'repair_ready' => 3,
    'correction_review' => 3,
    'repair_in_progress' => 4,
    'archived' => 5,
];
$canReportCorrection = $partnerStatus === 'repair_ready';
$pendingCorrection = is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'pending';
$correctionResponseLabel = '';
if (is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'approved') {
    $correctionResponseLabel = __('partner_case.corrections.approved');
} elseif (is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'declined') {
    $correctionResponseLabel = __('partner_case.corrections.declined');
}
$shouldOpenCorrectionModal = $_SERVER['REQUEST_METHOD'] === 'POST'
    && ($_POST['action'] ?? '') === 'submit-correction'
    && $errors !== [];
$archiveReasonValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive-case'
    ? (string) ($_POST['archive_reason'] ?? '')
    : $archiveReason;

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('partner_case.meta.title', ['reference' => $referenceLabel]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/partner-case-detail.css">
</head>
<body<?= platform_body_attributes(); ?>>
<header class="main-header">
  <div class="container">
    <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <span class="logo__mark" aria-hidden="true">DV</span>
      <span class="logo__text">
        <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </span>
    </a>
    <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <?php render_main_nav('partner_cases'); ?>
    </nav>
  </div>
</header>
<main class="container partner-case">
  <section class="partner-case__hero card card--glass">
    <div class="partner-case__intro">
      <div class="partner-case__eyebrow">
        <p class="eyebrow">Case #<?= htmlspecialchars((string) ($case['reference_code'] ?? $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <h1 class="partner-case__title"><?= htmlspecialchars((string) ($case['summary'] ?? __('partner_case.hero.fallback_title')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <p class="muted"><?= htmlspecialchars(__('partner_case.hero.device_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <div class="pill-row">
        <span class="pill pill--status"><?= htmlspecialchars(__('partner_case.hero.status_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <?php if ($archiveLabel !== ''): ?>
          <span class="pill pill--warning" aria-label="<?= htmlspecialchars(__('partner_case.hero.partner_status_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($archiveLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
      <?php if ($partnerStatus !== 'archived'): ?>
        <div class="progress-ribbon" role="list" aria-label="<?= htmlspecialchars(__('partner_case.progress.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?php $currentStep = $progressPosition[$partnerStatus] ?? 1; ?>
          <?php foreach ($progressSteps as $index => $label): ?>
            <div class="progress-ribbon__step<?= $index <= $currentStep ? ' is-active' : '' ?>" role="listitem">
              <span class="progress-ribbon__index">0<?= (int) $index ?></span>
              <span class="progress-ribbon__label"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($archivedAt !== '' || $archiveReason !== ''): ?>
        <p class="muted partner-case__archive">
          <?php if ($archivedAt !== ''): ?><?= htmlspecialchars(__('partner_case.hero.archived_at', ['date' => date('d-m-Y H:i', strtotime($archivedAt))]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
          <?php if ($archiveReason !== ''): ?><br><?= htmlspecialchars(__('partner_case.hero.archive_reason', ['reason' => $archiveReason]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  <aside class="partner-case__panel" aria-label="<?= htmlspecialchars(__('partner_case.customer.panel_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="info-card info-card--layered">
        <p class="eyebrow"><?= htmlspecialchars(__('partner_case.customer.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h2 class="info-card__title"><?= htmlspecialchars((string) ($customer['full_name'] ?? __('partner_case.customer.unknown')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <dl class="info-list">
          <div class="info-list__item">
            <dt><?= htmlspecialchars(__('partner_case.customer.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd id="client-phone"><?= htmlspecialchars((string) ($customer['phone'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="info-list__item">
            <dt><?= htmlspecialchars(__('partner_case.customer.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd id="client-email"><?= htmlspecialchars((string) ($customer['email'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="info-list__item">
            <dt><?= htmlspecialchars(__('partner_case.customer.code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd><?= htmlspecialchars((string) ($customer['customer_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        </dl>
        <div class="action-dropdown" data-dropdown>
         <button type="button" class="action-dropdown__trigger" data-dropdown-toggle aria-expanded="false"><?= htmlspecialchars(__('partner_case.customer.preferences.trigger'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <div class="dropdown-panel">
            <label class="form-field form-field--inline">
              <span class="form-field__label"><?= htmlspecialchars(__('partner_case.customer.preferences.channel_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <select name="contact_channel" class="pill-select" data-toast-on-change>
                <option value="call"><?= htmlspecialchars(__('partner_case.customer.preferences.channels.call'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <option value="email"><?= htmlspecialchars(__('partner_case.customer.preferences.channels.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <option value="sms"><?= htmlspecialchars(__('partner_case.customer.preferences.channels.sms'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              </select>
            </label>
            <label class="form-field form-field--inline">
              <span class="form-field__label"><?= htmlspecialchars(__('partner_case.customer.preferences.time_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <select name="contact_slot" class="pill-select" data-toast-on-change>
                <option value="morning">08:00-12:00</option>
                <option value="afternoon">12:00-16:00</option>
                <option value="evening">16:00-20:00</option>
              </select>
            </label>
            <button type="button" class="btn btn--primary btn--full"><?= htmlspecialchars(__('partner_case.customer.preferences.save'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </div>
      </div>
    </aside>
  </section>

  <section class="card estimate-lab">
    <div class="card__header estimate-lab__header">
      <div>
        <p class="eyebrow"><?= htmlspecialchars(__('partner_case.device_panel.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h2><?= htmlspecialchars(__('partner_case.device_panel.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      </div>
    </div>
    <div class="info-grid">
      <div class="info-tile">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.brand'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceBrand !== null && $deviceBrand !== '' ? (string) $deviceBrand : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    <div class="info-tile">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.model'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceModel !== null && $deviceModel !== '' ? (string) $deviceModel : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.serial'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceSerial !== null && $deviceSerial !== '' ? (string) $deviceSerial : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceType !== null && $deviceType !== '' ? (string) $deviceType : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile info-tile--wide">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.problem'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="info-tile__value"><?= nl2br(htmlspecialchars($problemDescription !== '' ? $problemDescription : __('partner_case.device_panel.problem_none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>

      </div>
      <div class="info-tile info-tile--wide">
        <p class="info-tile__label"><?= htmlspecialchars(__('partner_case.device_panel.notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
         <p class="info-tile__value"><?= nl2br(htmlspecialchars($deviceNotesText !== '' ? $deviceNotesText : __('partner_case.device_panel.notes_none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
        </div>
    </div>
  </section>
  <section class="card">
    <div class="card__header">
      <div>
        <p class="eyebrow"><?= htmlspecialchars(__('partner_case.estimate_lab.header.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h2><?= htmlspecialchars(__('partner_case.estimate_lab.header.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.header.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="lab-legend" aria-label="<?= htmlspecialchars(__('partner_case.estimate_lab.legend_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="legend-dot legend-dot--ready"><?= htmlspecialchars(__('partner_case.estimate_lab.status_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars(is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if ($partnerStatus !== 'archived'): ?>
      <form method="post" class="archive-panel__form" data-archive-form novalidate hidden>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="archive-case">
        <input type="hidden" name="archive_reason" data-archive-reason value="<?= htmlspecialchars($archiveReasonValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-actions">
          <button type="submit" class="btn btn--danger"><?= htmlspecialchars(__('partner_case.estimate_lab.close_and_archive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
        </form>
    <?php endif; ?>

    <?php if ($partnerStatus !== 'archived'): ?>
      <div
        class="modal"
        id="archive-modal"
        role="dialog"
        aria-modal="true"
        aria-hidden="true"
        aria-labelledby="archive-modal-title"
      >
        <div class="modal__panel" role="document">
          <header class="modal__header">
            <div>
              <p class="modal__eyebrow"><?= htmlspecialchars(__('partner_case.estimate_lab.modal.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <h2 id="archive-modal-title"><?= htmlspecialchars(__('partner_case.estimate_lab.modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.modal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <button type="button" class="modal__close" data-modal-close aria-label="Zamknij">&times;</button>
          </header>
          <div class="modal__body archive-modal__body">
            <div class="archive-modal__highlight">
              <span class="pill pill--status"><?= htmlspecialchars(__('partner_case.estimate_lab.status_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <p><?= htmlspecialchars(__('partner_case.estimate_lab.modal.highlight'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <div class="archive-modal__chips" role="list" aria-label="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <button type="button" class="archive-chip" data-archive-template="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.completed.template'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-toast="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.completed.toast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <span class="archive-chip__dot"></span>
                <span>
                  <strong><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.completed.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <small><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.completed.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                </span>
              </button>
              <button type="button" class="archive-chip" data-archive-template="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.withdrawn.template'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-toast="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.withdrawn.toast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <span class="archive-chip__dot"></span>
                <span>
                  <strong><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.withdrawn.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <small><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.withdrawn.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                </span>
              </button>
              <button type="button" class="archive-chip" data-archive-template="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.inactive.template'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-toast="<?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.inactive.toast'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <span class="archive-chip__dot"></span>
                <span>
                  <strong><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.inactive.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <small><?= htmlspecialchars(__('partner_case.estimate_lab.modal.quick_reasons.inactive.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                </span>
              </button>
            </div>
            <div class="archive-modal__preview" aria-live="polite">
              <p class="archive-modal__preview-label"><?= htmlspecialchars(__('partner_case.estimate_lab.modal.reason_preview_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <p class="archive-modal__preview-text" data-archive-preview><?= htmlspecialchars(__('partner_case.estimate_lab.modal.reason_preview_empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
          </div>
          <div class="modal__footer">
            <button type="button" class="btn btn--ghost" data-modal-close><?= htmlspecialchars(__('partner_case.estimate_lab.actions.back'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <button type="button" class="btn btn--primary" data-archive-submit><?= htmlspecialchars(__('partner_case.estimate_lab.actions.confirm'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="estimate-lab__meta" role="list" aria-label="<?= htmlspecialchars(__('partner_case.estimate_lab.meta.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="lab-chip" role="listitem">
        <span class="lab-chip__label"><?= htmlspecialchars(__('partner_case.estimate_lab.meta.status_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <strong><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
      </div>
    </div>

    <div class="estimate-lab__layout<?= $isEstimateLocked ? ' estimate-lab__layout--single' : '' ?>">
      <div class="estimate-lab__column">
        <div class="lab-panel">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <h3><?= htmlspecialchars(__('partner_case.estimate_lab.summary.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
              </div>
              <div class="lab-panel__actions"></div>
            </div>
          <?php if ($partnerEstimate !== null): ?>
            <div class="lab-summary">
              <div class="lab-summary__item">
                <p class="lab-summary__label"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p class="lab-summary__value">€ <?= number_format((float) ($partnerEstimate['amount'] ?? 0), 2, ',', ' ') ?></p>
              </div>
            <div class="lab-summary__item">
                <p class="lab-summary__label"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p class="lab-summary__value lab-summary__value--muted"><?= nl2br(htmlspecialchars((string) ($partnerEstimate['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
              </div>
              <?php if (!empty($partnerEstimate['submitted_at'])): ?>
                <div class="lab-summary__item">
                  <p class="lab-summary__label"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.submitted_at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <p class="lab-summary__value lab-summary__value--muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $partnerEstimate['submitted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
              <?php endif; ?>
            </div>
            <div class="lab-grid lab-grid--two">
              <div class="lab-tile">
                <p class="lab-tile__label"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.customer_decision'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($partnerDecision !== null): ?>
                  <p class="lab-tile__value"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($partnerDecision['status'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php if (!empty($partnerDecision['note'])): ?>
                    <p class="muted lab-tile__note"><?= nl2br(htmlspecialchars((string) $partnerDecision['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.customer_pending'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>
              <div class="lab-tile">
                <p class="lab-tile__label"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.history'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($partnerEstimateHistory !== []): ?>
                  <details class="lab-accordion" open>
                    <summary><?= htmlspecialchars(__('partner_case.estimate_lab.summary.history_toggle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></summary>
                    <ul class="lab-history">
                      <?php foreach (array_reverse($partnerEstimateHistory) as $historyItem): ?>
                        <li>
                          <div>
                            <strong>€ <?= number_format((float) ($historyItem['amount'] ?? 0), 2, ',', ' ') ?></strong>
                            <?php if (!empty($historyItem['replaced_at'])): ?>
                              <span class="lab-history__meta"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $historyItem['replaced_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($historyItem['replaced_by'])): ?>
                            <span class="lab-history__meta"><?= htmlspecialchars((string) $historyItem['replaced_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <?php endif; ?>
                        </li>
                      <?php endforeach; ?>
                    </ul>
                  </details>
                <?php else: ?>
                  <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.history_empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php endif; ?>
              </div>
            </div>
            <div class="lab-panel__footer">
              <?php if ($partnerStatus !== 'archived'): ?>
                <button type="button" class="btn btn--ghost btn--archive" data-modal-target="archive-modal"><?= htmlspecialchars(__('partner_case.estimate_lab.close_and_archive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <?php endif; ?>
              <?php if ($canReportCorrection): ?>
                <?php if ($pendingCorrection): ?>
                  <span class="status-badge" aria-label="<?= htmlspecialchars(__('partner_case.estimate_lab.summary.correction_status_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.correction_pending'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php elseif ($correctionResponseLabel !== ''): ?>
                  <span class="status-badge" aria-label="<?= htmlspecialchars(__('partner_case.estimate_lab.summary.correction_status_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($correctionResponseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php else: ?>
                  <button type="button" class="btn btn--ghost" data-modal-target="correction-modal"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.report_correction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.summary.empty_state'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php endif; ?>
        </div>

        <?php if ($partnerEstimate === null || empty($partnerEstimate['submitted_at'])): ?>
          <div class="lab-panel lab-panel--stacked">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <h3><?= htmlspecialchars(__('partner_case.estimate_lab.packages.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
              </div>
              <div class="lab-panel__actions">
                <span class="pill pill--ghost"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
            </div>
            <div class="lab-matrix" data-accordion>
              <button type="button" class="matrix-row" data-accordion-toggle>
                <span><?= htmlspecialchars(__('partner_case.estimate_lab.packages.groups.diagnostics'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="matrix-row__meta"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.expand'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </button>
              <div class="matrix-content">
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="<?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.express_diagnosis.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-add-amount="45"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.express_diagnosis.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="<?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.turbo_clean.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-add-amount="35"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.turbo_clean.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </div>

            <button type="button" class="matrix-row" data-accordion-toggle>
              <span><?= htmlspecialchars(__('partner_case.estimate_lab.packages.groups.repairs'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="matrix-row__meta"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.expand'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </button>
            <div class="matrix-content">
              <button
                type="button"
                class="micro-toggle"
                data-checklist-add="<?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.ssd_clone.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-add-amount="120"
                data-target-description="#estimate-description"
                data-target-amount="#estimate-amount"
              ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.ssd_clone.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              <button
                type="button"
                class="micro-toggle"
                data-checklist-add="<?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.power_supply.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-add-amount="80"
                data-target-description="#estimate-description"
                data-target-amount="#estimate-amount"
              ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.power_supply.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>

            <button type="button" class="matrix-row" data-accordion-toggle>
                <span><?= htmlspecialchars(__('partner_case.estimate_lab.packages.groups.comfort'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="matrix-row__meta"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.expand'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </button>
              <div class="matrix-content">
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="<?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.backup.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-add-amount="25"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.backup.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="Test końcowy i instrukcja dla klienta."
                  data-add-amount="18"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                ><?= htmlspecialchars(__('partner_case.estimate_lab.packages.items.final_check.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
              </div>
            </div>
            <div class="lab-inline-ribbon">
              <span class="pill pill--ghost"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.inline_hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <button type="button" class="chip-button" data-copy-target="#estimate-amount"><?= htmlspecialchars(__('partner_case.estimate_lab.packages.copy_amount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          </div>
          <?php endif; ?>
      </div>

      <?php if (!$isEstimateLocked): ?>
        <div class="estimate-lab__column estimate-lab__column--primary">
          <div class="lab-panel lab-panel--primary">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow"><?= htmlspecialchars(__('partner_case.estimate_lab.form.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <h3><?= htmlspecialchars(__('partner_case.estimate_lab.form.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
              </div>
              <div class="lab-panel__actions"></div>
            </div>
            <?php if ($showEstimateForm): ?>
              <form method="post" class="estimate-form" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="submit-estimate">
                <div class="lab-amount">
                  <label class="form-field">
                    <span class="form-field__label"><?= htmlspecialchars(__('partner_case.estimate_lab.form.amount_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <input id="estimate-amount" data-estimate-amount type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="<?= htmlspecialchars(__('partner_case.estimate_lab.form.amount_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars((string) $amountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                </div>

              <div class="lab-description">
                  <label class="form-field">
                    <span class="form-field__label"><?= htmlspecialchars(__('partner_case.estimate_lab.form.description_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <textarea id="estimate-description" data-estimate-description name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="<?= htmlspecialchars(__('partner_case.estimate_lab.form.description_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) $descriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    <?php if (!empty($errors['estimate_description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_description']) ? implode(' ', array_map('strval', $errors['estimate_description'])) : (string) $errors['estimate_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                  </label>
                </div>

              <div class="form-actions">
                <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('partner_case.estimate_lab.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                <?php if ($partnerEstimate !== null): ?>
                  <a class="btn btn--ghost" href="partner-case.php?id=<?= (int) $caseId ?>"><?= htmlspecialchars(__('partner_case.estimate_lab.form.cancel_edit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                <?php endif; ?>
              </div>
            </form>
            <?php else: ?>
             <p class="muted"><?= htmlspecialchars(__('partner_case.estimate_lab.form.submitted_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= $canEditEstimate ? htmlspecialchars(__('partner_case.estimate_lab.form.submitted_editable'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(__('partner_case.estimate_lab.form.submitted_locked'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </section>
  <div class="toast-stack" data-toast-stack aria-live="polite"></div>
</main>
<?php if ($canReportCorrection): ?>
  <div
    class="modal<?= $shouldOpenCorrectionModal ? ' is-visible' : '' ?>"
    id="correction-modal"
    role="dialog"
    aria-modal="true"
    aria-hidden="true"
    aria-labelledby="correction-modal-title"
  >
    <div class="modal__panel" role="document">
      <header class="modal__header">
        <div>
          <p class="modal__eyebrow"><?= htmlspecialchars(__('partner_case.correction_modal.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h2 id="correction-modal-title"><?= htmlspecialchars(__('partner_case.correction_modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        </div>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('partner_case.correction_modal.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
      </header>
      <form method="post" class="modal__body" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="submit-correction">
        <?php
        $correctionAmountValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-correction'
            ? (string) ($_POST['estimate_amount'] ?? '')
            : (string) ($partnerEstimate['amount'] ?? '');
        $correctionDescriptionValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-correction'
            ? (string) ($_POST['estimate_description'] ?? '')
            : ($partnerEstimate['description'] ?? '');
        $correctionNoteValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-correction'
            ? (string) ($_POST['error_details'] ?? '')
            : ($correctionRequest['error_details'] ?? '');
        ?>
        <div class="form-toolbar">
          <label class="form-field form-field--inline">
            <span class="form-field__label"><?= htmlspecialchars(__('partner_case.correction_modal.quick_fix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <select class="pill-select" data-template-select data-target-amount="#correction-amount" data-target-description="#correction-description">
              <option value=""><?= htmlspecialchars(__('partner_case.correction_modal.choose_scenario'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="<?= htmlspecialchars(__('partner_case.correction_modal.scenarios.parts_cost.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(__('partner_case.correction_modal.scenarios.parts_cost.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="<?= htmlspecialchars(__('partner_case.correction_modal.scenarios.extra_diagnostics.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(__('partner_case.correction_modal.scenarios.extra_diagnostics.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="<?= htmlspecialchars(__('partner_case.correction_modal.scenarios.negotiation.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(__('partner_case.correction_modal.scenarios.negotiation.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            </select>
          </label>
        </div>
        <label class="form-field">
          <span class="form-field__label"><?= htmlspecialchars(__('partner_case.correction_modal.amount_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <input id="correction-amount" type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="<?= htmlspecialchars(__('partner_case.correction_modal.amount_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" value="<?= htmlspecialchars($correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
        </label>
        <label class="form-field">
          <span class="form-field__label"><?= htmlspecialchars(__('partner_case.correction_modal.description_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <textarea id="correction-description" name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="<?= htmlspecialchars(__('partner_case.correction_modal.description_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($correctionDescriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($errors['estimate_description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_description']) ? implode(' ', array_map('strval', $errors['estimate_description'])) : (string) $errors['estimate_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
        </label>
        <label class="form-field">
          <span class="form-field__label"><?= htmlspecialchars(__('partner_case.correction_modal.error_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <textarea name="error_details" rows="3" maxlength="300" placeholder="<?= htmlspecialchars(__('partner_case.correction_modal.error_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($correctionNoteValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </label>
        <?php if (!empty($errors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
        <div class="modal__footer">
          <button type="button" class="btn btn--ghost" data-modal-close><?= htmlspecialchars(__('partner_case.correction_modal.actions.cancel'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          <button type="submit" class="btn btn--primary"><?= htmlspecialchars(__('partner_case.correction_modal.actions.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>
<script src="js/modals.js"></script>
<script src="js/partner-case-ui.js"></script>
<?php if ($shouldOpenCorrectionModal): ?>
  <script>document.body.classList.add('modal-open');</script>
<?php endif; ?>
</body>
</html>