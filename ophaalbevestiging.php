<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Ophaalbevestiging - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/ophaalbevestiging.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php">Start</a></li>
          <li><a href="ophaalbevestiging.php" aria-current="page">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <section class="form-shell">
      <div class="page-header">
        <h1>Ophaalbevestiging</h1>
        <p>Vul de gegevens in zodat je klant met een unieke code zijn of haar apparaat kan ophalen.</p>
      </div>

      <form action="generate-ophaalbevestiging.php" method="POST" class="document-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="form-group">
          <label for="klantnaam">Naam klant</label>
          <input type="text" id="klantnaam" name="klantnaam" maxlength="120" required>
        </div>

      <div class="form-group">
          <label for="merkmodel">Merk &amp; Model</label>
          <input type="text" id="merkmodel" name="merkmodel" maxlength="120" required>
        </div>

      <div class="form-group">
          <label for="ophaalcode">Unieke ophaalcode</label>
          <input type="text" id="ophaalcode" name="ophaalcode" maxlength="32" required>
        </div>

       <div class="form-group">
          <label for="datumgereed">Datum gereed</label>
          <input type="date" id="datumgereed" name="datumgereed" required>
        </div>

    <div class="document-actions">
          <button type="submit" class="btn">Genereer PDF</button>
          <a href="ophaalbevestigingen-list.php" class="btn btn--ghost">Alle bevestigingen</a>
        </div>
      </form>
    </section>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
