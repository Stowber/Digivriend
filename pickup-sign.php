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

  <!-- Form om data te versturen naar pickup-store.php -->
  <form id="signatureForm" action="pickup-store.php" method="POST" style="display: none;">
    <input type="hidden" name="id" value="<?php echo htmlspecialchars($_GET['id'] ?? ''); ?>">
    <input type="hidden" name="signatureData" id="signatureData">
  </form>

  <!-- Toegevoegde link om na het zetten van de handtekening 
       (of wanneer de gebruiker wil) terug te gaan naar index.html -->
  <div style="margin-top: 20px;">
    <a href="index.html" class="btn">Terug naar startpagina</a>
  </div>

  <!-- Signature Pad library (CDN) -->
  <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
  <script>
    const canvas = document.getElementById('signatureCanvas');
    const signaturePad = new SignaturePad(canvas);

    // Canvas responsive maken
    function resizeCanvas() {
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      canvas.width = canvas.offsetWidth * ratio;
      canvas.height = canvas.offsetHeight * ratio;
      canvas.getContext('2d').scale(ratio, ratio);
      signaturePad.clear();
    }
    window.addEventListener('resize', resizeCanvas);
    resizeCanvas();

    // Wissen
    document.getElementById('clearBtn').addEventListener('click', () => {
      signaturePad.clear();
    });

    // Opslaan
    document.getElementById('saveBtn').addEventListener('click', () => {
      if (!signaturePad.isEmpty()) {
        // Base64 data
        const dataURL = signaturePad.toDataURL();
        // Plaats in hidden input
        document.getElementById('signatureData').value = dataURL;
        // Verstuur formulier
        document.getElementById('signatureForm').submit();
      } else {
        alert("Handtekening is leeg. Zet eerst een handtekening.");
      }
    });
  </script>
</body>
</html>
