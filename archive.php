<?php

declare(strict_types=1);

use App\Support\Repositories\CaseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$caseRepository = new CaseRepository($pdo);

$typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$limit = 50;

$typeStatement = $pdo->query("SELECT DISTINCT type FROM cases WHERE closed_at IS NOT NULL ORDER BY type");
$availableTypes = ['all'];
if ($typeStatement !== false) {
    $availableTypes = array_merge($availableTypes, array_map('strval', $typeStatement->fetchAll(PDO::FETCH_COLUMN) ?: []));
}

$selectedType = in_array($typeFilter, $availableTypes, true) ? $typeFilter : 'all';
$typeFilterValue = $selectedType === 'all' ? null : $selectedType;
$archivedCases = $caseRepository->archivedCases($limit, $typeFilterValue);

?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Archief cases - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/devices.css">
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
        <?php render_main_nav('archive'); ?>
      </nav>
    </div>
  </header>
  <main class="container">
    <div class="page-header">
      <div>
        <h1>Archief dossiers</h1>
        <p class="page-intro">Overzicht van afgeronde, opgehaalde en geannuleerde dossiers.</p>
      </div>
    </div>
    <section class="card">
      <form method="get" class="search-bar" aria-label="Filter archief op type">
        <label>
          Toon dossiers van type
          <select name="type">
            <?php foreach ($availableTypes as $typeOption): ?>
              <option value="<?= htmlspecialchars($typeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $selectedType === $typeOption ? ' selected' : '' ?>>
                <?= htmlspecialchars($typeOption === 'all' ? 'Alle dossiers' : ucfirst(str_replace('_', ' ', $typeOption)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn btn--secondary">Filter toepassen</button>
      </form>
    </section>
    <section class="card">
      <h2>Laatste gesloten cases</h2>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Referentie</th>
              <th>Klant</th>
              <th>Type</th>
              <th>Afgerond op</th>
              <th>Status</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($archivedCases === []): ?>
              <tr>
                <td colspan="6">Er zijn nog geen afgesloten dossiers.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($archivedCases as $case): ?>
                <?php
                  $closedAt = (string) ($case['closed_at'] ?? '');
                  $closedLabel = $closedAt !== '' ? date('d-m-Y H:i', strtotime($closedAt)) : '—';
                  $status = (string) ($case['status'] ?? 'gesloten');
                  $customerName = (string) ($case['full_name'] ?? 'Onbekende klant');
                ?>
                <tr>
                  <td class="table__reference"><code><?= htmlspecialchars((string) ($case['reference_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
                  <td>
                    <div class="table__primary"><?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="table__secondary"><?= htmlspecialchars((string) ($case['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars((string) ($case['type'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($closedLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="status-pill <?= htmlspecialchars('status-pill--' . strtolower((string) $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                  <td class="table-actions"><a class="btn btn--link" href="case.php?id=<?= (int) $case['id'] ?>">Open case</a></td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</body>
</html>