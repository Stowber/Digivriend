<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use DateTimeImmutable;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (Auth::role() !== 'partner') {
    Response::error('Toegang geweigerd.', 403);
}

$caseRepository = new CaseRepository($pdo);
$partnerCases = $caseRepository->forPartner(Auth::id(), 100);

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
      <?php if ($partnerCases === []): ?>
        <p class="panel__empty"><?= htmlspecialchars(__('partner_cases.table.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php else: ?>
        <div class="partner-cases-meta">
          <span class="summary-card__label"><?= htmlspecialchars(__('partner_cases.table.summary_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong class="summary-card__value"><?= number_format(count($partnerCases), 0, ',', '.') ?></strong>
        </div>
        <table class="partner-cases-table">
          <thead>
            <tr>
              <th><?= htmlspecialchars(__('partner_cases.table.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.summary'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.table.assigned'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th class="text-right"><?= htmlspecialchars(__('partner_cases.table.actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($partnerCases as $case): ?>
              <?php
                $caseId = (int) ($case['id'] ?? 0);
                $reference = trim((string) ($case['reference_code'] ?? ''));
                $referenceLabel = $reference !== '' ? $reference : __('partner_cases.table.reference_fallback', ['id' => (string) $caseId]);
                $status = (string) ($case['status'] ?? '');
                $type = (string) ($case['type'] ?? '');
                $summary = trim((string) ($case['summary'] ?? ''));
                $details = is_array($case['details'] ?? null) ? $case['details'] : [];
                $assignedAt = isset($case['partner_assigned_at']) ? (string) $case['partner_assigned_at'] : ($details['partner_assigned_at'] ?? null);
                $customerVisible = !empty($details['partner_contact_consent']);
                $customerName = $customerVisible
                    ? (string) ($case['customer_name'] ?? __('partner_cases.table.hidden_customer'))
                    : __('partner_cases.table.hidden_customer');
              ?>
              <tr>
                <td><?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($type !== '' ? $type : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><span class="status-pill status-pill--neutral"><?= htmlspecialchars($status !== '' ? $status : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($summary !== '' ? $summary : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDate($case['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDate($assignedAt), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td class="text-right">
                  <a class="btn btn--ghost" href="case.php?id=<?= $caseId ?>">
                    <?= htmlspecialchars(__('partner_cases.table.view'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </a>
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