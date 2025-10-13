<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Digivriend - Documenten</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/index.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php" aria-current="page">Start</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container">
    <div class="page-intro">
      <h1>Documenten genereren</h1>
      <p>Welkom bij Digivriend. Kies het document dat je wilt aanmaken en start meteen met het invullen van de benodigde gegevens.</p>
    </div>

    <div class="document-grid">
      <div class="document-card">
        <h3>Ophaalbevestiging</h3>
        <p>Maak een ophaalbevestiging voor een gerepareerd apparaat.</p>
        <a href="ophaalbevestiging.php" class="btn">Genereer</a>
      </div>

      <div class="document-card">
        <h3>Reparatie &amp; Onderzoek</h3>
        <p>Laat je klanten een toestemmingsformulier ondertekenen voor Reparatie en Onderzoek!</p>
        <a href="reparatie-onderzoek.php" class="btn">Genereer</a>
      </div>

      <div class="document-card">
        <h3>Data Recovery</h3>
        <p>Algemene voorwaarden en Data Recovery formulier nodig? Klik hier!</p>
        <a href="data-recovery.php" class="btn">Genereer</a>
      </div>

      <div class="document-card">
        <h3>Klant Melding</h3>
        <p>Maak een klantmelding met alle relevante klant- en apparaatgegevens en een omschrijving van de melding.</p>
        <a href="klant-melding.php" class="btn">Genereer</a>
      </div>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>
