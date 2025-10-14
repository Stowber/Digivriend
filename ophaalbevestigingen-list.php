<?php


declare(strict_types=1);

use App\Http\Response;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$searchCode = trim((string) ($_GET['search'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$fromDate = trim((string) ($_GET['from'] ?? ''));
$toDate = trim((string) ($_GET['to'] ?? ''));

try {
    $sql = "SELECT o.*, c.status AS case_status, c.id AS case_id, c.updated_at AS case_updated_at
            FROM ophaalbevestigingen o
            LEFT JOIN cases c ON c.reference_code = o.ophaalcode AND c.type = 'pickup'";
    $conditions = [];
    $parameters = [];

    if ($searchCode !== '') {
        $conditions[] = '(o.ophaalcode LIKE :search OR o.klantnaam LIKE :search)';
        $parameters['search'] = sprintf('%%%s%%', $searchCode);
    }

    if ($statusFilter !== '') {
        $conditions[] = '(c.status = :status OR (c.status IS NULL AND o.status = :status))';
        $parameters['status'] = $statusFilter;
    }

    if ($fromDate !== '' && strtotime($fromDate) !== false) {
        $conditions[] = 'o.datumgereed >= :fromDate';
        $parameters['fromDate'] = $fromDate;
    }

    if ($toDate !== '' && strtotime($toDate) !== false) {
        $conditions[] = 'o.datumgereed <= :toDate';
        $parameters['toDate'] = $toDate;
    }

    if (!empty($conditions)) {
        $sql .= ' WHERE ' . implode(' AND ', $conditions);
    }

    $sql .= ' ORDER BY o.datumgereed ASC, o.created_at DESC';

    $statement = $pdo->prepare($sql);
    $statement->execute($parameters);
    $bevestigingen = $statement->fetchAll();
} catch (\PDOException $exception) {
    Response::error('Kon de lijst met ophaalbevestigingen niet ophalen.', 500);
}
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Ophaalbevestigingen - Overzicht</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/ophaalbevestiging-list.css">
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
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="ophaalbevestiging.php" aria-current="page">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <div class="container">
      <div class="page-header">
        <h1>Ophaalbevestigingen</h1>
        <p>Beheer de lopende ophaalbevestigingen, volg de status en genereer direct een bevestiging voor jouw klant.</p>
      </div>

   <section class="table-shell">
        <div class="table-actions">
          <form method="GET" class="search-bar">
            <div class="filter-group">
              <label for="search">Zoek</label>
              <input type="text" name="search" id="search" placeholder="Naam of code" value="<?= htmlspecialchars($searchCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="filter-group">
              <label for="status">Status</label>
              <select name="status" id="status">
                <option value="">Alle</option>
                <option value="klaar" <?= $statusFilter === 'klaar' ? 'selected' : '' ?>>Klaar</option>
                <option value="opgehaald" <?= $statusFilter === 'opgehaald' ? 'selected' : '' ?>>Opgehaald</option>
              </select>
            </div>
            <div class="filter-group">
              <label for="from">Van</label>
              <input type="date" name="from" id="from" value="<?= htmlspecialchars($fromDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="filter-group">
              <label for="to">Tot</label>
              <input type="date" name="to" id="to" value="<?= htmlspecialchars($toDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </div>
            <div class="filter-actions">
              <button type="submit" class="btn">Filter</button>
              <a href="ophaalbevestigingen-list.php" class="btn btn--ghost">Reset</a>
            </div>
          </form>
          <a href="ophaalbevestiging.php" class="btn">Nieuwe bevestiging</a>
        </div>

        <table>
          <thead>
            <tr>
              <th>Case</th>
              <th>Contact</th>
              <th>Apparaat</th>
              <th>Datum gereed</th>
              <th>Status</th>
              <th>Laatst bijgewerkt</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($bevestigingen)): ?>
              <tr>
                <td colspan="7" class="empty-state">Geen resultaten gevonden.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($bevestigingen as $bev): ?>
                <?php
                  $status = $bev['case_status'] ?? $bev['status'] ?? '';
                  $statusClass = $status === 'opgehaald' ? 'status-pill--picked' : 'status-pill--ready';
                  $merkModel = trim(((string) ($bev['apparaatmerk'] ?? '')) . ' ' . ((string) ($bev['apparaatmodel'] ?? '')));
                  if ($merkModel === '') {
                      $merkModel = (string) ($bev['merkmodel'] ?? 'Onbekend');
                  }
                  $caseLink = $bev['case_id'] ? 'case.php?id=' . (int) $bev['case_id'] : null;
                  $updatedAt = $bev['case_updated_at'] ?? $bev['updated_at'] ?? $bev['created_at'] ?? null;
                ?>
                <tr>
                  <td>
                    <strong><?= htmlspecialchars((string) $bev['klantnaam'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong><br>
                    <span class="muted">Code: <?= htmlspecialchars((string) $bev['ophaalcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><br>
                    <?php if ($caseLink): ?>
                      <a class="btn-link" href="<?= $caseLink ?>">Case bekijken</a>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!empty($bev['klanttelefoon'])): ?>
                      <div class="muted">Tel: <?= htmlspecialchars((string) $bev['klanttelefoon'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                    <?php if (!empty($bev['klantemail'])): ?>
                      <div class="muted">E-mail: <?= htmlspecialchars((string) $bev['klantemail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <?php endif; ?>
                  </td>
                  <td><?= htmlspecialchars($merkModel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) $bev['datumgereed'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="status-pill <?= $statusClass ?>"><?= htmlspecialchars(ucfirst((string) $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td><?= $updatedAt ? htmlspecialchars(date('d-m-Y H:i', strtotime((string) $updatedAt)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                  <td class="table-actions__buttons">
                    <?php if ($status === 'klaar'): ?>
                      <form action="pickup-sign.php" method="GET" class="inline-form">
                        <input type="hidden" name="id" value="<?= (int) $bev['id'] ?>">
                        <button type="submit" class="btn">Ophalen</button>
                      </form>
                    <?php else: ?>
                       <a href="generate-apparaat-opgehaald.php?id=<?= (int) $bev['id'] ?>" class="btn btn--ghost" target="_blank">Bekijk PDF</a>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </section>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
