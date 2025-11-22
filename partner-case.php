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
$isEditingEstimate = filter_input(INPUT_GET, 'edit_estimate', FILTER_VALIDATE_BOOLEAN) === true;
$csrfToken = Csrf::token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Nieprawidłowa sesja, odśwież stronę i spróbuj ponownie.']);
        }

        $action = $_POST['action'] ?? '';

        if (!in_array($action, ['submit-estimate', 'submit-correction'], true)) {
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
        <button class="pill pill--ghost" type="button" data-copy-target="#client-phone">Kopiuj telefon</button>
        <button class="pill pill--ghost" type="button" data-open-popover="next-steps-popover">Zobacz wskazówki</button>
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
            <button type="button" class="btn btn--primary btn--full" data-open-popover="contact-popover">Zapisz preferencję</button>
          </div>
        </div>
      </div>
      <div class="micro-actions">
        <button type="button" class="btn btn--ghost" data-open-popover="insights-popover">Szybkie podpowiedzi</button>
        <button type="button" class="btn btn--primary" data-copy-target="#client-email">Udostępnij e-mail</button>
      </div>
    </aside>
  </section>

  <section class="card">
    <div class="card__header">
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
    <div class="callout-grid">
      <div class="callout">
        <div>
          <p class="callout__eyebrow">Nowość</p>
          <h3 class="callout__title">Kapsuła informacji o sprzęcie</h3>
          <p class="muted">Przeglądaj ważne punkty bez przewijania dzięki rozwijanym podsumowaniom i mini checklistom.</p>
          <div class="addon-chips" data-addon-target="#estimate-description">
            <button type="button" class="addon-chip" data-addon="Dodaj pełne czyszczenie układu chłodzenia.">Czyszczenie</button>
            <button type="button" class="addon-chip" data-addon="Zalecam wymianę pasty termicznej i kontrolę wentylatorów.">Serwis chłodzenia</button>
            <button type="button" class="addon-chip" data-addon="Test żywotności dysku + kopia zapasowa plików krytycznych.">Backup + test</button>
          </div>
        </div>
      </div>
      <div class="callout callout--ghost">
        <p class="callout__eyebrow">Skróty</p>
        <h3 class="callout__title">Lista akcesoriów</h3>
        <details class="expander">
          <summary>Rozwiń dodatki</summary>
          <ul class="expander__list">
            <li>Ładowarka oraz kabel USB-C</li>
            <li>Dodatkowa pamięć RAM klienta</li>
            <li>Uwagi: <?= nl2br(htmlspecialchars($deviceNotesText !== '' ? $deviceNotesText : 'brak dodatkowych uwag', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></li>
          </ul>
        </details>
        <div class="inline-actions">
          <label class="form-field form-field--inline">
            <span class="form-field__label">Tryb diagnozy</span>
            <select class="pill-select" data-toast-on-change>
              <option value="standard">Standard (45 min)</option>
              <option value="extended">Rozszerzona (90 min)</option>
              <option value="express">Express (25 min)</option>
            </select>
          </label>
          <button type="button" class="btn btn--ghost" data-open-popover="next-steps-popover">Podgląd kroków</button>
        </div>
      </div>
    </div>
  </section>
  <section class="card">
    <div class="card__header">
      <div>
        <p class="eyebrow">Wycena naprawy</p>
        <h2>Wyślij koszt naprawy w €</h2>
      </div>
      <div class="status-badge" aria-label="Status wyceny">
        <?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    </div>

    <?php if ($successMessage !== ''): ?>
      <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars(is_array($errors['general']) ? implode(' ', array_map('strval', $errors['general'])) : (string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="quote-grid">
      <div class="quote-grid__column">
        <h3>Twoja ostatnia wycena</h3>
        <?php if ($partnerEstimate !== null): ?>
          <dl class="info-list info-list--plain">
            <div class="info-list__item">
              <dt>Kwota</dt>
              <dd>€ <?= number_format((float) ($partnerEstimate['amount'] ?? 0), 2, ',', ' ') ?></dd>
            </div>
            <div class="info-list__item">
              <dt>Opis</dt>
              <dd><?= nl2br(htmlspecialchars((string) ($partnerEstimate['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
            </div>
            <?php if (!empty($partnerEstimate['submitted_at'])): ?>
              <div class="info-list__item">
                <dt>Wysłano</dt>
                <dd><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $partnerEstimate['submitted_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
            <?php endif; ?>
            <?php if ($partnerDecision !== null): ?>
              <div class="info-list__item">
                <dt>Decyzja</dt>
                <dd><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($partnerDecision['status'] ?? ''))), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <?php if (!empty($partnerDecision['proposed_amount'])): ?>
                <div class="info-list__item">
                  <dt>Propozycja</dt>
                  <dd>€ <?= number_format((float) $partnerDecision['proposed_amount'], 2, ',', ' ') ?></dd>
                </div>
              <?php endif; ?>
              <?php if (!empty($partnerDecision['note'])): ?>
                <div class="info-list__item">
                  <dt>Uwagi pracownika</dt>
                  <dd><?= nl2br(htmlspecialchars((string) $partnerDecision['note'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($partnerEstimateHistory !== []): ?>
              <div class="info-list__item">
                <dt>Historia wycen</dt>
                <dd>
                  <ul class="muted" style="padding-left: 1rem; margin: 0;">
                    <?php foreach (array_reverse($partnerEstimateHistory) as $historyItem): ?>
                      <li>
                        <strong>€ <?= number_format((float) ($historyItem['amount'] ?? 0), 2, ',', ' ') ?></strong>
                        <?php if (!empty($historyItem['replaced_at'])): ?>
                          · <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $historyItem['replaced_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                        <?php if (!empty($historyItem['replaced_by'])): ?>
                          · <?= htmlspecialchars((string) $historyItem['replaced_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                </dd>
              </div>
            <?php endif; ?>
          </dl>
          <div class="card-actions">
            <?php if ($canEditEstimate && !$isEditingEstimate): ?>
              <a class="btn btn--ghost" href="partner-case.php?id=<?= (int) $caseId ?>&edit_estimate=1">Edytuj wycenę</a>
            <?php endif; ?>
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
          <p class="muted">Nie wysłano jeszcze żadnej wyceny dla tej sprawy.</p>
        <?php endif; ?>
      </div>

      <div class="quote-grid__column">
        <h3>Nowa wycena</h3>
        <?php if ($showEstimateForm): ?>
          <?php
          $amountValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-estimate'
              ? (string) ($_POST['estimate_amount'] ?? '')
              : ($partnerEstimate['amount'] ?? '');
          $descriptionValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-estimate'
              ? (string) ($_POST['estimate_description'] ?? '')
              : ($partnerEstimate['description'] ?? '');
          ?>
          <form method="post" class="form-grid" novalidate>
            <div class="form-toolbar">
              <label class="form-field form-field--inline">
                <span class="form-field__label">Szablon wyceny</span>
                <select class="pill-select" data-template-select data-target-amount="#estimate-amount" data-target-description="#estimate-description">
                  <option value="">Wybierz gotowy pakiet</option>
                  <option data-amount="85" data-description="Pakiet diagnozy + czyszczenie układu chłodzenia.">Szybka diagnoza €85</option>
                  <option data-amount="145" data-description="Wymiana dysku SSD, klonowanie danych oraz konfiguracja systemu.">SSD + konfiguracja €145</option>
                  <option data-amount="210" data-description="Kompleksowy serwis: chłodzenie, zasilacz, testy obciążeniowe.">Serwis premium €210</option>
                </select>
              </label>
              <div class="inline-actions">
                <button type="button" class="btn btn--ghost" data-open-popover="insights-popover">Podpowiedzi</button>
                <button type="button" class="btn btn--ghost" data-copy-target="#estimate-description">Kopiuj opis</button>
              </div>
            </div>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="submit-estimate">
            <label class="form-field">
              <span class="form-field__label">Kwota (€)</span>
              <input id="estimate-amount" data-estimate-amount type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="0,00" value="<?= htmlspecialchars((string) $amountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="form-field">
              <span class="form-field__label">Opis części/naprawy</span>
              <textarea id="estimate-description" data-estimate-description name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="Wymienię płytę główną, dysk SSD i zasilacz."><?= htmlspecialchars((string) $descriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              <?php if (!empty($errors['estimate_description'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_description']) ? implode(' ', array_map('strval', $errors['estimate_description'])) : (string) $errors['estimate_description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
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
  </section>
  <div class="floating-popovers" aria-live="polite">
    <div class="floating-popover" id="next-steps-popover" hidden>
      <div class="floating-popover__header">
        <h3>Najbliższe kroki</h3>
        <button type="button" class="floating-popover__close" data-popover-close aria-label="Zamknij">&times;</button>
      </div>
      <ol class="floating-popover__list">
        <li>Potwierdź z klientem kanał kontaktu (dropdown w panelu).</li>
        <li>Dodaj gotowy pakiet wyceny z listy rozwijanej i uzupełnij opis.</li>
        <li>Załącz korektę, jeśli pojawiła się nowa informacja o sprzęcie.</li>
      </ol>
    </div>
    <div class="floating-popover" id="contact-popover" hidden>
      <div class="floating-popover__header">
        <h3>Preferencja zapisana</h3>
        <button type="button" class="floating-popover__close" data-popover-close aria-label="Zamknij">&times;</button>
      </div>
      <p class="floating-popover__body">Pracownik otrzyma aktualną preferencję kontaktu wraz z terminem. Możesz ją zmieniać bez wychodzenia ze strony.</p>
    </div>
    <div class="floating-popover" id="insights-popover" hidden>
      <div class="floating-popover__header">
        <h3>Podpowiedzi do wyceny</h3>
        <button type="button" class="floating-popover__close" data-popover-close aria-label="Zamknij">&times;</button>
      </div>
      <ul class="floating-popover__list">
        <li>Użyj przycisków dodatków, aby dodać checklistę serwisową.</li>
        <li>Kopiuj opis do notatek klienta jednym kliknięciem.</li>
        <li>Włącz tryb Express, jeśli klient oczekuje szybkiej diagnozy.</li>
      </ul>
    </div>
  </div>
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