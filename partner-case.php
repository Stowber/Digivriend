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
    Response::error('Toegang geweigerd.', 403);
}

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$errors = [];
$successMessage = '';

$statusLabels = [
    'awaiting_acceptance' => 'Oczekuje na akceptację',
    'diagnosis' => 'W trakcie diagnozy',
    'estimate_submitted' => 'Wycena wysłana',
    'counter_review' => 'Zmiana ceny',
    'estimate_declined' => 'Wycena odrzucona',
    'repair_ready' => 'Wycena zaakceptowana',
    'repair_in_progress' => 'Naprawa',
    'correction_review' => 'Korekta oczekuje na zatwierdzenie',
    'archived' => 'Archiwum',
];

$case = $caseRepository->findById((int) $caseId);

if ($case === null) {
    Response::error('Case niet gevonden.', 404);
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
    Response::error('Deze case is niet gekoppeld aan jouw partneraccount.', 403);
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
            throw new ValidationException(['general' => 'Nieprawidłowa sesja, odśwież stronę i spróbuj ponownie.']);
        }

        $action = $_POST['action'] ?? '';

        if (!in_array($action, ['submit-estimate', 'submit-correction', 'archive-case'], true)) {
            throw new ValidationException(['general' => 'Nieznane działanie.']);
        }

        if ($action === 'submit-estimate') {
            if ($partnerStatus === 'repair_ready') {
                throw new ValidationException(['general' => 'Zaakceptowanej wyceny nie można już zmienić.']);
            }

            $amountRaw = InputValidator::requireString($_POST, 'estimate_amount', 32);
            $normalizedAmount = str_replace(',', '.', $amountRaw);
            $amount = filter_var($normalizedAmount, FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) {
                throw new ValidationException(['estimate_amount' => 'Podaj kwotę wyceny większą od zera.']);
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
                throw new ValidationException(['general' => 'Błąd można zgłosić tylko po akceptacji wyceny.']);
            }

            if (is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'pending') {
                throw new ValidationException(['general' => 'Poprzednie zgłoszenie błędu oczekuje na decyzję.']);
            }

            $amountRaw = InputValidator::requireString($_POST, 'estimate_amount', 32);
            $normalizedAmount = str_replace(',', '.', $amountRaw);
            $amount = filter_var($normalizedAmount, FILTER_VALIDATE_FLOAT);
            if ($amount === false || $amount <= 0) {
                throw new ValidationException(['estimate_amount' => 'Podaj kwotę wyceny większą od zera.']);
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
                throw new ValidationException(['general' => 'Sprawa jest już w archiwum.']);
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
    $successMessage = 'Wycena została wysłana do pracownika.';
}

if (filter_input(INPUT_GET, 'correction_submitted', FILTER_VALIDATE_BOOLEAN)) {
    $successMessage = 'Zgłoszenie błędu zostało wysłane do pracownika.';
}

if (filter_input(INPUT_GET, 'archived', FILTER_VALIDATE_BOOLEAN)) {
    $successMessage = 'Sprawa została zakończona i przeniesiona do archiwum partnera.';
}

            $customer = $customerRepository->findById((int) ($case['customer_id'] ?? 0));
if ($customer === null) {
    Response::error('Klient nie znaleziony.', 404);
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

$deviceLabel = 'Onbekend apparaat';
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

$archiveLabel = $partnerStatus === 'archived' ? 'Archiwum partnera' : '';

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
    1 => 'Zgłoszenie',
    2 => 'Wycena',
    3 => 'Decyzja klienta',
    4 => 'Naprawa',
    5 => 'Archiwizacja',
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
    $correctionResponseLabel = 'Korekta zaakceptowana przez pracownika.';
} elseif (is_array($correctionRequest) && ($correctionRequest['status'] ?? '') === 'declined') {
    $correctionResponseLabel = 'Korekta odrzucona przez pracownika.';
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
  <title>Case #<?= htmlspecialchars((string) ($case['reference_code'] ?? $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Partner</title>
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
        <span class="pill pill--signal">Nowy widok partnera</span>
      </div>
      <h1 class="partner-case__title"><?= htmlspecialchars((string) ($case['summary'] ?? 'Zgłoszenie partnera'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <p class="muted">Urządzenie: <?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <div class="pill-row">
        <span class="pill pill--status">Status: <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <?php if ($archiveLabel !== ''): ?>
          <span class="pill pill--warning" aria-label="Status partnera"><?= htmlspecialchars($archiveLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <?php endif; ?>
      </div>
      <div class="progress-ribbon" role="list" aria-label="Postęp zgłoszenia">
        <?php $currentStep = $progressPosition[$partnerStatus] ?? 1; ?>
        <?php foreach ($progressSteps as $index => $label): ?>
          <div class="progress-ribbon__step<?= $index <= $currentStep ? ' is-active' : '' ?>" role="listitem">
            <span class="progress-ribbon__index">0<?= (int) $index ?></span>
            <span class="progress-ribbon__label"><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($archivedAt !== '' || $archiveReason !== ''): ?>
        <p class="muted partner-case__archive">
          <?php if ($archivedAt !== ''): ?>Zarchiwizowano: <?= htmlspecialchars(date('d-m-Y H:i', strtotime($archivedAt)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
          <?php if ($archiveReason !== ''): ?><br>Powód: <?= htmlspecialchars($archiveReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  <aside class="partner-case__panel" aria-label="Informacje i akcje dla klienta">
      <div class="info-card info-card--layered">
        <p class="eyebrow">Klient</p>
        <h2 class="info-card__title"><?= htmlspecialchars((string) ($customer['full_name'] ?? 'Nieznany klient'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <dl class="info-list">
          <div class="info-list__item">
            <dt>Telefon</dt>
            <dd id="client-phone"><?= htmlspecialchars((string) ($customer['phone'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="info-list__item">
            <dt>E-mail</dt>
            <dd id="client-email"><?= htmlspecialchars((string) ($customer['email'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div class="info-list__item">
            <dt>Kod klienta</dt>
            <dd><?= htmlspecialchars((string) ($customer['customer_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        </dl>
        <div class="action-dropdown" data-dropdown>
          <button type="button" class="action-dropdown__trigger" data-dropdown-toggle aria-expanded="false">Preferencje kontaktu</button>
          <div class="dropdown-panel">
            <label class="form-field form-field--inline">
              <span class="form-field__label">Kanał</span>
              <select name="contact_channel" class="pill-select" data-toast-on-change>
                <option value="call">Telefon</option>
                <option value="email">E-mail</option>
                <option value="sms">SMS</option>
              </select>
            </label>
            <label class="form-field form-field--inline">
              <span class="form-field__label">Preferowana pora</span>
              <select name="contact_slot" class="pill-select" data-toast-on-change>
                <option value="morning">08:00-12:00</option>
                <option value="afternoon">12:00-16:00</option>
                <option value="evening">16:00-20:00</option>
              </select>
            </label>
            <button type="button" class="btn btn--primary btn--full">Zapisz preferencję</button>
          </div>
        </div>
      </div>
    </aside>
  </section>

  <section class="card estimate-lab">
    <div class="card__header estimate-lab__header">
      <div>
        <p class="eyebrow">Informacje o urządzeniu</p>
        <h2>Sprzęt przekazany do diagnozy</h2>
      </div>
    </div>
    <div class="info-grid">
      <div class="info-tile">
        <p class="info-tile__label">Marka</p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceBrand !== null && $deviceBrand !== '' ? (string) $deviceBrand : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    <div class="info-tile">
        <p class="info-tile__label">Model</p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceModel !== null && $deviceModel !== '' ? (string) $deviceModel : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile">
        <p class="info-tile__label">Numer seryjny</p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceSerial !== null && $deviceSerial !== '' ? (string) $deviceSerial : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile">
        <p class="info-tile__label">Typ urządzenia</p>
        <p class="info-tile__value"><?= htmlspecialchars($deviceType !== null && $deviceType !== '' ? (string) $deviceType : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>

      <div class="info-tile info-tile--wide">
        <p class="info-tile__label">Opis problemu</p>
        <p class="info-tile__value"><?= nl2br(htmlspecialchars($problemDescription !== '' ? $problemDescription : 'Brak opisu problemu.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>

      </div>
      <div class="info-tile info-tile--wide">
        <p class="info-tile__label">Notatki o sprzęcie</p>
         <p class="info-tile__value"><?= nl2br(htmlspecialchars($deviceNotesText !== '' ? $deviceNotesText : 'Brak dodatkowych notatek.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
        </div>
    </div>
  </section>
  <section class="card">
    <div class="card__header">
      <div>
        <p class="eyebrow">Wycena naprawy · Studio</p>
        <h2>Nowy układ wyceny z bocznym podglądem</h2>
        <p class="muted">Składaj ofertę w jednym miejscu, a szczegóły, historię i gotowe dodatki zobaczysz w panelach obok.</p>
      </div>
      <div class="lab-legend" aria-label="Legenda statusów">
        <span class="legend-dot legend-dot--ready">Status: <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars(is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="archive-panel">
      <div>
        <p class="eyebrow">Archiwizacja</p>
        <h3>Zakończ zlecenie po stronie partnera</h3>
        <p class="muted">Po zakończeniu prac możesz przenieść zlecenie do archiwum partnera. Informacje pozostaną dostępne w zakładce Archiwum.</p>
      </div>
      <?php if ($partnerStatus === 'archived'): ?>
        <div class="archive-panel__status">
          <span class="pill pill--warning">Sprawa w archiwum partnera</span>
        </div>
      <?php else: ?>
        <form method="post" class="archive-panel__form" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="archive-case">
          <label class="form-field">
            <span class="form-field__label">Powód archiwizacji (opcjonalnie)</span>
            <textarea name="archive_reason" rows="3" maxlength="300" placeholder="np. Zakończono naprawę i wydano sprzęt."><?= htmlspecialchars($archiveReasonValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <?php if (!empty($errors['archive_reason'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['archive_reason']) ? implode(' ', array_map('strval', $errors['archive_reason'])) : (string) $errors['archive_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
          </label>
          <div class="form-actions">
            <button type="submit" class="btn btn--danger">Zakończ i archiwizuj</button>
          </div>
        </form>
      <?php endif; ?>
    </div>

    <div class="estimate-lab__meta" role="list" aria-label="Szybkie ustawienia">
      <div class="lab-chip" role="listitem">
        <span class="lab-chip__label">Status partnera</span>
        <strong><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
      </div>
    </div>

    <div class="estimate-lab__layout<?= $isEstimateLocked ? ' estimate-lab__layout--single' : '' ?>">
      <div class="estimate-lab__column">
        <div class="lab-panel">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow">Twoja ostatnia wycena</p>
                <h3>Live panel szczegółów</h3>
              </div>
              <div class="lab-panel__actions"></div>
            </div>
          <?php if ($partnerEstimate !== null): ?>
            <div class="lab-summary">
              <div class="lab-summary__item">
                <p class="lab-summary__label">Kwota</p>
                <p class="lab-summary__value">€ <?= number_format((float) ($partnerEstimate['amount'] ?? 0), 2, ',', ' ') ?></p>
              </div>
            <div class="lab-summary__item">
                <p class="lab-summary__label">Opis</p>
                <p class="lab-summary__value lab-summary__value--muted"><?= nl2br(htmlspecialchars((string) ($partnerEstimate['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
              </div>
              <?php if (!empty($partnerEstimate['submitted_at'])): ?>
                <div class="lab-summary__item">
                  <p class="lab-summary__label">Wysłano</p>
                  <p class="lab-summary__value lab-summary__value--muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $partnerEstimate['submitted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
              <?php endif; ?>
            </div>
            <div class="lab-grid lab-grid--two">
              <div class="lab-tile">
                <p class="lab-tile__label">Decyzja klienta</p>
                <?php if ($partnerDecision !== null): ?>
                  <p class="lab-tile__value"><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($partnerDecision['status'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php if (!empty($partnerDecision['note'])): ?>
                    <p class="muted lab-tile__note"><?= nl2br(htmlspecialchars((string) $partnerDecision['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                  <?php endif; ?>
                <?php else: ?>
                  <p class="muted">Brak decyzji – czeka na akcję klienta.</p>
                <?php endif; ?>
              </div>
              <div class="lab-tile">
                <p class="lab-tile__label">Historia</p>
                <?php if ($partnerEstimateHistory !== []): ?>
                  <details class="lab-accordion" open>
                    <summary>Rozwiń historię wycen</summary>
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
                  <p class="muted">Brak wcześniejszych wycen.</p>
                <?php endif; ?>
              </div>
            </div>
            <div class="lab-panel__footer">
              <?php if ($canReportCorrection): ?>
                <?php if ($pendingCorrection): ?>
                  <span class="status-badge" aria-label="Status korekty">Korekta oczekuje na decyzję</span>
                <?php elseif ($correctionResponseLabel !== ''): ?>
                  <span class="status-badge" aria-label="Status korekty"><?= htmlspecialchars($correctionResponseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php else: ?>
                  <button type="button" class="btn btn--ghost" data-modal-target="correction-modal">Zgłoś błąd</button>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          <?php else: ?>
            <p class="muted">Nie wysłano jeszcze żadnej wyceny. Zbuduj nową po prawej stronie, aby uruchomić podgląd.</p>
          <?php endif; ?>
        </div>

        <?php if ($partnerEstimate === null || empty($partnerEstimate['submitted_at'])): ?>
          <div class="lab-panel lab-panel--stacked">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow">Pakiety i mikro-usługi</p>
                <h3>Dodaj elementy jednym kliknięciem</h3>
              </div>
              <div class="lab-panel__actions">
                <span class="pill pill--ghost">Nowy zestaw</span>
              </div>
            </div>
            <div class="lab-matrix" data-accordion>
              <button type="button" class="matrix-row" data-accordion-toggle>
                <span>Diagnoza i czyszczenie</span>
                <span class="matrix-row__meta">Rozwiń</span>
              </button>
              <div class="matrix-content">
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="Przyspieszona diagnoza (30 min)."
                  data-add-amount="45"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                >Diagnoza Express +45€</button>
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="Pełne czyszczenie układu chłodzenia."
                  data-add-amount="35"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                >Czyszczenie turbo +35€</button>
              </div>

            <button type="button" class="matrix-row" data-accordion-toggle>
              <span>Wymiana części</span>
              <span class="matrix-row__meta">Rozwiń</span>
            </button>
            <div class="matrix-content">
              <button
                type="button"
                class="micro-toggle"
                data-checklist-add="Wymiana dysku SSD z klonowaniem danych."
                data-add-amount="120"
                data-target-description="#estimate-description"
                data-target-amount="#estimate-amount"
              >Nowy SSD + klonowanie +120€</button>
              <button
                type="button"
                class="micro-toggle"
                data-checklist-add="Wymiana zasilacza i testy obciążeniowe."
                data-add-amount="80"
                data-target-description="#estimate-description"
                data-target-amount="#estimate-amount"
              >Stabilny zasilacz +80€</button>
            </div>

            <button type="button" class="matrix-row" data-accordion-toggle>
                <span>Opcje komfortu</span>
                <span class="matrix-row__meta">Rozwiń</span>
              </button>
              <div class="matrix-content">
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="Backup bezpieczeństwa przed naprawą."
                  data-add-amount="25"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                >Backup startowy +25€</button>
                <button
                  type="button"
                  class="micro-toggle"
                  data-checklist-add="Test końcowy i instrukcja dla klienta."
                  data-add-amount="18"
                  data-target-description="#estimate-description"
                  data-target-amount="#estimate-amount"
                >Checklist końcowa +18€</button>
              </div>
            </div>
            <div class="lab-inline-ribbon">
              <span class="pill pill--ghost">Dodajesz + zapisujesz do opisu i kwoty</span>
              <button type="button" class="chip-button" data-copy-target="#estimate-amount">Skopiuj kwotę</button>
            </div>
          </div>
          <?php endif; ?>
      </div>

      <?php if (!$isEstimateLocked): ?>
        <div class="estimate-lab__column estimate-lab__column--primary">
          <div class="lab-panel lab-panel--primary">
            <div class="lab-panel__header">
              <div>
                <p class="eyebrow">Nowa wycena</p>
                <h3>Tryb pisania + suwak kwoty</h3>
              </div>
              <div class="lab-panel__actions"></div>
            </div>
            <?php if ($showEstimateForm): ?>
              <form method="post" class="estimate-form" novalidate>
              <div class="lab-toolbar">
                <label class="form-field form-field--inline">
                  <span class="form-field__label">Szablon wyceny</span>
                  <select class="pill-select" data-template-select data-target-amount="#estimate-amount" data-target-description="#estimate-description">
                    <option value="">Wybierz gotowy pakiet</option>
                    <option data-amount="85" data-description="Pakiet diagnozy + czyszczenie układu chłodzenia.">Szybka diagnoza €85</option>
                    <option data-amount="145" data-description="Wymiana dysku SSD, klonowanie danych oraz konfiguracja systemu.">SSD + konfiguracja €145</option>
                    <option data-amount="210" data-description="Kompleksowy serwis: chłodzenie, zasilacz, testy obciążeniowe.">Serwis premium €210</option>
                  </select>
                </label>
                <label class="form-field form-field--inline">
                  <span class="form-field__label">Kanał powiadomień</span>
                  <select class="pill-select" data-toast-on-change>
                    <option value="email">E-mail</option>
                    <option value="sms">SMS</option>
                    <option value="push">Powiadomienie PUSH</option>
                  </select>
                </label>
              </div>
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="submit-estimate">
              <div class="lab-amount">
                <label class="form-field">
                  <span class="form-field__label">Kwota (€)</span>
                  <input id="estimate-amount" data-estimate-amount type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="0,00" value="<?= htmlspecialchars((string) $amountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <div class="range-control">
                  <label for="amount-range">Suwak kwoty</label>
                  <input id="amount-range" data-amount-range data-target-amount="#estimate-amount" type="range" min="0" max="500" step="5" value="<?= htmlspecialchars((string) ($amountValue !== '' ? $amountValue : '0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <div class="range-scale">
                    <span>0€</span><span>250€</span><span>500€</span>
                  </div>
                </div>
              </div>

              <div class="lab-description">
                <label class="form-field">
                  <span class="form-field__label">Opis części/naprawy</span>
                  <textarea id="estimate-description" data-estimate-description name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="Dodaj najważniejsze elementy i dodatki."><?= htmlspecialchars((string) $descriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  <?php if (!empty($errors['estimate_description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_description']) ? implode(' ', array_map('strval', $errors['estimate_description'])) : (string) $errors['estimate_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                </label>
                <div class="lab-description__grid">
                  <div>
                    <p class="lab-subtitle">Makra tekstowe</p>
                    <div class="lab-chips" data-addon-target="#estimate-description">
                      <button type="button" class="addon-chip" data-addon="Dodatkowe testy SMART + raport.">Testy SMART</button>
                      <button type="button" class="addon-chip" data-addon="Przegląd portów I/O i czyszczenie styków.">Kontrola portów</button>
                      <button type="button" class="addon-chip" data-addon="Aktualizacja BIOS/firmware po akceptacji klienta.">Aktualizacja BIOS</button>
                    </div>
                  </div>
                  <div>
                    <p class="lab-subtitle">Podgląd i kopiowanie</p>
                    <div class="lab-actions">
                      <button type="button" class="btn btn--ghost" data-copy-target="#estimate-description">Kopiuj opis</button>
                      <button type="button" class="btn btn--ghost" data-copy-target="#estimate-amount">Kopiuj kwotę</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="form-actions">
                <button type="submit" class="btn btn--primary">Wyślij wycenę</button>
                <?php if ($partnerEstimate !== null): ?>
                  <a class="btn btn--ghost" href="partner-case.php?id=<?= (int) $caseId ?>">Anuluj edycję</a>
                <?php endif; ?>
              </div>
            </form>
            <?php else: ?>
              <p class="muted">Wycena została wysłana. <?= $canEditEstimate ? 'Kliknij „Edytuj wycenę”, aby wprowadzić zmiany przed akceptacją.' : 'Edytowanie jest dostępne tylko przed akceptacją.' ?></p>
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
          <p class="modal__eyebrow">Wycena</p>
          <h2 id="correction-modal-title">Zgłoś błąd w zaakceptowanej wycenie</h2>
        </div>
        <button type="button" class="modal__close" data-modal-close aria-label="Zamknij">&times;</button>
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
            <span class="form-field__label">Szybka korekta</span>
            <select class="pill-select" data-template-select data-target-amount="#correction-amount" data-target-description="#correction-description">
              <option value="">Wybierz scenariusz</option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="Aktualizacja kosztu części po potwierdzeniu magazynu.">Koszt części</option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="Dodaj roboczogodziny za dodatkową diagnozę.">Dodatkowa diagnoza</option>
              <option data-amount="<?= htmlspecialchars((string) $correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-description="Obniżka ceny po negocjacji z klientem.">Negocjacja</option>
            </select>
          </label>
        </div>
        <label class="form-field">
          <span class="form-field__label">Poprawiona kwota (€)</span>
          <input id="correction-amount" type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="0,00" value="<?= htmlspecialchars($correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
        </label>
        <label class="form-field">
          <span class="form-field__label">Poprawiony opis</span>
          <textarea id="correction-description" name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="Opisz poprawioną wycenę."><?= htmlspecialchars($correctionDescriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($errors['estimate_description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_description']) ? implode(' ', array_map('strval', $errors['estimate_description'])) : (string) $errors['estimate_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
        </label>
        <label class="form-field">
          <span class="form-field__label">Opis błędu (opcjonalnie)</span>
          <textarea name="error_details" rows="3" maxlength="300" placeholder="Opisz, co wymaga korekty."><?= htmlspecialchars($correctionNoteValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
        </label>
        <?php if (!empty($errors['general'])): ?><div class="alert alert--danger"><?= htmlspecialchars(is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
        <div class="modal__footer">
          <button type="button" class="btn btn--ghost" data-modal-close>Anuluj</button>
          <button type="submit" class="btn btn--primary">Wyślij korektę</button>
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