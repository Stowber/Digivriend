<?php

declare(strict_types=1);

use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\WarehouseRepository;


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

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
    $trendStatementSql .= ' AND type = :type';
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
    ? 'Alle cases'
    : ucfirst(str_replace('_', ' ', (string) $typeFilter));
$now = new DateTimeImmutable('now');
$lastActivityDate = null;
if ($trendData !== []) {
    $trendKeys = array_keys($trendData);
    $lastTrendKey = end($trendKeys);
    if (is_string($lastTrendKey)) {
        $lastActivityDate = date_create_immutable($lastTrendKey) ?: null;
    }
}

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Digivriend - Dashboard</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle">Serviceplatform</span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('dashboard'); ?>
      </nav>
    </div>
  </header>

  <main class="container dashboard">
    <section class="dashboard__hero" aria-labelledby="dashboardTitle">
      <div class="dashboard__hero-layout">
        <div class="dashboard__hero-intro">
          <span class="hero__badge">Realtime overzicht</span>
          <h1 id="dashboardTitle">Digivriend Operations Dashboard</h1>
          <p><?= number_format($openCases, 0, ',', '.') ?> actieve cases en <?= number_format($pendingNotifications, 0, ',', '.') ?> meldingen wachten op opvolging. Houd magazijn en communicatie real-time in het oog.</p>
        </div>
        <div class="dashboard__hero-metrics">
          <article class="hero-metric">
            <span class="hero-metric__label">Actieve cases</span>
            <span class="hero-metric__value"><?= number_format($openCases, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($longestWaiting > 0 ? $longestWaiting . ' dagen wachttijd' : 'Directe opvolging', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
          <article class="hero-metric">
            <span class="hero-metric__label">Ophaalmomenten vandaag</span>
            <span class="hero-metric__value"><?= number_format($todayPickups, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($todayPickups > 0 ? 'Plan overdracht en communicatie' : 'Geen ophaalacties gepland', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
          <article class="hero-metric">
            <span class="hero-metric__label">Open meldingen</span>
            <span class="hero-metric__value"><?= number_format($pendingNotifications, 0, ',', '.') ?></span>
            <span class="hero-metric__hint"><?= htmlspecialchars($pendingNotifications > 0 ? 'Nog te informeren klanten' : 'Alle klanten op de hoogte', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </article>
        </div>
      </div>
      <div class="dashboard__hero-meta">
        <div class="hero-meta__item">
          <span class="hero-meta__label">Laatste update</span>
          <span class="hero-meta__value"><?= htmlspecialchars($now->format('d-m-Y H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label">Periode</span>
          <span class="hero-meta__value">Laatste <?= (int) $periodDays ?> dagen</span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label">Actief filter</span>
          <span class="hero-meta__value"><?= htmlspecialchars($typeFilterLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
        <div class="hero-meta__item">
          <span class="hero-meta__label">Laatste activiteit</span>
          <span class="hero-meta__value"><?= $lastActivityDate instanceof DateTimeInterface ? htmlspecialchars($lastActivityDate->format('d-m-Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : 'Nog geen activiteit' ?></span>
        </div>
      </div>
      <div class="dashboard__hero-actions">
        <div class="hero__actions">
          <a class="btn" href="ophaalbevestiging.php">Nieuwe ophaalbevestiging</a>
          <a class="btn btn--ghost" href="klant-melding.php">Nieuwe klantmelding</a>
        </div>
        <form method="GET" class="dashboard__filters" aria-label="Dashboardfilters">
          <div class="dashboard__filter">
            <label for="type">Case type</label>
            <select id="type" name="type">
              <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>>Alle typen</option>
              <?php foreach ($distinctTypes as $typeOption): ?>
                <option value="<?= htmlspecialchars((string) $typeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $typeFilter === $typeOption ? ' selected' : '' ?>><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $typeOption)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="dashboard__filter">
            <label for="period">Periode</label>
            <select id="period" name="period">
              <?php foreach ($periodOptions as $option): ?>
                <option value="<?= $option ?>"<?= $periodDays === $option ? ' selected' : '' ?>>Laatste <?= $option ?> dagen</option>
              <?php endforeach; ?>
            </select>
          </div>
          <button type="submit" class="btn">Filter toepassen</button>
        </form>
      </div>
    </section>
      <section class="dashboard__highlights" aria-label="Belangrijkste KPI&#39;s">
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">👥</span>
          <div>
            <h2>Klantbestand</h2>
            <p>Unieke profielen in beheer</p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($totalCustomers, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars(number_format($openCases, 0, ',', '.') . ' actieve cases gekoppeld', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">📂</span>
          <div>
            <h2>Case traject</h2>
            <p>Werkvoorraad in geselecteerde periode</p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($casesInPeriod, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars(number_format($casesCompleted, 0, ',', '.') . ' afgerond', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($casesCompletionRate !== null): ?>
          <div class="insight-card__progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $casesCompletionRate ?>">
            <span style="width: <?= $casesCompletionRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($casesCompletionRate . '% van de cases afgerond', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-cases">Diepte-inzicht</button>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">🏬</span>
          <div>
            <h2>Magazijnstatus</h2>
            <p>Beschikbaarheid &amp; reserveringen</p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($totalWarehouseItems, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars(number_format($warehouseAvailableTotal, 0, ',', '.') . ' beschikbaar · ' . number_format($warehouseReadyTotal, 0, ',', '.') . ' klaar', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($warehouseReadyRate !== null): ?>
          <div class="insight-card__progress insight-card__progress--accent" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $warehouseReadyRate ?>">
            <span style="width: <?= $warehouseReadyRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($warehouseReadyRate . '% klaar voor uitgifte', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-warehouse">Bekijk magazijn</button>
      </article>
      <article class="insight-card insight-card--interactive">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">✉️</span>
          <div>
            <h2>Communicatie</h2>
            <p>Uitgestuurde notificaties</p>
          </div>
        </header>
        <p class="insight-card__value"><?= number_format($notificationTotal, 0, ',', '.') ?></p>
        <p class="insight-card__hint"><?= htmlspecialchars(number_format($pendingNotifications, 0, ',', '.') . ' meldingen wachten nog', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php if ($notificationCompletionRate !== null): ?>
          <div class="insight-card__progress insight-card__progress--soft" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $notificationCompletionRate ?>">
            <span style="width: <?= $notificationCompletionRate ?>%;"></span>
          </div>
          <p class="insight-card__meta"><?= htmlspecialchars($notificationCompletionRate . '% afgehandeld', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php endif; ?>
        <button type="button" class="insight-card__action" data-modal-open="modal-notifications">Bekijk kanalen</button>
      </article>
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">⏱️</span>
          <div>
            <h2>Gem. doorlooptijd</h2>
            <p>Van gereed tot opgehaald</p>
          </div>
        </header>
        <p class="insight-card__value"><?= $averageLeadTime !== null ? htmlspecialchars($averageLeadTime . ' dagen', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '&mdash;' ?></p>
        <p class="insight-card__hint">Focus op snelle opvolging van gereedmeldingen.</p>
      </article>
      <article class="insight-card">
        <header class="insight-card__header">
          <span class="insight-card__icon" aria-hidden="true">📅</span>
          <div>
            <h2>Langste wachttijd</h2>
            <p>Hoelang staat de oudste case klaar?</p>
          </div>
        </header>
        <p class="insight-card__value"><?= htmlspecialchars($longestWaiting > 0 ? $longestWaiting . ' dagen' : 'Geen wachtrij', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p class="insight-card__hint">Monitor op escalatie en extra opvolging.</p>
      </article>
    </section>

    <section class="dashboard__activity-grid" aria-label="Teamactiviteiten en communicatie">
      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title">Activiteit laatste <?= (int) $periodDays ?> dagen</h2>
            <p class="activity-card__subtitle">Inzichten in de case-updates binnen de geselecteerde periode.</p>
          </div>
          <span class="activity-card__tag"><?= htmlspecialchars($periodStart->format('d-m-Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> &ndash; <?= htmlspecialchars($now->format('d-m-Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </header>
        <dl class="activity-card__stats">
          <div class="activity-card__stat">
            <dt>Case-updates</dt>
            <dd><?= number_format($casesInPeriod, 0, ',', '.') ?></dd>
          </div>
          <div class="activity-card__stat">
            <dt>Afgerond</dt>
            <dd><?= number_format($casesCompleted, 0, ',', '.') ?></dd>
          </div>
          <?php if ($casesCompletionRate !== null): ?>
            <div class="activity-card__stat">
              <dt>Succesratio</dt>
              <dd><?= htmlspecialchars($casesCompletionRate . '%', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          <?php endif; ?>
        </dl>
        <footer class="activity-card__footer">
          <span>Laatste update</span>
          <strong><?= htmlspecialchars($lastActivityDate instanceof DateTimeInterface ? $lastActivityDate->format('d-m-Y H:i') : 'Nog geen activiteit', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
        </footer>
      </article>

      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title">Verstuurde meldingen</h2>
            <p class="activity-card__subtitle">Overzicht van kanalen die klanten recent bereikten.</p>
          </div>
          <span class="activity-card__badge">Totaal <?= number_format($notificationTotal, 0, ',', '.') ?></span>
        </header>
        <?php if (empty($notificationsByChannel)): ?>
          <p class="activity-card__empty">Er zijn nog geen meldingen verzonden in deze periode.</p>
        <?php else: ?>
          <ul class="activity-card__list activity-card__list--notifications">
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span class="activity-card__list-label"><?= htmlspecialchars(strtoupper((string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="activity-card__list-value"><?= number_format($count, 0, ',', '.') ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <div class="activity-card__footer activity-card__footer--split">
          <span>Open meldingen</span>
          <strong><?= number_format($pendingNotifications, 0, ',', '.') ?></strong>
        </div>
      </article>

      <article class="activity-card">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title">Laatste cases</h2>
            <p class="activity-card__subtitle">Recent bijgewerkte dossiers voor snelle opvolging.</p>
          </div>
        </header>
        <?php if (empty($recentCases)): ?>
          <p class="activity-card__empty">Nog geen cases geregistreerd.</p>
        <?php else: ?>
          <ul class="activity-card__list activity-card__list--cases">
            <?php foreach ($recentCases as $case): ?>
              <li>
                <div class="activity-card__case-title"><?= htmlspecialchars((string) ($case['summary'] ?? ucfirst((string) $case['type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="activity-card__case-meta">Type: <?= htmlspecialchars((string) $case['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Status: <?= htmlspecialchars((string) $case['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $case['updated_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <a class="activity-card__link" href="case.php?id=<?= (int) $case['id'] ?>">Bekijk case</a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="activity-card activity-card--notes">
        <header class="activity-card__header">
          <div>
            <h2 class="activity-card__title">Recente notities</h2>
            <p class="activity-card__subtitle">Laatste klantinteracties en servicelogboek.</p>
          </div>
        </header>
        <?php if (empty($recentNotes)): ?>
          <p class="activity-card__empty">Er zijn nog geen notities toegevoegd.</p>
        <?php else: ?>
          <ul class="note-list">
            <?php foreach ($recentNotes as $note): ?>
              <li class="note-card">
                <div class="note-card__meta">
                  <span class="note-card__author"><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="note-card__date"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <div class="note-card__body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                <div class="note-card__footer">Case: <a href="case.php?id=<?= (int) $note['case_id'] ?>"><?= htmlspecialchars((string) ($note['summary'] ?? 'Onbekend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a> · Klant: <?= htmlspecialchars((string) $note['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </section>

    <section class="dashboard__panel-grid" aria-label="Operationele details">
      <article class="dashboard__panel dashboard__panel--stretch">
        <header class="dashboard__panel-header">
          <div>
            <h2>Openstaande ophaalbevestigingen</h2>
            <p class="dashboard__panel-subtitle">Realtime overzicht van klanten die gereed staan</p>
          </div>
          <a href="ophaalbevestigingen-list.php" class="btn-link">Bekijk alle</a>
        </header>
        <table class="data-table">
          <thead>
            <tr>
              <th>Klant</th>
              <th>Code</th>
              <th>Datum gereed</th>
              <th>Contact</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($upcomingPickups)): ?>
              <tr>
                <td colspan="4" class="empty-state">Geen openstaande bevestigingen.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($upcomingPickups as $pickup): ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string) $pickup['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
                    <span class="muted">Status: <?= htmlspecialchars((string) $pickup['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </td>
                  <td><?= htmlspecialchars((string) ($pickup['ophaalcode'] ?? $pickup['reference_code']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($pickup['datumgereed'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td>
                    <?php if (!empty($pickup['phone'])): ?>
                      <div class="muted">Tel: <?= htmlspecialchars((string) $pickup['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($pickup['email'])): ?>
                      <div class="muted">E-mail: <?= htmlspecialchars((string) $pickup['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      w</article>

      <article class="dashboard__panel">
        <header class="dashboard__panel-header">
          <div>
            <h2>Case verdeling</h2>
            <p class="dashboard__panel-subtitle">Inzicht per type en status</p>
          </div>
        </header>
        <div class="case-summary">
          <?php if (empty($caseSummary)): ?>
            <p class="empty-state">Nog geen cases aangemaakt.</p>
          <?php else: ?>
            <?php foreach ($caseSummary as $type => $statuses): ?>
              <article class="case-summary__item">
                <h3><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $type)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <ul>
                  <?php foreach ($statuses as $status => $count): ?>
                    <li>
                      <span><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $status)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
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
            <h2>Activiteit laatste <?= (int) $periodDays ?> dagen</h2>
            <p class="dashboard__panel-subtitle">Aantal case-updates per dag</p>
          </div>
        </header>
        <div class="trend-list">
          <?php if (empty($trendData)): ?>
            <p class="empty-state">Geen case-activiteit in deze periode.</p>
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
            <h2>Verstuurde meldingen</h2>
            <p class="dashboard__panel-subtitle">Kanaalprestatie en follow-up</p>
          </div>
        </header>
        <ul class="notifications-summary">
          <?php if (empty($notificationsByChannel)): ?>
            <li class="empty-state">Nog geen meldingen verzonden in deze periode.</li>
          <?php else: ?>
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span><?= htmlspecialchars(strtoupper((string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
        <div class="notifications-summary__footer">
          <span>Afgeronde cases: <strong><?= number_format($casesCompleted, 0, ',', '.') ?></strong></span>
        </div>
      </article>
    </section>
  </main>

  <div class="modal" id="modal-cases" role="dialog" aria-modal="true" aria-labelledby="modalCasesTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalCasesTitle">Diepte-inzicht case traject</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="Sluit pop-up"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p>In de laatste <?= (int) $periodDays ?> dagen zijn <?= number_format($casesInPeriod, 0, ',', '.') ?> cases aangemaakt waarvan <?= number_format($casesCompleted, 0, ',', '.') ?> werden afgerond.</p>
        <?php if ($casesCompletionRate !== null): ?>
          <p class="modal__note">Afrondingspercentage: <strong><?= htmlspecialchars($casesCompletionRate . '%', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>.</p>
        <?php endif; ?>
        <?php if (empty($caseSummary)): ?>
          <p class="empty-state">Nog geen cases aangemaakt.</p>
        <?php else: ?>
          <div class="modal__grid">
            <?php foreach ($caseSummary as $type => $statuses): ?>
              <article class="modal__card">
                <h3><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $type)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <ul class="modal__list">
                  <?php foreach ($statuses as $status => $count): ?>
                    <li>
                      <span><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $status)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
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
        <p>Tip: filter op type om specifieke diensten sneller te analyseren.</p>
      </footer>
    </div>
  </div>

  <div class="modal" id="modal-warehouse" role="dialog" aria-modal="true" aria-labelledby="modalWarehouseTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalWarehouseTitle">Magazijninzicht</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="Sluit pop-up"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p>Het magazijn bevat <?= number_format($totalWarehouseItems, 0, ',', '.') ?> registraties met <?= number_format($warehouseReadyTotal, 0, ',', '.') ?> klaar voor uitgifte en <?= number_format($warehouseReservedTotal, 0, ',', '.') ?> gereserveerd.</p>
        <ul class="modal__list modal__list--stacked">
          <li><span>Beschikbaar</span><strong><?= number_format($warehouseAvailableTotal, 0, ',', '.') ?></strong></li>
          <li><span>Klaar</span><strong><?= number_format($warehouseReadyTotal, 0, ',', '.') ?></strong></li>
          <li><span>Gereserveerd</span><strong><?= number_format($warehouseReservedTotal, 0, ',', '.') ?></strong></li>
        </ul>
        <?php if (!empty($warehouseStatusCounts)): ?>
          <div class="modal__grid modal__grid--compact">
            <?php foreach ($warehouseStatusCounts as $status => $count): ?>
              <div class="modal__stat">
                <span><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $status)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <footer class="modal__footer">
        <p>Plan uitgiftes vanuit dit overzicht en stem af met het serviceteam.</p>
      </footer>
    </div>
  </div>

  <div class="modal" id="modal-notifications" role="dialog" aria-modal="true" aria-labelledby="modalNotificationsTitle" hidden>
    <div class="modal__overlay" data-modal-close></div>
    <div class="modal__content" role="document">
      <header class="modal__header">
        <h2 id="modalNotificationsTitle">Notificatiekanalen</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="Sluit pop-up"><span aria-hidden="true">&times;</span></button>
      </header>
      <div class="modal__body">
        <p>In de geselecteerde periode zijn <?= number_format($notificationTotal, 0, ',', '.') ?> meldingen verstuurd naar klanten.</p>
        <?php if (empty($notificationsByChannel)): ?>
          <p class="empty-state">Nog geen meldingen verzonden in deze periode.</p>
        <?php else: ?>
          <ul class="modal__list">
            <?php foreach ($notificationsByChannel as $channel => $count): ?>
              <li>
                <span><?= htmlspecialchars(strtoupper((string) $channel), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <strong><?= (int) $count ?></strong>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
        <p class="modal__note">Open meldingen: <strong><?= number_format($pendingNotifications, 0, ',', '.') ?></strong>.</p>
      </div>
      <footer class="modal__footer">
        <p>Laat meldingen automatisch opvolgen of plan handmatige acties direct.</p>
      </footer>
    </div>
  </div>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
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
