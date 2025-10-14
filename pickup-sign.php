<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$csrfToken = Csrf::token();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Handtekening voor ophalen</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/pickup.css">
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

  <main>
    <div class="container">
      <div class="page-header">
        <h1>Apparaat ophalen</h1>
        <p>Zet hieronder je handtekening om te bevestigen dat het apparaat is opgehaald.</p>
      </div>

      <section class="form-shell signature-stage">
        <div class="signature-board">
          <canvas id="signatureCanvas"></canvas>
        </div>

   <div class="signature-actions button-row">
          <button id="clearBtn" class="btn btn--ghost" type="button">Wissen</button>
          <button id="saveBtn" class="btn" type="button">Opslaan</button>
        </div>

  <form id="signatureForm" action="pickup-store.php" method="POST" hidden>
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="id" value="<?= htmlspecialchars((string) $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="signatureData" id="signatureData">
        </form>

  <div class="back-link">
          <a href="index.php" class="btn btn--ghost">Terug naar startpagina</a>
        </div>
      </section>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>

  <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"
          integrity="sha384-Pu0vQX1a+8XMGO6zkRgZNpmjDoE7YQDdyCjTiMQuuLHfoalGoVYLRNvKcJsteUms"
          crossorigin="anonymous"></script>
  <script>
    const canvas = document.getElementById('signatureCanvas');
    const signaturePad = new SignaturePad(canvas);

    function resizeCanvas() {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      canvas.width = canvas.offsetWidth * ratio;
      canvas.height = canvas.offsetHeight * ratio;
      canvas.getContext('2d').scale(ratio, ratio);
      signaturePad.clear();
    }
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    document.getElementById('clearBtn').addEventListener('click', () => {
      signaturePad.clear();
    });

    document.getElementById('saveBtn').addEventListener('click', () => {
      if (!signaturePad.isEmpty()) {
        const dataURL = signaturePad.toDataURL('image/png');
        document.getElementById('signatureData').value = dataURL;
        document.getElementById('signatureForm').submit();
      } else {
        alert('Handtekening is leeg. Zet eerst een handtekening.');
      }
    });
  </script>
</body>
</html>
