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
  <title>Reparatie &amp; Onderzoek - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/reparatie-onderzoek.css">
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
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php" aria-current="page">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php">Documenten</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main>
    <div class="container">
      <div class="page-header">
        <h1>Reparatie &amp; Onderzoek</h1>
        <p>Vul het toestemmingsformulier in zodat we het apparaat kunnen onderzoeken en, indien gewenst, direct kunnen repareren volgens jouw instructies.</p>
      </div>

        <section class="form-shell">
        <div class="form-lead">
          <h2>Toestemmingsformulier</h2>
          <p>Controleer de gegevens zorgvuldig en geef aan welke reparatie-optie van toepassing is. Daarna ontvang je direct een PDF met alle informatie.</p>
        </div>

              <form action="generate-reparatie-onderzoek.php" method="POST" class="repair-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="form-section">
            <h3>Klantinformatie</h3>
            <div class="field-grid field-grid--two">
              <div>
                <label for="fullname">Voor- en achternaam</label>
                <input type="text" id="fullname" name="fullname" required>
              </div>
              <div>
                <label for="address">Adres + postcode</label>
                <input type="text" id="address" name="address" required>
              </div>
              <div>
                <label for="phone">Telefoonnummer</label>
                <input type="text" id="phone" name="phone" required>
              </div>
              <div>
                <label for="email">E-mailadres</label>
                <input type="email" id="email" name="email" required>
              </div>
            </div>
          </div>

          <div class="form-section">
            <h3>Apparaatgegevens</h3>
            <div class="field-grid field-grid--two">
              <div>
                <label for="deviceBrand">Merk</label>
                <input type="text" id="deviceBrand" name="deviceBrand" required>
              </div>
              <div>
                <label for="deviceModel">Model</label>
                <input type="text" id="deviceModel" name="deviceModel" required>
              </div>
              <div>
                <label for="deviceSerial">Serienummer (optioneel)</label>
                <input type="text" id="deviceSerial" name="deviceSerial">
              </div>
              <div>
                <label for="deviceNotes">Probleembeschrijving</label>
                <input type="text" id="deviceNotes" name="deviceNotes" placeholder="Korte omschrijving van het defect">
              </div>
            </div>
          </div>

              <div class="form-section">
            <h3>Onderzoekstoestemming</h3>
            <p class="helper-text">
              Ik geef Digivriend toestemming om onderzoek uit te voeren naar de aard en omvang van de schade.
              <strong>Onderzoekskosten:</strong> €49,95. Deze kosten worden altijd in rekening gebracht, ongeacht het vervolg van de reparatie.
            </p>
          </div>

              <div class="form-section">
            <h3>Reparatietoestemming</h3>
            <p class="helper-text">Kies één van de onderstaande opties zodat we weten hoe we met de reparatie mogen starten.</p>
            <div class="radio-group">
              <label>
                <input type="radio" name="repairConsentOption" id="repairConsent100" value="100" checked>
                <span>
                  <strong>Tot €100 zonder kennisgeving</strong>
                  <span class="helper-text">Ik geef toestemming om noodzakelijke reparaties uit te voeren tot een bedrag van €100 zonder eerst contact op te nemen.</span>
                </span>
              </label>
              <label>
                <input type="radio" name="repairConsentOption" id="repairConsentNotify" value="notify">
                <span>
                  <strong>Altijd vooraf informeren</strong>
                  <span class="helper-text">Ik wil voor iedere vervolgstap persoonlijk benaderd worden, ongeacht de kosten.</span>
                </span>
              </label>
              <label>
                <input type="radio" name="repairConsentOption" id="repairConsentCustom" value="custom">
                <span>
                  <strong>Eigen budget zonder kennisgeving</strong>
                  <span class="helper-text">Ik geef toestemming om reparaties uit te voeren tot een bedrag van
                    € <input type="text" name="customAmount" placeholder="bijv. 250"> zonder verdere kennisgeving.</span>
                </span>
              </label>
            </div>
          </div>

               <div class="form-section">
            <h3>Verklaring van akkoord</h3>
            <p class="helper-text">
              Door het ondertekenen van dit formulier ga ik akkoord met de algemene voorwaarden van Digivriend omtrent onderzoek en reparatie. Deze voorwaarden zijn ter plaatse in te zien en worden op verzoek digitaal of fysiek beschikbaar gesteld.
            </p>
            <div class="field-grid field-grid--two">
              <div>
                <label for="signatureName">Naam (ondertekenaar)</label>
                <input type="text" id="signatureName" name="signatureName" required>
              </div>
               <div>
                <label for="signaturePlace">Plaats</label>
                <input type="text" id="signaturePlace" name="signaturePlace" required>
              </div>
              <div>
                <label for="signatureDate">Datum</label>
                <input type="date" id="signatureDate" name="signatureDate" required>
              </div>
              <div>
                <label for="signature">Handtekening (optioneel als tekst)</label>
                <input type="text" id="signature" name="signature" placeholder="Bijv. gescande handtekening of tekst">
              </div>
            </div>
          </div>
          <div class="form-actions">
            <button type="submit" class="btn">Genereer PDF</button>
          </div>
        </form>
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
