<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
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
          <label for="klantemail">E-mailadres</label>
          <input type="email" id="klantemail" name="klantemail" maxlength="120" placeholder="optioneel">
        </div>

        <div class="form-group">
          <label for="klanttelefoon">Telefoonnummer</label>
          <input type="text" id="klanttelefoon" name="klanttelefoon" maxlength="32" placeholder="optioneel">
        </div>

        <div class="form-split">
          <div class="form-group">
            <label for="apparaatmerk">Merk</label>
            <input type="text" id="apparaatmerk" name="apparaatmerk" maxlength="120" required>
          </div>
          <div class="form-group">
            <label for="apparaatmodel">Model</label>
            <input type="text" id="apparaatmodel" name="apparaatmodel" maxlength="120" required>
          </div>
        </div>

      <div class="form-group">
          <label for="ophaalcode">Unieke ophaalcode</label>
          <input type="text" id="ophaalcode" name="ophaalcode" maxlength="32" required>
        </div>

       <div class="form-group">
          <label for="datumgereed">Datum gereed</label>
          <input type="date" id="datumgereed" name="datumgereed" required>
        </div>

        <div class="form-group">
          <label for="opmerkingen">Interne notitie (optioneel)</label>
          <textarea id="opmerkingen" name="opmerkingen" rows="3" placeholder="Bijvoorbeeld bijzonderheden bij afhalen"></textarea>
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
