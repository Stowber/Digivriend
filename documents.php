<?php

declare(strict_types=1);

use App\Support\Documents\DocumentRepository;
use App\Support\Repositories\CaseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$documentRepository = new DocumentRepository($pdo);
$caseRepository = new CaseRepository($pdo);

$typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$caseIdFilter = filter_input(INPUT_GET, 'case_id', FILTER_VALIDATE_INT);

$documents = $documentRepository->recent(100);

if ($typeFilter !== 'all') {
    $documents = array_values(array_filter($documents, static fn (array $doc): bool => ($doc['type'] ?? '') === $typeFilter));
}

if ($caseIdFilter) {
    $documents = array_values(array_filter($documents, static fn (array $doc): bool => (int) ($doc['case_id'] ?? 0) === (int) $caseIdFilter));
}

$types = array_unique(array_map(static fn (array $doc): string => (string) $doc['type'], $documents));
sort($types);

?><!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Documentarchief - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/documents.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="devices.php">Klanten &amp; apparaten</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php" aria-current="page">Documenten</a></li>
          <li><a href="magazyn.php">Magazyn</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container documents">
    <div class="page-header">
      <h1>Documentarchief</h1>
      <p>Bekijk en exporteer recent aangemaakte documenten zoals ophaalbevestigingen, handtekeningen en toestemmingsformulieren.</p>
    </div>

    <form method="GET" class="documents__filters" aria-label="Documentfilters">
      <label>
        <span>Type</span>
        <select name="type">
          <option value="all"<?= $typeFilter === 'all' ? ' selected' : '' ?>>Alle documenten</option>
          <?php foreach ($types as $typeOption): ?>
            <option value="<?= htmlspecialchars((string) $typeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $typeFilter === $typeOption ? ' selected' : '' ?>><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $typeOption)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>Case ID</span>
        <input type="number" name="case_id" value="<?= $caseIdFilter ? (int) $caseIdFilter : '' ?>" placeholder="Bijv. 42">
      </label>
      <button type="submit" class="btn">Filter</button>
    </form>

    <section class="documents__list">
      <?php if (empty($documents)): ?>
        <p class="empty-state">Geen documenten gevonden voor de gekozen filters.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Datum</th>
              <th>Type</th>
              <th>Case</th>
              <th>Bestand</th>
              <th>Actie</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($documents as $document): ?>
              <?php
                $createdAt = isset($document['created_at']) ? new DateTimeImmutable((string) $document['created_at']) : null;
                $caseId = isset($document['case_id']) ? (int) $document['case_id'] : null;
                $caseSummary = null;
                if ($caseId) {
                    $case = $caseRepository->findById($caseId);
                    $caseSummary = $case['summary'] ?? $case['type'] ?? null;
                }
              ?>
              <tr>
                <td><?= $createdAt ? htmlspecialchars($createdAt->format('d-m-Y H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                <td><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $document['type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <?php if ($caseId): ?>
                    <a class="btn-link" href="case.php?id=<?= $caseId ?>">Case #<?= $caseId ?></a>
                    <?php if ($caseSummary): ?><div class="muted"><?= htmlspecialchars((string) $caseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div><?php endif; ?>
                  <?php else: ?>
                    <span class="muted">—</span>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars((string) $document['file_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                <td>
                  <?php $fileAbsolute = __DIR__ . '/' . $document['file_path']; ?>
                  <?php if (is_file($fileAbsolute)): ?>
                    <a class="btn btn--ghost" href="<?= htmlspecialchars((string) $document['file_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" download>Download</a>
                  <?php else: ?>
                    <span class="muted">Niet beschikbaar</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>