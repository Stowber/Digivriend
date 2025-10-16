<?php

declare(strict_types=1);

use App\Security\Csrf;

$inboxDir = __DIR__ . '/storage/data-recovery/inbox';
if (!is_dir($inboxDir)) {
    mkdir($inboxDir, 0775, true);
}

$pendingImports = array_map('basename', glob($inboxDir . '/*.json') ?: []);
$importedCount = filter_input(INPUT_GET, 'imported', FILTER_VALIDATE_INT) ?: 0;
$importErrorsRaw = filter_input(INPUT_GET, 'errors', FILTER_UNSAFE_RAW);
$importErrors = [];
if (is_string($importErrorsRaw) && $importErrorsRaw !== '') {
    $decoded = json_decode(urldecode($importErrorsRaw), true);
    if (is_array($decoded)) {
        $importErrors = $decoded;
    }
}

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Data Recovery - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/data-recovery.css">
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
        <?php render_main_nav('data_recovery'); ?>
      </nav>
    </div>
  </header>

  <main>
    <div class="container">
      <div class="page-header">
        <h1>Data Recovery</h1>
        <p>Start een nieuwe datarecovery-aanvraag via ons partnerformulier. Vul alle velden in, zodat onze specialisten direct met je case aan de slag kunnen.</p>
    </div>

    <?php if ($importedCount > 0): ?>
      <div class="alert alert--success">Succesvol <?= (int) $importedCount ?> partneraanvraag/aanvragen geïmporteerd.</div>
    <?php endif; ?>
    <?php if (!empty($importErrors)): ?>
      <div class="alert alert--error">
        <strong>Waarschuwing:</strong>
        <ul>
          <?php foreach ($importErrors as $errorMessage): ?>
            <li><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?php endif; ?>

    <section class="partner-sync">
      <h2>Partnerinbox</h2>
      <p class="muted">Bestanden in <code>storage/data-recovery/inbox</code> worden automatisch verwerkt tot cases.</p>
      <?php if (empty($pendingImports)): ?>
        <p class="muted">Geen wachtende partnerbestanden.</p>
      <?php else: ?>
        <ul>
          <?php foreach ($pendingImports as $fileName): ?>
            <li><?= htmlspecialchars($fileName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endforeach; ?>
        </ul>
        <form action="data-recovery-sync.php" method="POST" class="partner-sync__form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <button type="submit" class="btn">Importeer partneraanvragen</button>
        </form>
      <?php endif; ?>
    </section>

  <div class="embed-shell">
        <div id="zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw"></div>
      </div>

    <div class="contact-card">
        <p>Vragen over data recovery? Mail naar <a href="mailto:contact@digivriend.nl">contact@digivriend.nl</a> of bel <strong>+31 (0)33 785 4284</strong>.</p>
      </div>
      <section class="manual-card">
        <h2>Handmatig case registreren</h2>
        <p>Leg een data-recoveryaanvraag vast in de interne database om voortgang en communicatie te volgen.</p>
        <form action="generate-data-recovery.php" method="POST" class="manual-form">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div class="manual-grid">
            <label>
              <span>Volledige naam</span>
              <input type="text" name="fullname" required>
            </label>
            <label>
              <span>Adres</span>
              <input type="text" name="address" required>
            </label>
            <label>
              <span>Postcode</span>
              <input type="text" name="postcode" required>
            </label>
            <label>
              <span>Telefoonnummer</span>
              <input type="text" name="phone" required>
            </label>
            <label>
              <span>E-mailadres</span>
              <input type="email" name="email" required>
            </label>
            <label>
              <span>Referentie/Zoho-nummer</span>
              <input type="text" name="caseReference" placeholder="Bijv. Zoho ID">
            </label>
            <label>
              <span>Apparaat merk</span>
              <input type="text" name="deviceBrand">
            </label>
            <label>
              <span>Apparaat model</span>
              <input type="text" name="deviceModel">
            </label>
            <label>
              <span>Serienummer</span>
              <input type="text" name="deviceSerial">
            </label>
            <label>
              <span>Datum akkoord</span>
              <input type="date" name="signatureDate" required>
            </label>
            <label class="manual-grid__wide">
              <span>Opmerkingen</span>
              <textarea name="notes" rows="3" placeholder="Belangrijke details of bijzonderheden"></textarea>
            </label>
          </div>
          <button type="submit" class="btn">Registreren en PDF maken</button>
        </form>
      </section>
    </div>
  </main>

    <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>

   <script type="text/javascript">
  (function() {
      try {
          var f = document.createElement("iframe");
          f.src = "https://forms.zohopublic.eu/ahogye/form/Diagnosis/formperma/NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw?zf_rszfm=1&con1=Netherlands&con2=Netherlands&con3=Netherlands&ML=NL%2FDutch&pa=true&pc=PARTNERCODE&pn=BEDRIJFSNAAM";
          f.style.border = "none";
          f.style.height = "2503px";
          f.style.width = "100%";
          f.style.transition = "all 0.5s ease";
          var d = document.getElementById("zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw");
          d.appendChild(f);
          window.addEventListener('message', function(event) {
              var zf_ifrm_data = event.data.split("|");
              var zf_perma = zf_ifrm_data[0];
              var zf_ifrm_ht_nw = (parseInt(zf_ifrm_data[1], 10) + 15) + "px";
              var iframe = document.getElementById("zf_div_NjvRlJCeWk-6kWpDnNOiviDvjs0WoTznjCUgcSCEIgw").getElementsByTagName("iframe")[0];
              if ((iframe.src).indexOf('formperma') > 0 && (iframe.src).indexOf(zf_perma) > 0) {
                  var prevIframeHeight = iframe.style.height;
                  if (prevIframeHeight != zf_ifrm_ht_nw) {
                      iframe.style.height = zf_ifrm_ht_nw;
                  }
              }
          }, false);
      } catch (e) {}
  })();
  </script>
</body>
</html>
