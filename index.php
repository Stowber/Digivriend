<?php

declare(strict_types=1);

use App\Support\Repositories\CaseRepository;


require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$caseRepository = new CaseRepository($pdo);

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
        <ul>
          <li><a href="index.php" aria-current="page">Dashboard</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php">Documenten</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container dashboard">
    <section class="dashboard__intro">
      <div>
        <h1>Operationeel overzicht</h1>
        <p>Monitor lopende cases, openstaande ophaalbevestigingen en de laatste activiteiten van klanten in één blik.</p>
      </div>
      <div class="dashboard__quick-actions">
        <a class="btn" href="ophaalbevestiging.php">Nieuwe ophaalbevestiging</a>
        <a class="btn btn--ghost" href="klant-melding.php">Nieuwe klantmelding</a>
      </div>
    </section>

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

    <section class="dashboard__stats">
      <article class="stat-card">
        <h2>Totaal klanten</h2>
        <p class="stat-card__value"><?= number_format($totalCustomers, 0, ',', '.') ?></p>
        <span class="stat-card__hint">Unieke klantprofielen in de database</span>
      </article>
      <article class="stat-card">
        <h2>Actieve cases</h2>
        <p class="stat-card__value"><?= number_format($openCases, 0, ',', '.') ?></p>
        <span class="stat-card__hint">Cases die nog aandacht vereisen</span>
      </article>
      <article class="stat-card">
        <h2>Ophaal vandaag</h2>
        <p class="stat-card__value"><?= number_format($todayPickups, 0, ',', '.') ?></p>
        <span class="stat-card__hint">Klaar voor overdracht op <?= date('d-m-Y') ?></span>
      </article>
      <article class="stat-card">
        <h2>Langste wachttijd</h2>
        <p class="stat-card__value"><?= $longestWaiting > 0 ? $longestWaiting . ' dagen' : '—' ?></p>
        <span class="stat-card__hint">Sinds datum gereed</span>
      </article>
      <article class="stat-card">
        <h2>Cases deze periode</h2>
        <p class="stat-card__value"><?= number_format($casesInPeriod, 0, ',', '.') ?></p>
        <span class="stat-card__hint">Vanaf <?= htmlspecialchars($periodStart->format('d-m-Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </article>
      <article class="stat-card">
        <h2>Gem. doorlooptijd</h2>
        <p class="stat-card__value"><?= $averageLeadTime !== null ? $averageLeadTime . ' dagen' : '—' ?></p>
        <span class="stat-card__hint">Van gereed tot opgehaald</span>
      </article>
      <article class="stat-card">
        <h2>Open meldingen</h2>
        <p class="stat-card__value"><?= number_format($pendingNotifications, 0, ',', '.') ?></p>
        <span class="stat-card__hint">Nog te informeren klanten</span>
      </article>
    </section>

    <section class="dashboard__grid">
      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Openstaande ophaalbevestigingen</h2>
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

      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Case verdeling</h2>
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
                    <li><span><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $status)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><strong><?= (int) $count ?></strong></li>
                  <?php endforeach; ?>
                </ul>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
      </section>

      <section class="dashboard__grid">
      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Activiteit laatste <?= (int) $periodDays ?> dagen</h2>
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
      </div>

      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Verstuurde meldingen</h2>
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
      </div>
    </section>

      <section class="dashboard__grid">
      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Laatste cases</h2>
        </header>
        <ul class="timeline">
          <?php if (empty($recentCases)): ?>
            <li class="empty-state">Nog geen cases geregistreerd.</li>
          <?php else: ?>
            <?php foreach ($recentCases as $case): ?>
              <li>
                <div class="timeline__title"><?= htmlspecialchars((string) ($case['summary'] ?? ucfirst((string) $case['type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <div class="timeline__meta">Type: <?= htmlspecialchars((string) $case['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Status: <?= htmlspecialchars((string) $case['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $case['updated_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <a class="btn-link" href="case.php?id=<?= (int) $case['id'] ?>">Bekijk case</a>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>

      <div class="dashboard__panel">
        <header class="dashboard__panel-header">
          <h2>Recente notities</h2>
        </header>
        <ul class="notes">
          <?php if (empty($recentNotes)): ?>
            <li class="empty-state">Er zijn nog geen notities toegevoegd.</li>
          <?php else: ?>
            <?php foreach ($recentNotes as $note): ?>
              <li>
                <div class="notes__header">
                  <strong><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <span class="muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </div>
                <div class="notes__body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                <div class="notes__footer">Case: <a href="case.php?id=<?= (int) $note['case_id'] ?>"><?= htmlspecialchars((string) ($note['summary'] ?? 'Onbekend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a> · Klant: <?= htmlspecialchars((string) $note['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </li>
            <?php endforeach; ?>
          <?php endif; ?>
        </ul>
      </div>
    </section>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
