<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;

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
</main>
</body>
</html>