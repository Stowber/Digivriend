<?php

declare(strict_types=1);

use App\Security\Csrf;

require __DIR__ . '/bootstrap.php';

$csrfToken = Csrf::token();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?? '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Handtekening voor ophalen</title>
  <style>
    body { font-family: Arial, sans-serif; margin: 20px; }
    .signature-container {
      border: 2px solid #ccc;
      border-radius: 4px;
      width: 400px;
      height: 200px;
      margin-bottom: 10px;
      position: relative;
    }
    #signatureCanvas {
      width: 100%;
      height: 100%;
    }
    .btn {
      display: inline-block;
      background: #F05A28;
      color: #fff;
      padding: 8px 15px;
      text-decoration: none;
      border-radius: 4px;
      margin-right: 10px;
    }
    .btn:hover {
      background: #e14b20;
    }
  </style>
</head>
<body>
  <h1>Apparaat ophalen - Handtekening</h1>
  <p>Zet hieronder uw handtekening voor het ophalen van het apparaat.</p>

  <div class="signature-container">
    <canvas id="signatureCanvas"></canvas>
  </div>

  <button id="clearBtn" class="btn" type="button">Wissen</button>
  <button id="saveBtn" class="btn" type="button">Opslaan</button>

  <form id="signatureForm" action="pickup-store.php" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="id" value="<?= htmlspecialchars((string) $id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
    <input type="hidden" name="signatureData" id="signatureData">
  </form>

  <div style="margin-top: 20px;">
    <a href="index.php" class="btn">Terug naar startpagina</a>
  </div>

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
