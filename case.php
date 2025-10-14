<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\NoteRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

$case = $caseRepository->findById((int) $caseId);
if ($case === null) {
    Response::error('Case niet gevonden.', 404);
}

$statement = $pdo->prepare(
    'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,
            dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial
     FROM cases c
     INNER JOIN customers cust ON cust.id = c.customer_id
     LEFT JOIN devices dev ON dev.id = c.device_id
     WHERE c.id = :id'
);
$statement->execute(['id' => $caseId]);
$caseRecord = $statement->fetch();
if (!$caseRecord) {
    Response::error('Casegegevens konden niet worden geladen.', 500);
}

$caseDetails = [];
if (!empty($caseRecord['details'])) {
    $decoded = json_decode((string) $caseRecord['details'], true);
    if (is_array($decoded)) {
        $caseDetails = $decoded;
    }
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        $body = InputValidator::requireString($_POST, 'body', 2000);
        $noteRepository->add((int) $caseId, (int) $caseRecord['customer_id'], (string) ($_SESSION['username'] ?? 'Systeem'), $body);
        Response::redirect('case.php?id=' . (int) $caseId);
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

$notes = $noteRepository->forCase((int) $caseId);
$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Case #<?= (int) $caseId ?> - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/case.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container case-view">
    <section class="case-overview">
      <div>
        <h1>Case #<?= (int) $caseId ?> · <?= htmlspecialchars((string) $caseRecord['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="muted">Status: <span class="status-pill <?= ($caseRecord['status'] === 'opgehaald' || $caseRecord['status'] === 'gesloten') ? 'status-pill--picked' : 'status-pill--ready' ?>"><?= htmlspecialchars(ucfirst((string) $caseRecord['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
      </div>
      <div class="case-meta">
        <p>Laatst bijgewerkt: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $caseRecord['updated_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p>Referentie: <?= htmlspecialchars((string) $caseRecord['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    </section>

    <section class="case-grid">
      <article class="info-card">
        <h2>Klantgegevens</h2>
        <ul>
          <li><strong>Naam:</strong> <?= htmlspecialchars((string) $caseRecord['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>E-mail:</strong> <?= htmlspecialchars((string) $caseRecord['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Telefoon:</strong> <?= htmlspecialchars((string) $caseRecord['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Adres:</strong> <?= htmlspecialchars((string) ($caseRecord['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php if (!empty($caseRecord['postal_code'])): ?>
            <li><strong>Postcode:</strong> <?= htmlspecialchars((string) $caseRecord['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
          <?php if (!empty($caseRecord['city'])): ?>
            <li><strong>Plaats:</strong> <?= htmlspecialchars((string) $caseRecord['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
        </ul>
      </article>

      <article class="info-card">
        <h2>Apparaatgegevens</h2>
        <?php if ($caseRecord['device_brand'] || $caseRecord['device_model']): ?>
          <ul>
            <?php if (!empty($caseRecord['device_brand'])): ?><li><strong>Merk:</strong> <?= htmlspecialchars((string) $caseRecord['device_brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_model'])): ?><li><strong>Model:</strong> <?= htmlspecialchars((string) $caseRecord['device_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_serial'])): ?><li><strong>Serienummer:</strong> <?= htmlspecialchars((string) $caseRecord['device_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
          </ul>
        <?php else: ?>
          <p class="muted">Geen apparaatgegevens beschikbaar.</p>
        <?php endif; ?>
      </article>

      <article class="info-card info-card--wide">
        <h2>Details</h2>
        <?php if (empty($caseDetails)): ?>
          <p class="muted">Geen aanvullende details opgeslagen.</p>
        <?php else: ?>
          <dl class="details-list">
            <?php foreach ($caseDetails as $key => $value): ?>
              <div>
                <dt><?= htmlspecialchars(str_replace('_', ' ', ucfirst((string) $key)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= is_array($value) ? htmlspecialchars(json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : nl2br(htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </article>
    </section>

    <section class="notes-section">
      <div class="notes-header">
        <h2>Notities</h2>
      </div>
      <div class="notes-grid">
        <div class="notes-list">
          <?php if (empty($notes)): ?>
            <p class="muted">Er zijn nog geen notities voor deze case.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($notes as $note): ?>
                <li>
                  <div class="note-header">
                    <strong><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <span class="muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                  <div class="note-body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="note-form">
          <h3>Nieuwe notitie</h3>
          <?php if (!empty($errors['general'])): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <label for="body">Notitie</label>
            <textarea name="body" id="body" rows="5" required></textarea>
            <button type="submit" class="btn">Opslaan</button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>