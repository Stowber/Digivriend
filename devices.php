<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Csrf;
use App\Support\Repositories\DeviceRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$deviceRepository = new DeviceRepository($pdo);

$errors = [];
$searchBarcode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['general'] = 'Ongeldige sessie, probeer opnieuw.';
    } else {
        $searchBarcode = trim((string) ($_POST['barcode'] ?? ''));
        if ($searchBarcode === '') {
            $errors['barcode'] = 'Voer een barcode in.';
        } else {
            $device = $deviceRepository->findWithCustomerByBarcode($searchBarcode);
            if ($device === null) {
                $errors['barcode'] = 'Geen apparaat gevonden met deze barcode.';
            } else {
                Response::redirect('device.php?barcode=' . urlencode($searchBarcode));
            }
        }
    }
}

$recentDevices = $deviceRepository->recentDevices(15);
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
        <ul>
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="devices.php" aria-current="page">Klanten &amp; apparaten</a></li>
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
  <main class="container">
    <div class="page-header">
      <div>
        <h1>Klanten &amp; apparaten</h1>
        <p class="page-intro">Zoek apparaten met een barcode of registreer een nieuw reparatiedossier.</p>
      </div>
      <a href="device-intake.php" class="btn btn--primary">Nieuw intakeformulier</a>
    </div>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <section class="card">
      <form action="devices.php" method="post" class="scan-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <label>
          Scan of voer barcode in
          <input type="text" name="barcode" value="<?= htmlspecialchars($searchBarcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. DV240101ABC123" autofocus>
        </label>
        <button type="submit" class="btn btn--secondary">Zoeken</button>
      </form>
      <?php if (!empty($errors['barcode'])): ?>
        <div class="form-error form-error--inline"><?= htmlspecialchars($errors['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Laatste geregistreerde apparaten</h2>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th>Barcode</th>
              <th>Apparaat</th>
              <th>Klant</th>
              <th>Aangemaakt</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($recentDevices === []): ?>
              <tr>
                <td colspan="5">Er zijn nog geen apparaten geregistreerd.</td>
              </tr>
            <?php else: ?>
              <?php foreach ($recentDevices as $device): ?>
                <tr>
                  <td><code><?= htmlspecialchars((string) ($device['barcode'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></td>
                  <td><?= htmlspecialchars(trim(($device['brand'] ?? '') . ' ' . ($device['model'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) $device['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($device['created_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td class="table-actions"><a class="btn btn--link" href="device.php?id=<?= (int) $device['id'] ?>">Details</a></td>
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