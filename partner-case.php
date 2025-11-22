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

$archiveLabel = $partnerStatus === 'archived' ? 'Archiwum partnera' : '';

$canEditEstimate = in_array($partnerStatus, ['awaiting_acceptance', 'estimate_submitted', 'counter_review'], true);
if ($partnerEstimate === null) {
    $canEditEstimate = true;
}

if (!$canEditEstimate) {
    $isEditingEstimate = false;
}

$showEstimateForm = $partnerEstimate === null || $isEditingEstimate;
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
  <div class="partner-case__header">
    <div>
      <p class="eyebrow">Case #<?= htmlspecialchars((string) ($case['reference_code'] ?? $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <h1><?= htmlspecialchars((string) ($case['summary'] ?? 'Zgłoszenie partnera'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <p class="muted">Urządzenie: <?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <p class="muted">Status partnera: <strong><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
    <?php if ($archiveLabel !== ''): ?>
        <div class="status-badge" aria-label="Status partnera"><?= htmlspecialchars($archiveLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php if ($archivedAt !== '' || $archiveReason !== ''): ?>
        <p class="muted">
          <?php if ($archivedAt !== ''): ?>Zarchiwizowano: <?= htmlspecialchars(date('d-m-Y H:i', strtotime($archivedAt)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
          <?php if ($archiveReason !== ''): ?><br>Powód: <?= htmlspecialchars($archiveReason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
        </p>
      <?php endif; ?>
    </div>
  <div class="info-card" aria-label="Informacje o kliencie">
      <p class="eyebrow">Klient</p>
      <h2 class="info-card__title"><?= htmlspecialchars((string) ($customer['full_name'] ?? 'Nieznany klient'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
      <dl class="info-list">
        <div class="info-list__item">
          <dt>Telefon</dt>
          <dd><?= htmlspecialchars((string) ($customer['phone'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <div class="info-list__item">
          <dt>E-mail</dt>
          <dd><?= htmlspecialchars((string) ($customer['email'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <div class="info-list__item">
          <dt>Kod klienta</dt>
          <dd><?= htmlspecialchars((string) ($customer['customer_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
      </dl>
    </div>
  </div>

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
        <p class="info-tile__value"><?= nl2br(htmlspecialchars($deviceNotes !== null && trim((string) $deviceNotes) !== '' ? (string) $deviceNotes : 'Brak dodatkowych notatek.', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
        </form>
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
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="submit-estimate">
            <label class="form-field">
              <span class="form-field__label">Kwota (€)</span>
              <input type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="0,00" value="<?= htmlspecialchars((string) $amountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label class="form-field">
              <span class="form-field__label">Opis części/naprawy</span>
              <textarea name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="Wymienię płytę główną, dysk SSD i zasilacz."><?= htmlspecialchars((string) $descriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
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
            : ($partnerEstimate['amount'] ?? '');
        $correctionDescriptionValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-correction'
            ? (string) ($_POST['estimate_description'] ?? '')
            : ($partnerEstimate['description'] ?? '');
        $correctionNoteValue = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'submit-correction'
            ? (string) ($_POST['error_details'] ?? '')
            : ($correctionRequest['error_details'] ?? '');
        ?>
        <label class="form-field">
          <span class="form-field__label">Poprawiona kwota (€)</span>
          <input type="number" name="estimate_amount" step="0.01" min="0" required aria-required="true" placeholder="0,00" value="<?= htmlspecialchars($correctionAmountValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <?php if (!empty($errors['estimate_amount'])): ?><small class="form-error"><?= htmlspecialchars(is_array($errors['estimate_amount']) ? implode(' ', array_map('strval', $errors['estimate_amount'])) : (string) $errors['estimate_amount'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
        </label>
        <label class="form-field">
          <span class="form-field__label">Poprawiony opis</span>
          <textarea name="estimate_description" rows="4" maxlength="500" required aria-required="true" placeholder="Opisz poprawioną wycenę."><?= htmlspecialchars($correctionDescriptionValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
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
<?php if ($shouldOpenCorrectionModal): ?>
  <script>document.body.classList.add('modal-open');</script>
<?php endif; ?>
</body>
</html>