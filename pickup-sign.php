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

  <script>
    const canvas = document.getElementById('signatureCanvas');
    const context = canvas.getContext('2d');
    const clearButton = document.getElementById('clearBtn');
    const saveButton = document.getElementById('saveBtn');
    const signatureDataInput = document.getElementById('signatureData');
    const signatureForm = document.getElementById('signatureForm');

    const penColor = '#1f2933';
    const backgroundColor = '#ffffff';
    let drawing = false;
    let isCanvasEmpty = true;
    let lastPoint = { x: 0, y: 0 };

    function getCanvasRect() {
      return canvas.getBoundingClientRect();
    }

    function resizeCanvas() {
      const rect = getCanvasRect();
      if (!rect.width || !rect.height) {
        return;
      }
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const existingDrawing = !isCanvasEmpty ? canvas.toDataURL() : null;

      canvas.width = rect.width * ratio;
      canvas.height = rect.height * ratio;

      context.setTransform(1, 0, 0, 1, 0, 0);
      context.scale(ratio, ratio);
      context.lineCap = 'round';
      context.lineJoin = 'round';
      context.lineWidth = 2.4;
      context.strokeStyle = penColor;

      context.fillStyle = backgroundColor;
      context.fillRect(0, 0, rect.width, rect.height);

      if (existingDrawing) {
        const image = new Image();
        image.onload = () => {
          context.drawImage(image, 0, 0, rect.width, rect.height);
          isCanvasEmpty = false;
        };
        image.src = existingDrawing;
      } else {
        isCanvasEmpty = true;
      }
    }
    function getInputPoint(event) {
      if (event.touches && event.touches.length > 0) {
        return event.touches[0];
      }

      if (event.changedTouches && event.changedTouches.length > 0) {
        return event.changedTouches[0];
      }

      return event;
    }

    function getPointerType(event) {
      if (event.pointerType) {
        return event.pointerType;
      }

      if (event.touches || event.changedTouches) {
        return 'touch';
      }

      return 'mouse';
    }

    function getCanvasCoordinates(event) {
      const rect = getCanvasRect();
      const point = getInputPoint(event);
      return {
        x: point.clientX - rect.left,
        y: point.clientY - rect.top,
      };
    }

    function drawDot(point) {
      context.beginPath();
      context.arc(point.x, point.y, context.lineWidth / 2, 0, Math.PI * 2);
      context.fillStyle = penColor;
      context.fill();
      context.fillStyle = backgroundColor;
      context.beginPath();
      context.moveTo(point.x, point.y);
    }

    function startStroke(event) {
      const pointerType = getPointerType(event);
      if (pointerType === 'mouse' && 'button' in event && event.button !== 0) {
        return;
      }

      event.preventDefault();
      if ('pointerId' in event && canvas.setPointerCapture) {
        canvas.setPointerCapture(event.pointerId);
      }
      drawing = true;
      lastPoint = getCanvasCoordinates(event);
      drawDot(lastPoint);
      isCanvasEmpty = false;
    }

    function moveStroke(event) {
      if (!drawing) {
        return;
      }

      event.preventDefault();
      const point = getCanvasCoordinates(event);
      context.beginPath();
      context.moveTo(lastPoint.x, lastPoint.y);
      context.lineTo(point.x, point.y);
      context.stroke();
      lastPoint = point;
      isCanvasEmpty = false;
    }

    function endStroke(event) {
      if (!drawing) {
        return;
      }

      if (event) {
        if (typeof event.preventDefault === 'function') {
          event.preventDefault();
        }
        if ('pointerId' in event && canvas.releasePointerCapture) {
          canvas.releasePointerCapture(event.pointerId);
        }
      }

      drawing = false;
      context.beginPath();
    }

    function clearCanvas() {
      isCanvasEmpty = true;
      signatureDataInput.value = '';
      drawing = false;
      context.beginPath();
      resizeCanvas();
    }

    clearButton.addEventListener('click', () => {
      clearCanvas();
    });

    saveButton.addEventListener('click', () => {
      if (isCanvasEmpty) {
        alert('Handtekening is leeg. Zet eerst een handtekening.');
        return;
      }

      signatureDataInput.value = canvas.toDataURL('image/png');
      signatureForm.submit();
    });

    const supportsPointerEvents = window.PointerEvent !== undefined;
    const nonPassive = { passive: false };

    if (supportsPointerEvents) {
      canvas.addEventListener('pointerdown', startStroke);
      canvas.addEventListener('pointermove', moveStroke);
      canvas.addEventListener('pointerup', endStroke);
      canvas.addEventListener('pointerleave', endStroke);
      canvas.addEventListener('pointercancel', endStroke);
    } else {
      canvas.addEventListener('mousedown', startStroke);
      canvas.addEventListener('mousemove', moveStroke);
      document.addEventListener('mouseup', endStroke);
      canvas.addEventListener('touchstart', startStroke, nonPassive);
      canvas.addEventListener('touchmove', moveStroke, nonPassive);
      document.addEventListener('touchend', endStroke, nonPassive);
      document.addEventListener('touchcancel', endStroke, nonPassive);
    }

    window.addEventListener('resize', () => {
      window.requestAnimationFrame(resizeCanvas);
    });

    resizeCanvas();
  </script>
</body>
</html>
