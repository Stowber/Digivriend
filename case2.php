<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Support\Repositories\CaseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$case = $caseRepository->findById((int) $caseId);
if ($case === null) {
    Response::error('Case niet gevonden.', 404);
}

// Pobieramy sklejone dane klienta + urządzenia dla widoku klienta
$statement = $pdo->prepare(
    'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,
            dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial
     FROM cases c
     INNER JOIN customers cust ON cust.id = c.customer_id
     LEFT JOIN devices dev ON dev.id = c.device_id
     WHERE c.id = :id'
);
$statement->execute(['id' => $caseId]);
$caseRecord = $statement->fetch();
if (!$caseRecord) {
    Response::error('Casegegevens konden niet worden geladen.', 500);
}

$customer = [
    'name' => trim((string) ($caseRecord['full_name'] ?? 'Onbekende klant')),
    'email' => trim((string) ($caseRecord['email'] ?? '')),
    'phone' => trim((string) ($caseRecord['phone'] ?? '')),
    'address' => trim((string) ($caseRecord['address'] ?? '')),
    'postal_code' => trim((string) ($caseRecord['postal_code'] ?? '')),
    'city' => trim((string) ($caseRecord['city'] ?? '')),
];

$deviceSummary = array_filter([
    trim((string) ($caseRecord['device_brand'] ?? '')),
    trim((string) ($caseRecord['device_model'] ?? '')),
]);
$deviceLabel = $deviceSummary !== [] ? implode(' ', $deviceSummary) : 'Onbekend apparaat';
$deviceSerial = trim((string) ($caseRecord['device_serial'] ?? ''));

// Prosty helper do maskowania braku danych
$fallback = static fn(string $value): string => $value !== '' ? $value : '—';
$customerEmailSafe = $fallback($customer['email']);
$customerPhoneSafe = $fallback($customer['phone']);

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Case #<?= (int) $caseId ?> - Klant</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/case2.css">
</head>
<body class="case2">
  <main class="container case2-shell">
    <section class="case2-hero-card" data-toggle="#customer-block" role="button" tabindex="0">
      <div class="case2-hero-card__row">
        <div class="case2-chip">Case #<?= (int) $caseId ?></div>
        <?php if (!empty($case['reference_code'])): ?>
          <div class="case2-chip case2-chip--ghost"><?= htmlspecialchars((string) $case['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="case2-chip case2-chip--status"><?= htmlspecialchars((string) ($case['status'] ?? 'onbekend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      </div>
      <div class="case2-hero-card__body">
        <div class="case2-hero-card__bodyText">
          <p class="case2-eyebrow">Klient</p>
          <h1 class="case2-title"><?= htmlspecialchars($customer['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <p class="case2-subtitle">Urządzenie: <?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div class="case2-hero-meta">
          <p><strong>E-mail:</strong> <?= htmlspecialchars($customerEmailSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <p><strong>Telefon:</strong> <?= htmlspecialchars($customerPhoneSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <p class="case2-hero-hint">Kliknij, aby rozwinąć</p>
        </div>
      </div>
    </section>

    <section class="case2-card">
      <div id="customer-block" class="case2-grid" hidden>
        <div class="case2-panel">
          <div class="case2-panel__header">
            <p class="case2-eyebrow">Dane kontaktowe</p>
            <div class="case2-panel__actions">
              <a class="btn btn--ghost<?= $customerEmailSafe === '—' ? ' is-disabled' : '' ?>" href="<?= $customerEmailSafe === '—' ? '#' : 'mailto:' . htmlspecialchars($customer['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">E-mail</a>
              <a class="btn btn--ghost<?= $customerPhoneSafe === '—' ? ' is-disabled' : '' ?>" href="<?= $customerPhoneSafe === '—' ? '#' : 'tel:' . htmlspecialchars($customer['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">Zadzwoń</a>
            </div>
          </div>
          <ul class="case2-info">
            <li><span>E-mail</span><strong><?= htmlspecialchars($customerEmailSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></li>
            <li><span>Telefon</span><strong><?= htmlspecialchars($customerPhoneSafe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></li>
            <li><span>Adres</span><strong><?= htmlspecialchars($fallback($customer['address']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></li>
            <li><span>Kod pocztowy</span><strong><?= htmlspecialchars($fallback($customer['postal_code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></li>
            <li><span>Miasto</span><strong><?= htmlspecialchars($fallback($customer['city']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></li>
          </ul>
        </div>
        <aside class="case2-panel case2-panel--accent">
          <p class="case2-eyebrow">Urządzenie</p>
          <p class="case2-device-name"><?= htmlspecialchars($deviceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <p class="case2-device-meta">Nr seryjny: <?= htmlspecialchars($fallback($deviceSerial), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </aside>
      </div>
    </section>
  </main>

  <script>
    document.querySelectorAll('[data-toggle]').forEach((btn) => {
      btn.addEventListener('click', () => {
        const target = btn.getAttribute('data-toggle');
        if (!target) return;
        const el = document.querySelector(target);
        if (el) {
          const isHidden = el.hasAttribute('hidden') || el.classList.contains('is-hidden');
          if (isHidden) {
            el.removeAttribute('hidden');
            el.classList.remove('is-hidden');
          } else {
            el.setAttribute('hidden', 'true');
            el.classList.add('is-hidden');
          }
        }
      });
    });
  </script>
</body>
</html>
