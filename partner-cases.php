<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (Auth::role() !== 'partner') {
    Response::error('Toegang geweigerd.', 403);
}

$caseRepository = new CaseRepository($pdo);
$partnerCases = $caseRepository->forPartner(Auth::id(), 200);

$statusLabels = [
    'awaiting_acceptance' => 'Oczekuje na akceptację',
    'diagnosis' => 'W trakcie diagnozy',
    'estimate_submitted' => 'Wycena wysłana',
    'counter_review' => 'Zmiana ceny',
    'repair_ready' => 'Wycena zaakceptowana',
    'repair_in_progress' => 'Naprawa',
    'archived' => 'Archiwum',
];

$activeCases = [];
$archivedCases = [];

foreach ($partnerCases as $case) {
    $workflow = $case['details']['partner_workflow'] ?? [];
    if (!is_array($workflow)) {
        $workflow = [];
    }
    $status = $workflow['status'] ?? 'awaiting_acceptance';

    $case['partner_status'] = $status;
    $case['partner_status_label'] = $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', (string) $status));

    if ($status === 'archived') {
        $archivedCases[] = $case;
    } else {
        $activeCases[] = $case;
    }
}

$formatDate = static function (?string $value): string {
    if (!is_string($value) || trim($value) === '') {
        return '—';
    }

    $date = date_create_immutable($value);

    return $date instanceof DateTimeImmutable ? $date->format('d-m-Y H:i') : (string) $value;
};

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('partner_cases.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/partner-cases.css">
</head>
<body<?= platform_body_attributes(); ?>>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('partner_cases'); ?>
      </nav>
    </div>
  </header>

  <main class="container partner-cases-page">
    <div class="partner-cases-hero">
      <p class="page-eyebrow"><?= htmlspecialchars(__('partner_cases.hero.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <h1><?= htmlspecialchars(__('partner_cases.hero.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
      <p class="page-intro"><?= htmlspecialchars(__('partner_cases.hero.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>

    <section class="table-shell partner-cases-card" aria-label="<?= htmlspecialchars(__('partner_cases.hero.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div class="partner-cases-meta">
        <span class="summary-card__label">Aktywne</span>
        <strong class="summary-card__value"><?= number_format(count($activeCases), 0, ',', '.') ?></strong>
      </div>
      <?php if ($activeCases === []): ?>
        <p class="panel__empty">Brak aktywnych spraw.</p>
      <?php else: ?>
        <table class="partner-cases-table">
          <thead>
            <tr>
              <th>Referencja</th>
              <th>Status partnera</th>
              <th>Opis</th>
              <th>Ostatnia aktualizacja</th>
              <th class="text-right">Akcje</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($activeCases as $case): ?>
              <?php
                $caseId = (int) ($case['id'] ?? 0);
                $reference = trim((string) ($case['reference_code'] ?? ''));
                $referenceLabel = $reference !== '' ? $reference : __('partner_cases.table.reference_fallback', ['id' => (string) $caseId]);
                $summary = trim((string) ($case['summary'] ?? ''));
                $partnerStatusLabel = (string) ($case['partner_status_label'] ?? '—');
              ?>
              <tr>
                <td><?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><span class="status-pill status-pill--neutral"><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($summary !== '' ? $summary : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDate($case['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td class="text-right">
                  <a class="btn btn--ghost" href="partner-case.php?id=<?= $caseId ?>">Zarządzaj</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <section class="table-shell partner-cases-card" aria-label="Archiwum partnera">
      <div class="partner-cases-meta">
        <span class="summary-card__label">Archiwum</span>
        <strong class="summary-card__value"><?= number_format(count($archivedCases), 0, ',', '.') ?></strong>
      </div>
      <?php if ($archivedCases === []): ?>
        <p class="panel__empty">Brak zarchiwizowanych spraw.</p>
      <?php else: ?>
        <table class="partner-cases-table">
          <thead>
            <tr>
              <th>Referencja</th>
              <th>Status partnera</th>
              <th>Opis</th>
              <th>Zarchiwizowano</th>
              <th class="text-right">Podgląd</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($archivedCases as $case): ?>
              <?php
                $caseId = (int) ($case['id'] ?? 0);
                $reference = trim((string) ($case['reference_code'] ?? ''));
                $referenceLabel = $reference !== '' ? $reference : __('partner_cases.table.reference_fallback', ['id' => (string) $caseId]);
                $summary = trim((string) ($case['summary'] ?? ''));
                $partnerStatusLabel = (string) ($case['partner_status_label'] ?? 'Archiwum');
                $archivedAt = $case['details']['partner_workflow']['archived_at'] ?? null;
              ?>
              <tr>
                <td><?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><span class="status-pill status-pill--neutral"><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($summary !== '' ? $summary : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDate($archivedAt ?? $case['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td class="text-right">
                  <a class="btn btn--ghost" href="partner-case.php?id=<?= $caseId ?>">Podgląd</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>
</body>
</html>