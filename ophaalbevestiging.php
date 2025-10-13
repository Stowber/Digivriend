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
  <link rel="stylesheet" href="css/ophaalbevestiging.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php"><h1 class="logo">Digivriend</h1></a>
    </div>
  </header>

  <main class="container">
    <h2>Ophaalbevestiging</h2>
    <p>Vul hieronder de gegevens in om een ophaalbevestiging te genereren.</p>

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
        <label for="ophaalcode">Unieke Ophaalcode</label>
        <input type="text" id="ophaalcode" name="ophaalcode" maxlength="32" required>
      </div>

      <div class="form-group">
        <label for="datumgereed">Datum gereed</label>
        <input type="date" id="datumgereed" name="datumgereed" required>
      </div>

      <button type="submit" class="btn">Genereer PDF</button>
    </form>

    <div style="margin-top: 20px;">
        <a href="ophaalbevestigingen-list.php" class="btn">Alle Bevestigingen</a>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
