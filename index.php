<?php

declare(strict_types=1);

use App\Support\Lang\Translator;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (!function_exists('translate_dashboard_enum')) {
    function translate_dashboard_enum(string $prefix, string $key): string
    {
        $normalizedKey = strtolower(str_replace(' ', '_', $key));
        $translationKey = $prefix . $normalizedKey;
        $translated = __($translationKey);

        if ($translated === $translationKey) {
            return ucfirst(str_replace('_', ' ', $normalizedKey));
        }

        return $translated;
    }
}

$caseRepository = new CaseRepository($pdo);
$warehouseRepository = new WarehouseRepository($pdo);

$totalCustomers = (int) ($pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() ?: 0);
$openCases = (int) ($pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('opgehaald', 'gesloten')")->fetchColumn() ?: 0);
$today = (new DateTimeImmutable('today'))->setTime(0, 0);
$todayDateString = $today->format('Y-m-d');
$typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$periodParam = filter_input(INPUT_GET, 'period', FILTER_SANITIZE_NUMBER_INT);
$periodOptions = [7, 30, 90];
$periodDays = in_array((int) $periodParam, $periodOptions, true) ? (int) $periodParam : 30;
$periodStart = $today->sub(new DateInterval('P' . max($periodDays - 1, 0) . 'D'))->setTime(0, 0);
$periodStartString = $periodStart->format('Y-m-d 00:00:00');
$todayPickups = 0;
$longestWaiting = 0;
$warehouseStatusCounts = $warehouseRepository->statusCounts();
$totalWarehouseItems = $warehouseRepository->totalItems();
$warehouseQuantityTotal = $warehouseRepository->totalQuantity();
$warehouseReservedTotal = $warehouseRepository->totalReserved();
$warehouseReadyTotal = $warehouseStatusCounts['ready'] ?? 0;
$warehouseAvailableTotal = max(0, $warehouseQuantityTotal - $warehouseReservedTotal);

$readyPickupsStmt = $pdo->prepare(
    "SELECT details
     FROM cases
     WHERE type = 'pickup' AND status = 'klaar' AND details IS NOT NULL"
);
$readyPickupsStmt->execute();

while (($detailsJson = $readyPickupsStmt->fetchColumn()) !== false) {
    if (!is_string($detailsJson) || trim($detailsJson) === '') {
        continue;
    }

    $detailsData = json_decode($detailsJson, true);

    if (!is_array($detailsData)) {
        continue;
    }

    $readyDateRaw = $detailsData['datumgereed'] ?? null;

    if (!is_string($readyDateRaw) || $readyDateRaw === '') {
        continue;
    }

    $readyDate = date_create_immutable($readyDateRaw) ?: DateTimeImmutable::createFromFormat('Y-m-d', $readyDateRaw);

    if (!$readyDate instanceof DateTimeImmutable) {
        continue;
    }

    $readyDate = $readyDate->setTime(0, 0);

    if ($readyDate->format('Y-m-d') === $todayDateString) {
        $todayPickups++;
    }

    $daysWaiting = (int) $readyDate->diff($today)->format('%r%a');

    if ($daysWaiting >= 0 && $daysWaiting > $longestWaiting) {
        $longestWaiting = $daysWaiting;
    }
}

$caseSummaryStmt = $pdo->query('SELECT type, status, COUNT(*) AS total FROM cases GROUP BY type, status');
$caseSummary = [];
foreach ($caseSummaryStmt->fetchAll() as $row) {
    $caseSummary[$row['type']][$row['status']] = (int) $row['total'];
}

$distinctTypes = $pdo->query('SELECT DISTINCT type FROM cases ORDER BY type')->fetchAll(PDO::FETCH_COLUMN) ?: [];

$casesInPeriodSql = 'SELECT COUNT(*) FROM cases WHERE updated_at >= :since';
$casesCompletedSql = "SELECT COUNT(*) FROM cases WHERE updated_at >= :since AND status IN ('opgehaald','gesloten')";
if ($typeFilter !== 'all') {
    $casesInPeriodSql .= ' AND type = :type';
    $casesCompletedSql .= ' AND type = :type';
}

$casesInPeriodStatement = $pdo->prepare($casesInPeriodSql);
$casesInPeriodStatement->bindValue('since', $periodStartString);
if ($typeFilter !== 'all') {
    $casesInPeriodStatement->bindValue('type', $typeFilter);
}
$casesInPeriodStatement->execute();
$casesInPeriod = (int) $casesInPeriodStatement->fetchColumn();

$casesCompletedStatement = $pdo->prepare($casesCompletedSql);
$casesCompletedStatement->bindValue('since', $periodStartString);
if ($typeFilter !== 'all') {
    $casesCompletedStatement->bindValue('type', $typeFilter);
}
$casesCompletedStatement->execute();
$casesCompleted = (int) $casesCompletedStatement->fetchColumn();

$notificationsByChannelStatement = $pdo->prepare('SELECT channel, COUNT(*) AS total FROM notifications WHERE sent_at >= :since GROUP BY channel');
$notificationsByChannelStatement->execute(['since' => $periodStartString]);
$notificationsByChannel = [];
foreach ($notificationsByChannelStatement->fetchAll() ?: [] as $notificationRow) {
    $notificationsByChannel[$notificationRow['channel']] = (int) $notificationRow['total'];
}

$pendingNotifications = (int) ($pdo->query("SELECT COUNT(*) FROM ophaalbevestigingen WHERE status = 'klaar' AND (notified_ready_at IS NULL OR notified_ready_at = '')")?->fetchColumn() ?: 0);

$leadStatement = $pdo->query('SELECT datumgereed, pickup_signed_at FROM ophaalbevestigingen WHERE pickup_signed_at IS NOT NULL');
$leadDurations = [];
if ($leadStatement) {
    while ($leadRow = $leadStatement->fetch()) {
        $ready = !empty($leadRow['datumgereed']) ? date_create_immutable((string) $leadRow['datumgereed']) : null;
        $picked = !empty($leadRow['pickup_signed_at']) ? date_create_immutable((string) $leadRow['pickup_signed_at']) : null;
        if ($ready instanceof DateTimeInterface && $picked instanceof DateTimeInterface) {
            $leadDurations[] = max(0, (int) $ready->diff($picked)->format('%a'));
        }
    }
}
$averageLeadTime = $leadDurations !== [] ? round(array_sum($leadDurations) / count($leadDurations), 1) : null;

$trendStatementSql = 'SELECT updated_at FROM cases WHERE updated_at >= :since';
if ($typeFilter !== 'all') {
    $trendStatementSql += ' AND type = :type';
}
$trendStatement = $pdo->prepare($trendStatementSql);
$trendStatement->bindValue('since', $periodStartString);
if ($typeFilter !== 'all') {
    $trendStatement->bindValue('type', $typeFilter);
}
$trendStatement->execute();
$trendData = [];
while ($trendRow = $trendStatement->fetch()) {
    $day = date_create_immutable((string) $trendRow['updated_at']);
    if (!$day instanceof DateTimeImmutable) {
        continue;
    }
    $key = $day->format('Y-m-d');
    $trendData[$key] = ($trendData[$key] ?? 0) + 1;
}
ksort($trendData);

$recentCases = $caseRepository->recentCases(6, $typeFilter !== 'all' ? $typeFilter : null, $periodStartString);

$pickupsStatement = $pdo->prepare(
    "SELECT c.*, cust.full_name, cust.phone, cust.email, ob.datumgereed, ob.ophaalcode
     FROM cases c
     INNER JOIN customers cust ON cust.id = c.customer_id
     LEFT JOIN ophaalbevestigingen ob ON ob.ophaalcode = c.reference_code
     WHERE c.type = 'pickup' AND c.status = 'klaar'
     ORDER BY ob.datumgereed ASC, c.updated_at DESC
     LIMIT 5"
);
$pickupsStatement->execute();
$upcomingPickups = $pickupsStatement->fetchAll() ?: [];

$notesStatement = $pdo->query(
    "SELECT n.*, c.summary, cust.full_name
     FROM notes n
     INNER JOIN cases c ON c.id = n.case_id
     INNER JOIN customers cust ON cust.id = n.customer_id
     ORDER BY n.created_at DESC
     LIMIT 5"
);
$recentNotes = $notesStatement->fetchAll() ?: [];

$notificationTotal = array_sum($notificationsByChannel);
$casesCompletionRate = $casesInPeriod > 0
    ? max(0, min(100, (int) round(($casesCompleted / $casesInPeriod) * 100)))
    : null;
$warehouseReadyRate = $totalWarehouseItems > 0
    ? max(0, min(100, (int) round(($warehouseReadyTotal / $totalWarehouseItems) * 100)))
    : null;
$warehouseReservedRate = $warehouseQuantityTotal > 0
    ? max(0, min(100, (int) round(($warehouseReservedTotal / $warehouseQuantityTotal) * 100)))
    : null;
$notificationCompletionRate = ($notificationTotal + $pendingNotifications) > 0
    ? max(0, min(100, (int) round(($notificationTotal / ($notificationTotal + $pendingNotifications)) * 100)))
    : null;
$typeFilterLabel = $typeFilter === 'all'
    ? __('dashboard.hero.meta.filter_all')
    : translate_dashboard_enum('dashboard.case_types.', (string) $typeFilter);
$now = new DateTimeImmutable('now');
$lastActivityDate = null;
if ($trendData !== []) {
    $trendKeys = array_keys($trendData);
    $lastTrendKey = end($trendKeys);
    if (is_string($lastTrendKey)) {
        $lastActivityDate = date_create_immutable($lastTrendKey) ?: null;
    }
}

if ($lastActivityDate instanceof DateTimeInterface) {
    $lastActivityDisplay = $lastActivityDate->format('d-m-Y');
} else {
    $lastActivityDisplay = __('dashboard.hero.meta.last_activity_none');
}

$heroSummary = __('dashboard.hero.summary', [
    'open_cases' => number_format($openCases, 0, ',', '.'),
    'pending_notifications' => number_format($pendingNotifications, 0, ',', '.'),
]);

$heroActiveHint = $longestWaiting > 0
    ? __('dashboard.hero.metrics.active_cases.hint_waiting', ['days' => $longestWaiting])
    : __('dashboard.hero.metrics.active_cases.hint_clear');

$heroPickupHint = $todayPickups > 0
    ? __('dashboard.hero.metrics.today_pickups.hint_any')
    : __('dashboard.hero.metrics.today_pickups.hint_none');

$heroNotificationHint = $pendingNotifications > 0
    ? __('dashboard.hero.metrics.notifications.hint_any')
    : __('dashboard.hero.metrics.notifications.hint_none');

$casesCompletionMeta = $casesCompletionRate !== null
    ? __('dashboard.highlights.cases.progress', ['rate' => $casesCompletionRate])
    : null;

$warehouseReadyMeta = $warehouseReadyRate !== null
    ? __('dashboard.highlights.warehouse.progress_ready', ['rate' => $warehouseReadyRate])
    : null;

$notificationCompletionMeta = $notificationCompletionRate !== null
    ? __('dashboard.highlights.notifications.progress', ['rate' => $notificationCompletionRate])
    : null;

$averageLeadTimeLabel = $averageLeadTime !== null
    ? __('dashboard.highlights.lead_time.value', ['days' => $averageLeadTime])
    : null;

$longestWaitingLabel = $longestWaiting > 0
    ? __('dashboard.highlights.wait_time.value', ['days' => $longestWaiting])
    : __('dashboard.highlights.wait_time.empty');

$customerHint = __('dashboard.highlights.customers.hint', [
    'cases' => number_format($openCases, 0, ',', '.'),
]);

$caseHint = __('dashboard.highlights.cases.hint', [
    'completed' => number_format($casesCompleted, 0, ',', '.'),
]);

$warehouseHint = __('dashboard.highlights.warehouse.hint', [
    'available' => number_format($warehouseAvailableTotal, 0, ',', '.'),
    'ready' => number_format($warehouseReadyTotal, 0, ',', '.'),
]);

$notificationsHint = __('dashboard.highlights.notifications.hint', [
    'pending' => number_format($pendingNotifications, 0, ',', '.'),
]);

$leadTimeHint = __('dashboard.highlights.lead_time.hint');
$waitTimeHint = __('dashboard.highlights.wait_time.hint');

$caseTrendEmptyMessage = __('dashboard.panels.trend.empty');
$caseSummaryEmptyMessage = __('dashboard.panels.case_summary.empty');
$notificationEmptyMessage = __('dashboard.panels.notifications.empty');

$modalCaseEmptyMessage = __('dashboard.modals.cases.empty');

$heroPeriodValue = __('dashboard.hero.meta.period_value', ['days' => $periodDays]);
$unknownLabel = __('dashboard.common.unknown');

$activityCasesTitle = __('dashboard.activity.cases.title', ['days' => $periodDays]);
$activityCasesSubtitle = __('dashboard.activity.cases.subtitle');
$activityCasesRange = __('dashboard.activity.cases.range', [
    'start' => $periodStart->format('d-m-Y'),
    'end' => $now->format('d-m-Y'),
]);
$activityCasesUpdatesLabel = __('dashboard.activity.cases.stats.updates');
$activityCasesCompletedLabel = __('dashboard.activity.cases.stats.completed');
$activityCasesSuccessLabel = __('dashboard.activity.cases.stats.success');
$activityCasesFooterLabel = __('dashboard.activity.cases.footer.label');

$activityNotificationsTitle = __('dashboard.activity.notifications.title');
$activityNotificationsSubtitle = __('dashboard.activity.notifications.subtitle');
$activityNotificationsTotal = __('dashboard.activity.notifications.total', [
    'total' => number_format($notificationTotal, 0, ',', '.'),
]);
$activityNotificationsEmpty = __('dashboard.activity.notifications.empty');
$activityNotificationsFooterLabel = __('dashboard.activity.notifications.footer.open');

$activityRecentCasesTitle = __('dashboard.activity.recent_cases.title');
$activityRecentCasesSubtitle = __('dashboard.activity.recent_cases.subtitle');
$activityRecentCasesEmpty = __('dashboard.activity.recent_cases.empty');
$activityRecentCasesLink = __('dashboard.activity.recent_cases.link');
$activityRecentCasesMeta = __('dashboard.activity.recent_cases.meta_template');

$activityNotesTitle = __('dashboard.activity.notes.title');
$activityNotesSubtitle = __('dashboard.activity.notes.subtitle');
$activityNotesEmpty = __('dashboard.activity.notes.empty');
$activityNotesCaseLabel = __('dashboard.activity.notes.case_label');
$activityNotesCustomerLabel = __('dashboard.activity.notes.customer_label');

$panelGridAria = __('dashboard.panels.grid_aria');
$panelPickupsTitle = __('dashboard.panels.pickups.title');
$panelPickupsSubtitle = __('dashboard.panels.pickups.subtitle');
$panelPickupsViewAll = __('dashboard.panels.pickups.view_all');
$panelPickupsHeaders = [
    __('dashboard.panels.pickups.headers.customer'),
    __('dashboard.panels.pickups.headers.code'),
    __('dashboard.panels.pickups.headers.ready_date'),
    __('dashboard.panels.pickups.headers.contact'),
];
$panelPickupsEmpty = __('dashboard.panels.pickups.empty');
$panelPickupsPhoneLabel = __('dashboard.panels.pickups.phone');
$panelPickupsEmailLabel = __('dashboard.panels.pickups.email');
$panelPickupsStatusLabel = __('dashboard.panels.pickups.status');

$panelCaseSummaryTitle = __('dashboard.panels.case_summary.title');
$panelCaseSummarySubtitle = __('dashboard.panels.case_summary.subtitle');

$panelTrendTitle = __('dashboard.panels.trend.title', ['days' => $periodDays]);
$panelTrendSubtitle = __('dashboard.panels.trend.subtitle');

$panelNotificationsTitle = __('dashboard.panels.notifications.title');
$panelNotificationsSubtitle = __('dashboard.panels.notifications.subtitle');
$panelNotificationsFooter = __('dashboard.panels.notifications.footer', [
    'count' => number_format($casesCompleted, 0, ',', '.'),
]);

$modalCloseLabel = __('common.close');
$modalCasesTitle = __('dashboard.modals.cases.title');
$modalCasesSummary = __('dashboard.modals.cases.summary', [
    'days' => $periodDays,
    'created' => number_format($casesInPeriod, 0, ',', '.'),
    'completed' => number_format($casesCompleted, 0, ',', '.'),
]);
$modalCasesCompletionLabel = $casesCompletionRate !== null
    ? __('dashboard.modals.cases.completion', ['rate' => $casesCompletionRate])
    : null;
$modalCasesTip = __('dashboard.modals.cases.tip');

$modalWarehouseTitle = __('dashboard.modals.warehouse.title');
$modalWarehouseSummary = __('dashboard.modals.warehouse.summary', [
    'total' => number_format($totalWarehouseItems, 0, ',', '.'),
    'ready' => number_format($warehouseReadyTotal, 0, ',', '.'),
    'reserved' => number_format($warehouseReservedTotal, 0, ',', '.'),
]);
$modalWarehouseLabels = [
    'available' => __('dashboard.modals.warehouse.available'),
    'ready' => __('dashboard.modals.warehouse.ready'),
    'reserved' => __('dashboard.modals.warehouse.reserved'),
];
$modalWarehouseTip = __('dashboard.modals.warehouse.tip');

$modalNotificationsTitle = __('dashboard.modals.notifications.title');
$modalNotificationsSummary = __('dashboard.modals.notifications.summary', [
    'total' => number_format($notificationTotal, 0, ',', '.'),
]);
$modalNotificationEmptyMessage = __('dashboard.modals.notifications.empty');
$modalNotificationsOpen = __('dashboard.modals.notifications.open', [
    'pending' => number_format($pendingNotifications, 0, ',', '.'),
]);
$modalNotificationsTip = __('dashboard.modals.notifications.tip');

$footerCopyright = __('dashboard.footer.copyright', [
    'year' => date('Y'),
    'app' => __('app.name'),
]);

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('dashboard.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="logo__subtitle"><?= htmlspecialchars(__('dashboard.header.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('dashboard'); ?>
      </nav>
    </div>
  </header>

  <main class="container dashboard">
    <section class="dashboard__hero" aria-labelledby="dashboardTitle">
      <div class="dashboard__hero-layout">
        <div class="dashboard__hero-intro">
          <span class="hero__badge"><?= htmlspecialchars(__('dashboard.hero.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <h1 id="dashboardTitle"><?= htmlspecialchars(__('dashboard.hero.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
          <p><?= htmlspecialchars($heroSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div class="dashboard__hero-metrics">
          <article class="hero-metric">
            <span class="hero-metric__label"><?= htmlspecialchars(__('dashboard.hero.metrics.active_cases.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="hero-metric__value"><?= number_format($openCases, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($heroActiveHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
          <article class="hero-metric">
            <span class="hero-metric__label"><?= htmlspecialchars(__('dashboard.hero.metrics.today_pickups.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="hero-metric__value"><?= number_format($todayPickups, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($heroPickupHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
          <article class="hero-metric">
            <span class="hero-metric__label"><?= htmlspecialchars(__('dashboard.hero.metrics.notifications.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="hero-metric__value"><?= number_format($pendingNotifications, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($heroNotificationHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
        </div>
      </div>
      <div class="dashboard__hero-meta">
        <div class="hero-meta__item">
          <span class="hero-meta__label"><?= htmlspecialchars(__('dashboard.hero.meta.last_update.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="hero-meta__value"><?= htmlspecialchars($now->format('d-m-Y H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label"><?= htmlspecialchars(__('dashboard.hero.meta.period.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="hero-meta__value"><?= htmlspecialchars($heroPeriodValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label"><?= htmlspecialchars(__('dashboard.hero.meta.filter.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="hero-meta__value"><?= htmlspecialchars($typeFilterLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label"><?= htmlspecialchars(__('dashboard.hero.meta.last_activity.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="hero-meta__value"><?= htmlspecialchars($lastActivityDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
      </div>
      <div class="dashboard__hero-actions">
        <div class="hero__actions">
          <a class="btn" href="ophaalbevestiging.php"><?= htmlspecialchars(__('dashboard.hero.actions.pickup'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <a class="btn btn--ghost" href="klant-melding.php"><?= htmlspecialchars(__('dashboard.hero.actions.customer_notification'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
          <a class="btn btn--ghost" href="netwerkcheck-brief.php"><?= htmlspecialchars(__('dashboard.hero.actions.network_check'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </div>
        <form method="GET" class="dashboard__filters" aria-label="<?= htmlspecialchars(__('dashboard.filters.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="dashboard__filter">
            <label for="type"><?= htmlspecialchars(__('dashboard.filters.case_type.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select id="type" name="type">
              <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>><?= htmlspecialchars(__('dashboard.filters.case_type.all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($distinctTypes as $typeOption): ?>
                <option value="<?= htmlspecialchars((string) $typeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $typeFilter === $typeOption ? ' selected' : '' ?>><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_types.', (string) $typeOption), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="dashboard__filter">
            <label for="period"><?= htmlspecialchars(__('dashboard.filters.period.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select id="period" name="period">
              <?php foreach ($periodOptions as $option): ?>
                <option value="<?= $option ?>"<?= $periodDays === $option ? ' selected' : '' ?>><?= htmlspecialchars(__('dashboard.filters.period.option', ['days' => $option]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn"><?= htmlspecialchars(__('dashboard.filters.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        </form>
      </div>
    </section>
    <section class="dashboard__highlights" aria-label="<?= htmlspecialchars(__('dashboard.highlights.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">👥</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.customers.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.customers.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($totalCustomers, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars($customerHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">📂</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.cases.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.cases.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($casesInPeriod, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars($caseHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($casesCompletionRate !== null): ?>
          <div class="insight-card__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $casesCompletionRate ?>">
            <span style="width: <?= $casesCompletionRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($casesCompletionMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-cases"><?= htmlspecialchars(__('dashboard.highlights.cases.action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">🏬</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.warehouse.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.warehouse.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($totalWarehouseItems, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars($warehouseHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($warehouseReadyRate !== null): ?>
          <div class="insight-card__progress insight-card__progress--accent" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $warehouseReadyRate ?>">
            <span style="width: <?= $warehouseReadyRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($warehouseReadyMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-warehouse"><?= htmlspecialchars(__('dashboard.highlights.warehouse.action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">✉️</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.notifications.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.notifications.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($notificationTotal, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars($notificationsHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($notificationCompletionRate !== null): ?>
          <div class="insight-card__progress insight-card__progress--soft" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $notificationCompletionRate ?>">
            <span style="width: <?= $notificationCompletionRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($notificationCompletionMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-notifications"><?= htmlspecialchars(__('dashboard.highlights.notifications.action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
      </article>
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">⏱️</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.lead_time.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.lead_time.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value">
          <?php if ($averageLeadTimeLabel !== null): ?>
            <?= htmlspecialchars($averageLeadTimeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          <?php else: ?>
            &mdash;
          <?php endif; ?>
        </p>
        <p class="insight-card__hint"><?= htmlspecialchars($leadTimeHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </article>
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">📅</span>
          <div>
            <h2><?= htmlspecialchars(__('dashboard.highlights.wait_time.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.highlights.wait_time.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <p class="insight-card__value"><?= htmlspecialchars($longestWaitingLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars($waitTimeHint, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </article>
    </section>

    <section class="dashboard__activity-grid" aria-label="<?= htmlspecialchars(__('dashboard.activity.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title"><?= htmlspecialchars($activityCasesTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="activity-card__subtitle"><?= htmlspecialchars($activityCasesSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <span class="activity-card__tag"><?= htmlspecialchars($activityCasesRange, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </header>
        <dl class="activity-card__stats">
          <div class="activity-card__stat">
            <dt><?= htmlspecialchars($activityCasesUpdatesLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd><?= number_format($casesInPeriod, 0, ',', '.') ?></dd>
          </div>
          <div class="activity-card__stat">
            <dt><?= htmlspecialchars($activityCasesCompletedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
            <dd><?= number_format($casesCompleted, 0, ',', '.') ?></dd>
          </div>
          <?php if ($casesCompletionRate !== null): ?>
            <div class="activity-card__stat">
              <dt><?= htmlspecialchars($activityCasesSuccessLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
              <dd><?= htmlspecialchars($casesCompletionRate . '%', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          <?php endif; ?>
        </dl>
        <footer class="activity-card__footer">
          <span><?= htmlspecialchars($activityCasesFooterLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= htmlspecialchars($lastActivityDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
        </footer>
      </article>

      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title"><?= htmlspecialchars($activityNotificationsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="activity-card__subtitle"><?= htmlspecialchars($activityNotificationsSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <span class="activity-card__badge"><?= htmlspecialchars($activityNotificationsTotal, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </header>
        <?php if (empty($notificationsByChannel)): ?>
          <p class="activity-card__empty"><?= htmlspecialchars($activityNotificationsEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="activity-card__list activity-card__list--notifications">
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span class="activity-card__list-label"><?= htmlspecialchars(translate_dashboard_enum('dashboard.notification_channels.', (string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="activity-card__list-value"><?= number_format($count, 0, ',', '.') ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="activity-card__footer activity-card__footer--split">
          <span><?= htmlspecialchars($activityNotificationsFooterLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= number_format($pendingNotifications, 0, ',', '.') ?></strong>
        </div>
      </article>

      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title"><?= htmlspecialchars($activityRecentCasesTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="activity-card__subtitle"><?= htmlspecialchars($activityRecentCasesSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <?php if (empty($recentCases)): ?>
          <p class="activity-card__empty"><?= htmlspecialchars($activityRecentCasesEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="activity-card__list activity-card__list--cases">
            <?php foreach ($recentCases as $case): ?>
              <?php
                $caseSummaryText = trim((string) ($case['summary'] ?? ''));
                $caseTypeLabel = translate_dashboard_enum('dashboard.case_types.', (string) ($case['type'] ?? ''));
                if ($caseSummaryText === '') {
                    $caseSummaryText = $caseTypeLabel !== '' ? $caseTypeLabel : $unknownLabel;
                }
                $caseStatusLabel = translate_dashboard_enum('dashboard.case_status.', (string) ($case['status'] ?? ''));
                $caseUpdatedAt = date('d-m-Y H:i', strtotime((string) $case['updated_at']));
                $caseMeta = strtr($activityRecentCasesMeta, [
                    ':type' => $caseTypeLabel,
                    ':status' => $caseStatusLabel,
                    ':updated_at' => $caseUpdatedAt,
                ]);
              ?>
              <li>
                <div class="activity-card__case-title"><?= htmlspecialchars($caseSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="activity-card__case-meta"><?= htmlspecialchars($caseMeta, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <a class="activity-card__link" href="case.php?id=<?= (int) $case['id'] ?>"><?= htmlspecialchars($activityRecentCasesLink, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="activity-card activity-card--notes">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title"><?= htmlspecialchars($activityNotesTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="activity-card__subtitle"><?= htmlspecialchars($activityNotesSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <?php if (empty($recentNotes)): ?>
          <p class="activity-card__empty"><?= htmlspecialchars($activityNotesEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="note-list">
            <?php foreach ($recentNotes as $note): ?>
              <?php
                $noteSummary = trim((string) ($note['summary'] ?? ''));
                if ($noteSummary === '') {
                    $noteSummary = $unknownLabel;
                }
              ?>
              <li class="note-card">
                <div class="note-card__meta">
                  <span class="note-card__author"><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="note-card__date"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <div class="note-card__body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                <div class="note-card__footer">
                  <span><?= htmlspecialchars($activityNotesCaseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>:</span>
                  <a href="case.php?id=<?= (int) $note['case_id'] ?>"><?= htmlspecialchars($noteSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                  <span> · <?= htmlspecialchars($activityNotesCustomerLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars((string) $note['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </section>

    <section class="dashboard__panel-grid" aria-label="<?= htmlspecialchars($panelGridAria, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <article class="dashboard__panel dashboard__panel--stretch">
        <header class="dashboard__panel-header">
          <div>
            <h2><?= htmlspecialchars($panelPickupsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="dashboard__panel-subtitle"><?= htmlspecialchars($panelPickupsSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <a href="ophaalbevestigingen-list.php" class="btn-link"><?= htmlspecialchars($panelPickupsViewAll, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
        </header>
        <table class="data-table">
          <thead>
            <tr>
              <?php foreach ($panelPickupsHeaders as $header): ?>
                <th><?= htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($upcomingPickups)): ?>
              <tr>
                <td colspan="4" class="empty-state"><?= htmlspecialchars($panelPickupsEmpty, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
              </tr>
            <?php else: ?>
              <?php foreach ($upcomingPickups as $pickup): ?>
                <?php
                  $pickupStatus = translate_dashboard_enum('dashboard.case_status.', (string) ($pickup['status'] ?? ''));
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string) $pickup['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
                    <span class="muted"><?= htmlspecialchars($panelPickupsStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars($pickupStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </td>
                  <td><?= htmlspecialchars((string) ($pickup['ophaalcode'] ?? $pickup['reference_code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($pickup['datumgereed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td>
                    <?php if (!empty($pickup['phone'])): ?>
                      <div class="muted"><?= htmlspecialchars($panelPickupsPhoneLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars((string) $pickup['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($pickup['email'])): ?>
                      <div class="muted"><?= htmlspecialchars($panelPickupsEmailLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>: <?= htmlspecialchars((string) $pickup['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </article>

      <article class="dashboard__panel">
        <header class="dashboard__panel-header">
          <div>
            <h2><?= htmlspecialchars($panelCaseSummaryTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="dashboard__panel-subtitle"><?= htmlspecialchars($panelCaseSummarySubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <div class="case-summary">
          <?php if (empty($caseSummary)): ?>
            <p class="empty-state"><?= htmlspecialchars($caseSummaryEmptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php else: ?>
            <?php foreach ($caseSummary as $type => $statuses): ?>
              <article class="case-summary__item">
                <h3><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_types.', (string) $type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <ul>
                  <?php foreach ($statuses as $status => $count): ?>
                    <li>
                      <span><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_status.', (string) $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <strong><?= (int) $count ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </article>

      <article class="dashboard__panel">
        <header class="dashboard__panel-header">
          <div>
            <h2><?= htmlspecialchars($panelTrendTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="dashboard__panel-subtitle"><?= htmlspecialchars($panelTrendSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <div class="trend-list">
          <?php if (empty($trendData)): ?>
            <p class="empty-state"><?= htmlspecialchars($caseTrendEmptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php else: ?>
            <ul>
              <?php foreach ($trendData as $dateKey => $value): ?>
                <li>
                  <span><?= htmlspecialchars(date('d-m-Y', strtotime($dateKey)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <strong><?= (int) $value ?></strong>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </article>

      <article class="dashboard__panel">
        <header class="dashboard__panel-header">
           <div>
            <h2><?= htmlspecialchars($panelNotificationsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="dashboard__panel-subtitle"><?= htmlspecialchars($panelNotificationsSubtitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
        </header>
        <ul class="notifications-summary">
          <?php if (empty($notificationsByChannel)): ?>
            <li class="empty-state"><?= htmlspecialchars($notificationEmptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php else: ?>
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span><?= htmlspecialchars(translate_dashboard_enum('dashboard.notification_channels.', (string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
        <div class="notifications-summary__footer">
          <span><?= htmlspecialchars($panelNotificationsFooter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
      </article>
    </section>
  </main>

  <div class="modal" id="modal-cases" role="dialog" aria-modal="true" aria-labelledby="modalCasesTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalCasesTitle"><?= htmlspecialchars($modalCasesTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars($modalCloseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p><?= htmlspecialchars($modalCasesSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($modalCasesCompletionLabel !== null): ?>
          <p class="modal__note"><?= htmlspecialchars($modalCasesCompletionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (empty($caseSummary)): ?>
          <p class="empty-state"><?= htmlspecialchars($modalCaseEmptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <div class="modal__grid">
            <?php foreach ($caseSummary as $type => $statuses): ?>
              <article class="modal__card">
                <h3><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_types.', (string) $type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <ul class="modal__list">
                  <?php foreach ($statuses as $status => $count): ?>
                    <li>
                      <span><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_status.', (string) $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <strong><?= (int) $count ?></strong>
                    </li>
                  <?php endforeach; ?>
                </ul>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <footer class="modal__footer">
        <p><?= htmlspecialchars($modalCasesTip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </footer>
    </div>
  </div>

  <div class="modal" id="modal-warehouse" role="dialog" aria-modal="true" aria-labelledby="modalWarehouseTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalWarehouseTitle"><?= htmlspecialchars($modalWarehouseTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars($modalCloseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p><?= htmlspecialchars($modalWarehouseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <ul class="modal__list modal__list--stacked">
          <li><span><?= htmlspecialchars($modalWarehouseLabels['available'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= number_format($warehouseAvailableTotal, 0, ',', '.') ?></strong></li>
          <li><span><?= htmlspecialchars($modalWarehouseLabels['ready'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= number_format($warehouseReadyTotal, 0, ',', '.') ?></strong></li>
          <li><span><?= htmlspecialchars($modalWarehouseLabels['reserved'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= number_format($warehouseReservedTotal, 0, ',', '.') ?></strong></li>
        </ul>
        <?php if (!empty($warehouseStatusCounts)): ?>
          <div class="modal__grid modal__grid--compact">
            <?php foreach ($warehouseStatusCounts as $status => $count): ?>
              <div class="modal__stat">
                <span><?= htmlspecialchars(translate_dashboard_enum('dashboard.warehouse.status.', (string) $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <footer class="modal__footer">
        <p><?= htmlspecialchars($modalWarehouseTip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </footer>
    </div>
  </div>

  <div class="modal" id="modal-notifications" role="dialog" aria-modal="true" aria-labelledby="modalNotificationsTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalNotificationsTitle"><?= htmlspecialchars($modalNotificationsTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars($modalCloseLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p><?= htmlspecialchars($modalNotificationsSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if (empty($notificationsByChannel)): ?>
          <p class="empty-state"><?= htmlspecialchars($modalNotificationEmptyMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="modal__list">
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span><?= htmlspecialchars(translate_dashboard_enum('dashboard.notification_channels.', (string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="modal__note"><?= htmlspecialchars($modalNotificationsOpen, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <footer class="modal__footer">
        <p><?= htmlspecialchars($modalNotificationsTip, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </footer>
    </div>
  </div>

  <footer class="main-footer">
    <div class="container">
      <p><?= htmlspecialchars($footerCopyright, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>
  </footer>

  <script>
  (function() {
    const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let activeModal = null;
    let previousFocus = null;
    const focusHandlers = new WeakMap();

    function createTrapHandler(modal) {
      return function(event) {
        if (event.key !== 'Tab') {
          return;
        }

        const focusable = modal.querySelectorAll(focusableSelector);
        if (!focusable.length) {
          event.preventDefault();
          return;
        }

        const first = focusable[0];
        const last = focusable[focusable.length - 1];

        if (event.shiftKey) {
          if (document.activeElement === first) {
            event.preventDefault();
            last.focus();
          }
        } else if (document.activeElement === last) {
          event.preventDefault();
          first.focus();
        }
      };
    }

    function openModal(modal, trigger) {
      if (!modal || activeModal === modal) {
        return;
      }

      if (activeModal && activeModal !== modal) {
        closeModal(activeModal, false);
      }

      previousFocus = trigger;
      modal.removeAttribute('hidden');
      requestAnimationFrame(() => {
        modal.classList.add('modal--visible');
      });
      document.body.classList.add('has-modal');

      const trapHandler = createTrapHandler(modal);
      focusHandlers.set(modal, trapHandler);
      modal.addEventListener('keydown', trapHandler);

      const focusable = modal.querySelectorAll(focusableSelector);
      const targetFocus = focusable.length ? focusable[0] : modal;
      targetFocus.focus({ preventScroll: true });
      activeModal = modal;
    }

    function closeModal(modal, restoreFocus = true) {
      if (!modal) {
        return;
      }

      modal.classList.remove('modal--visible');
      const trapHandler = focusHandlers.get(modal);
      if (trapHandler) {
        modal.removeEventListener('keydown', trapHandler);
        focusHandlers.delete(modal);
      }

      setTimeout(() => {
        modal.setAttribute('hidden', '');
      }, 220);

      document.body.classList.remove('has-modal');

      if (restoreFocus && previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus({ preventScroll: true });
      }

      if (activeModal === modal) {
        activeModal = null;
      }
    }

    document.addEventListener('click', (event) => {
      const openTrigger = event.target.closest('[data-modal-open]');
      if (openTrigger) {
        event.preventDefault();
        const modalId = openTrigger.getAttribute('data-modal-open');
        const modal = document.getElementById(modalId);
        openModal(modal, openTrigger);
        return;
      }

      const closeTrigger = event.target.closest('[data-modal-close]');
      if (closeTrigger) {
        const modal = closeTrigger.closest('.modal');
        closeModal(modal);
      }
    });

    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && activeModal) {
        event.preventDefault();
        closeModal(activeModal);
      }
    });
  })();
  </script>
</body>
</html>
