<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$caseRepository = new CaseRepository($pdo);

$errors = [];
$searchReference = '';
$highlightReference = filter_input(INPUT_GET, 'highlight', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ongeldige sessie, probeer opnieuw.';
    } else {
        $searchReference = strtoupper(trim((string) ($_POST['reference_code'] ?? '')));
        if ($searchReference === '') {
            $errors['reference_code'] = 'Voer een referentiecode in.';
        } else {
            $case = $caseRepository->findByReferenceCode($searchReference);
            if ($case === null) {
                $errors['reference_code'] = 'Geen case gevonden met deze referentie.';
            } else {
                Response::redirect('case.php?id=' . (int) $case['id']);
            }
        }
    }
}

$recentCases = $caseRepository->recentCaseOverview(20);
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Klanten &amp; apparaten - Digivriend</title>
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
        <?php render_main_nav('devices'); ?>
      </nav>
    </div>
  </header>
  <main class="container">
    <div class="page-header">
      <div>
        <h1>Klanten &amp; apparaten</h1>
        <p class="page-intro">Volg alle lopende dossiers, scan intakebarcodes en open cases direct vanuit deze hub.</p>
      </div>
      <a href="intake.php" class="btn btn--primary">Klant registratie</a>
    </div>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <section class="card">
      <form action="devices.php" method="post" class="scan-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <label>
          Scan of voer referentiecode in
          <input type="text" name="reference_code" value="<?= htmlspecialchars($searchReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. IN-240601-ABCD" autofocus>
        </label>
        <button type="submit" class="btn btn--secondary">Case openen</button>
      </form>
      <?php if (!empty($errors['reference_code'])): ?>
        <div class="form-error form-error--inline"><?= htmlspecialchars($errors['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Laatste intake- en servicedossiers</h2>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Referentie</th>
              <th>Klant</th>
              <th>Type</th>
              <th>Afspraak</th>
              <th>Status</th>
              <th>Acties</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentCases === []): ?>
              <tr>
                <td colspan="6">Er zijn nog geen dossiers geregistreerd.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentCases as $case): ?>
                <?php
                  $details = [];
                  if (!empty($case['details'])) {
                      $decoded = json_decode((string) $case['details'], true);
                      if (is_array($decoded)) {
                          $details = $decoded;
                      }
                  }
                  $appointmentAt = $details['appointment_at'] ?? null;
                  $appointmentLabel = '-';
                  if (is_string($appointmentAt) && $appointmentAt !== '') {
                      $appointmentLabel = date('d-m-Y H:i', strtotime($appointmentAt));
                  }
                  $highlightClass = ($highlightReference !== '' && strtoupper((string) ($case['reference_code'] ?? '')) === strtoupper($highlightReference)) ? 'table-row--highlight' : '';
                  $customerName = (string) ($case['full_name'] ?? 'Onbekende klant');
                  $caseStatus = (string) ($case['status'] ?? 'open');
                ?>
                <tr class="<?= htmlspecialchars($highlightClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <td class="table__reference">
                    <code><?= htmlspecialchars((string) ($case['reference_code'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code>
                  </td>
                  <td>
                    <div class="table__primary"><?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="table__secondary"><?= htmlspecialchars((string) ($case['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </td>
                  <td><?= htmlspecialchars((string) ($case['type'] ?? '-'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars($appointmentLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><span class="status-pill <?= htmlspecialchars('status-pill--' . strtolower($caseStatus), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars(ucfirst($caseStatus), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
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