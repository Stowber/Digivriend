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
    'awaiting_acceptance' => __('partner_case.status.awaiting_acceptance'),
    'diagnosis' => __('partner_case.status.diagnosis'),
    'estimate_submitted' => __('partner_case.status.estimate_submitted'),
    'counter_review' => __('partner_case.status.counter_review'),
    'estimate_declined' => __('partner_case.status.estimate_declined'),
    'repair_ready' => __('partner_case.status.repair_ready'),
    'repair_in_progress' => __('partner_case.status.repair_in_progress'),
    'archived' => __('partner_case.status.archived'),
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

$activeCountLabel = number_format(count($activeCases), 0, ',', '.');
$archivedCountLabel = number_format(count($archivedCases), 0, ',', '.');

$activePageTotal = max(1, (int) ceil(count($activeCases) / 5));
$archivedPageTotal = max(1, (int) ceil(count($archivedCases) / 10));

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
        <span class="summary-card__label"><?= htmlspecialchars(__('partner_cases.list.active.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <strong class="summary-card__value"><?= $activeCountLabel ?></strong>
      </div>
      <?php if ($activeCases === []): ?>
        <p class="panel__empty"><?= htmlspecialchars(__('partner_cases.list.active.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php else: ?>
        <div class="archive-shell">
          <div class="archive-controls">
            <label class="archive-search">
              <span class="archive-search__label"><?= htmlspecialchars(__('partner_cases.list.active.search_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <div class="archive-search__field">
                <span class="archive-search__icon" aria-hidden="true">🔎</span>
                <input
                  type="search"
                  name="active_search"
                  placeholder="<?= htmlspecialchars(__('partner_cases.list.active.search_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  class="archive-search__input"
                  data-active-search
                >
                <span class="archive-search__glow" aria-hidden="true"></span>
              </div>
            </label>

            <div
              class="archive-meta"
              role="status"
              aria-live="polite"
              data-active-summary
              data-range-template="<?= htmlspecialchars(__('partner_cases.list.pagination.range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-all-template="<?= htmlspecialchars(__('partner_cases.list.pagination.all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-empty-label="<?= htmlspecialchars(__('partner_cases.list.pagination.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            >
              <?= htmlspecialchars(__('partner_cases.list.pagination.all', ['total' => $activeCountLabel]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </div>
          </div>

          <div class="archive-pagination" data-active-pagination hidden>
            <button type="button" class="btn btn--ghost" data-active-prev aria-label="<?= htmlspecialchars(__('partner_cases.list.pagination.previous'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&larr;</button>
            <span
              class="archive-pagination__label"
              data-active-page
              data-page-template="<?= htmlspecialchars(__('partner_cases.list.pagination.page'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-empty-label="<?= htmlspecialchars(__('partner_cases.list.pagination.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            >
              <?= htmlspecialchars(__('partner_cases.list.pagination.page', ['current' => 1, 'total' => $activePageTotal]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </span>
            <button type="button" class="btn btn--ghost" data-active-next aria-label="<?= htmlspecialchars(__('partner_cases.list.pagination.next'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&rarr;</button>
          </div>
        </div>

        <table class="partner-cases-table" data-active-table>
          <thead>
            <tr>
              <th><?= htmlspecialchars(__('partner_cases.list.active.table.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.list.active.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.list.active.table.summary'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('partner_cases.list.active.table.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th class="text-right">&nbsp;</th>
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
                $customerName = trim((string) ($case['customer_name'] ?? ''));
              ?>
              <tr
                data-active-row
                data-reference="<?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-summary="<?= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              >
                <td><?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><span class="status-pill status-pill--neutral"><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                <td><?= htmlspecialchars($summary !== '' ? $summary : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td><?= htmlspecialchars($formatDate($case['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td class="text-right">
                  <a class="btn btn--ghost" href="partner-case.php?id=<?= $caseId ?>"><?= htmlspecialchars(__('partner_cases.list.active.table.manage'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p class="panel__empty" data-active-empty hidden><?= htmlspecialchars(__('partner_cases.list.active.filter_empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      <?php endif; ?>
    </section>

    <section class="table-shell partner-cases-card" aria-label="<?= htmlspecialchars(__('partner_cases.list.archive.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <details class="archive-accordion">
        <summary class="archive-accordion__summary">
          <div class="archive-accordion__meta">
            <div class="archive-accordion__label">
              <span class="summary-card__label"><?= htmlspecialchars(__('partner_cases.list.archive.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <div class="archive-accordion__title"><?= htmlspecialchars(__('partner_cases.list.archive.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
          </div>
          <span class="archive-accordion__chevron" aria-hidden="true"></span>
        </summary>

        <?php if ($archivedCases === []): ?>
          <p class="panel__empty"><?= htmlspecialchars(__('partner_cases.list.archive.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <div class="archive-shell">
            <div class="archive-controls">
              <label class="archive-search">
                <span class="archive-search__label"><?= htmlspecialchars(__('partner_cases.list.archive.search_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div class="archive-search__field">
                  <span class="archive-search__icon" aria-hidden="true">🔎</span>
                  <input
                    type="search"
                    name="archive_search"
                    placeholder="<?= htmlspecialchars(__('partner_cases.list.archive.search_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                    class="archive-search__input"
                    data-archive-search
                  >
                  <span class="archive-search__glow" aria-hidden="true"></span>
                </div>
              </label>

              <div
                class="archive-meta"
                role="status"
                aria-live="polite"
                data-archive-summary
                data-range-template="<?= htmlspecialchars(__('partner_cases.list.pagination.range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-all-template="<?= htmlspecialchars(__('partner_cases.list.pagination.all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-empty-label="<?= htmlspecialchars(__('partner_cases.list.pagination.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              >
                <?= htmlspecialchars(__('partner_cases.list.pagination.all', ['total' => $archivedCountLabel]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </div>
            </div>

          <div class="archive-pagination" data-archive-pagination hidden>
              <button type="button" class="btn btn--ghost" data-archive-prev aria-label="<?= htmlspecialchars(__('partner_cases.list.pagination.previous'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&larr;</button>
              <span
                class="archive-pagination__label"
                data-archive-page
                data-page-template="<?= htmlspecialchars(__('partner_cases.list.pagination.page'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                data-empty-label="<?= htmlspecialchars(__('partner_cases.list.pagination.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              >
                <?= htmlspecialchars(__('partner_cases.list.pagination.page', ['current' => 1, 'total' => $archivedPageTotal]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
              <button type="button" class="btn btn--ghost" data-archive-next aria-label="<?= htmlspecialchars(__('partner_cases.list.pagination.next'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&rarr;</button>
            </div>
          </div>

          <table class="partner-cases-table archive-accordion__table" data-archive-table>
            <thead>
              <tr>
                <th><?= htmlspecialchars(__('partner_cases.list.archive.table.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(__('partner_cases.list.archive.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th><?= htmlspecialchars(__('partner_cases.list.archive.table.archived'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                <th class="text-right">&nbsp;</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($archivedCases as $case): ?>
                <?php
                  $caseId = (int) ($case['id'] ?? 0);
                  $reference = trim((string) ($case['reference_code'] ?? ''));
                  $referenceLabel = $reference !== '' ? $reference : __('partner_cases.table.reference_fallback', ['id' => (string) $caseId]);
                  $customerName = trim((string) ($case['customer_name'] ?? ''));
                  $partnerStatusLabelRaw = (string) ($case['partner_status_label'] ?? '');
                  $partnerStatusLabel = trim($partnerStatusLabelRaw) !== '' ? $partnerStatusLabelRaw : __('partner_cases.list.archive.table.status_fallback');
                  $archivedAtRaw = $case['details']['partner_workflow']['archived_at'] ?? null;
                  $archivedAt = is_string($archivedAtRaw) && trim($archivedAtRaw) !== '' ? $archivedAtRaw : null;
                ?>
                <tr
                  data-archive-row
                  data-reference="<?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-customer="<?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                >
                  <td>
                    <div class="table-primary"><?= htmlspecialchars($referenceLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php if ($customerName !== ''): ?>
                      <div class="table-subtle"><?= htmlspecialchars(__('partner_cases.list.archive.table.customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                  <td><span class="status-pill status-pill--neutral"><?= htmlspecialchars($partnerStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td><?= htmlspecialchars($formatDate($archivedAt ?? $case['updated_at'] ?? null), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="text-right">
                    <a class="btn btn--ghost" href="partner-case.php?id=<?= $caseId ?>"><?= htmlspecialchars(__('partner_cases.list.archive.table.preview'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <p class="panel__empty" data-archive-empty hidden><?= htmlspecialchars(__('partner_cases.list.archive.filter_empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
      </details>
    </section>
  </main>
  <script src="js/partner-cases.js"></script>
</body>
</html>