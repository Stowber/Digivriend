<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Klant registratie - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/intake.css">
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
        <?php render_main_nav('intake'); ?>
      </nav>
    </div>
  </header>

  <main class="container intake-page">
    <section class="page-hero">
      <div class="page-hero__content">
        <h1>Klant registratie</h1>
        <p class="page-hero__intro">
          Start elke serviceaanvraag met een gestroomlijnd intakeproces. Registreer de klantgegevens,
          plan het bezoek voor het apparaat en bevestig de intake direct per e-mail.
        </p>
        <button class="btn btn--primary" type="button" data-intake-open>
          Nieuwe registratie starten
        </button>
      </div>
      <ul class="page-hero__steps" aria-label="Procesoverzicht">
        <li>
          <span class="page-hero__step-number">1</span>
          <div>
            <strong>Gegevens vastleggen</strong>
            <p>Naam, adres en contactinformatie van de klant.</p>
          </div>
        </li>
        <li>
          <span class="page-hero__step-number">2</span>
          <div>
            <strong>Afspraak plannen</strong>
            <p>Maak een afspraak voor het inleveren van het apparaat en noteer het probleem.</p>
          </div>
        </li>
        <li>
          <span class="page-hero__step-number">3</span>
          <div>
            <strong>Bevestigen & verzenden</strong>
            <p>De klant ontvangt een PDF met barcode en intake afspraken.</p>
          </div>
        </li>
      </ul>
    </section>

    <section class="info-card">
      <h2>Wat gebeurt er na de registratie?</h2>
      <ol class="info-card__list">
        <li>De intakebevestiging wordt automatisch naar de klant gemaild met barcode.</li>
        <li>De afspraak en case verschijnen meteen in <strong>Klanten &amp; apparaten</strong>.</li>
        <li>Bij binnenkomst scant de medewerker de barcode en laat de klant de toestemmingsverklaring ondertekenen.</li>
      </ol>
    </section>
  </main>

  <div class="intake-modal" data-intake-modal hidden>
    <div class="intake-modal__backdrop" data-intake-close aria-hidden="true"></div>
    <div class="intake-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="intakeModalTitle">
      <header class="intake-modal__header">
        <p class="intake-modal__eyebrow">Nieuw intakeproces</p>
        <h2 id="intakeModalTitle">Klantgegevens registreren</h2>
        <button type="button" class="intake-modal__close" data-intake-close aria-label="Sluiten">&times;</button>
      </header>
      <div class="intake-modal__body">
        <form class="intake-form" id="intakeForm" novalidate>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <section class="intake-form__panel" data-step="customer" aria-label="Klantgegevens">
            <h3>Contactgegevens klant</h3>
            <div class="form-grid">
              <label class="form-field">
                <span>Naam *</span>
                <input type="text" name="full_name" autocomplete="name" required maxlength="191">
              </label>
              <label class="form-field">
                <span>E-mailadres *</span>
                <input type="email" name="email" autocomplete="email" required maxlength="191">
              </label>
              <label class="form-field">
                <span>Telefoon *</span>
                <input type="tel" name="phone" autocomplete="tel" required maxlength="32">
              </label>
              <label class="form-field form-field--wide">
                <span>Adres *</span>
                <input type="text" name="address" autocomplete="street-address" required maxlength="255">
              </label>
              <label class="form-field">
                <span>Postcode *</span>
                <input type="text" name="postal_code" autocomplete="postal-code" required maxlength="16">
              </label>
              <label class="form-field">
                <span>Plaats *</span>
                <input type="text" name="city" autocomplete="address-level2" required maxlength="120">
              </label>
            </div>
            <footer class="intake-form__actions">
              <button type="button" class="btn btn--primary" data-next-step>Volgende stap</button>
            </footer>
          </section>

          <section class="intake-form__panel" data-step="visit" hidden aria-label="Afspraakgegevens">
            <h3>Afspraak en apparaat</h3>
            <div class="form-grid">
              <label class="form-field">
                <span>Datum &amp; tijd afspraak *</span>
                <input type="datetime-local" name="appointment_at" required>
              </label>
              <label class="form-field">
                <span>Type apparaat</span>
                <select name="device_type">
                  <option value="">— Kies —</option>
                  <option value="Laptop">Laptop</option>
                  <option value="PC">PC</option>
                  <option value="Desktop">Desktop</option>
                  <option value="Telefon">Telefon</option>
                  <option value="Tablet">Tablet</option>
                  <option value="Konsola">Konsola</option>
                </select>
              </label>
              <label class="form-field">
                <span>Merk</span>
                <input type="text" name="device_brand" maxlength="120">
              </label>
              <label class="form-field">
                <span>Model</span>
                <input type="text" name="device_model" maxlength="191">
              </label>
              <label class="form-field">
                <span>Serienummer</span>
                <input type="text" name="device_serial" maxlength="120">
              </label>
              <label class="form-field form-field--wide">
                <span>Korte probleemomschrijving</span>
                <textarea name="problem_description" rows="4" maxlength="500" placeholder="Bijv. start niet meer op…"></textarea>
              </label>
            </div>
            <footer class="intake-form__actions">
              <button type="button" class="btn btn--ghost" data-prev-step>Terug</button>
              <button type="submit" class="btn btn--primary">Registratie afronden</button>
            </footer>
          </section>
        </form>

        <section class="intake-result" data-result hidden aria-live="polite">
          <div class="intake-result__icon" aria-hidden="true">✅</div>
          <h3>Intake bevestigd</h3>
          <p>We hebben de intake vastgelegd. De klant ontvangt zo dadelijk een e-mail met de barcode en afspraak.</p>
          <dl class="intake-result__summary">
            <div>
              <dt>Referentiecode</dt>
              <dd data-result-reference>-</dd>
            </div>
            <div>
              <dt>Afspraak</dt>
              <dd data-result-appointment>-</dd>
            </div>
          </dl>
          <div class="intake-result__actions">
            <a class="btn btn--primary" data-result-case href="#">Bekijk case</a>
            <a class="btn btn--secondary" data-result-pdf href="#" target="_blank" rel="noopener">Download bevestiging</a>
          </div>
        </section>

        <div class="intake-feedback" data-feedback hidden></div>
      </div>
    </div>
  </div>

  <script src="js/intake.js" defer></script>
</body>
</html>