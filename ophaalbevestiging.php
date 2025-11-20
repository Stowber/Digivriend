<?php

declare(strict_types=1);

use App\Security\Csrf;
use App\Support\Codes\PickupCodeGenerator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
$csrfToken = Csrf::token();

$generatedPickupCode = '';
$pickupCodeReadOnly = false;

try {
    $pickupCodeGenerator = new PickupCodeGenerator($pdo);
    $generatedPickupCode = $pickupCodeGenerator->generate();
    $pickupCodeReadOnly = true;
} catch (\RuntimeException $exception) {
    $pickupCodeReadOnly = false;
}
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
<body<?= platform_body_attributes(); ?>>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('pickup'); ?>
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
          <input
            type="text"
            id="ophaalcode"
            name="ophaalcode"
            maxlength="32"
            inputmode="numeric"
            autocomplete="off"
            value="<?= htmlspecialchars($generatedPickupCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            <?= $pickupCodeReadOnly ? 'readonly' : '' ?>
            required
          >
          <small>
            <?= $pickupCodeReadOnly
                ? 'De code wordt automatisch gegenereerd bij het openen van dit formulier.'
                : 'Kon geen automatische code genereren. Vul handmatig een unieke code in.';
            ?>
          </small>
        </div>

       <div class="form-group">
          <label for="datumgereed">Datum gereed</label>
          <input type="date" id="datumgereed" name="datumgereed" required>
        </div>

        <div class="form-split">
          <div class="form-group">
            <label for="pickup_scheduled_at">Afspraakdatum (optioneel)</label>
            <input type="datetime-local" id="pickup_scheduled_at" name="pickup_scheduled_at">
          </div>
          <div class="form-group">
            <label for="pickup_window">Afhaalvenster</label>
            <input type="text" id="pickup_window" name="pickup_window" maxlength="120" placeholder="Bijv. tussen 10:00 en 12:00">
          </div>
        </div>

        <div class="form-group">
          <label for="opmerkingen">Interne notitie (optioneel)</label>
          <textarea id="opmerkingen" name="opmerkingen" rows="3" placeholder="Bijvoorbeeld bijzonderheden bij afhalen"></textarea>
        </div>

        <fieldset class="form-group">
          <legend>Automatische communicatie</legend>
          <label class="form-checkbox">
            <input type="checkbox" name="notify_email" value="1" checked>
            <span>Verstuur direct een e-mail zodra de bevestiging is aangemaakt</span>
          </label>
          <label class="form-checkbox">
            <input type="checkbox" name="notify_sms" value="1">
            <span>Verstuur ook een sms naar het opgegeven telefoonnummer</span>
          </label>
          <label class="form-group form-group--stacked" for="sms_template">
            <span>SMS-tekst (optioneel)</span>
            <textarea id="sms_template" name="sms_template" rows="2" placeholder="Hoi! Uw apparaat staat klaar bij Digivriend. Code: 123456."></textarea>
          </label>
        </fieldset>

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
