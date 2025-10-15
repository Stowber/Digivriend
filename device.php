<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\DevicePhotoRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\RepairEventRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$deviceRepository = new DeviceRepository($pdo);
$photoRepository = new DevicePhotoRepository($pdo);
$repairEventRepository = new RepairEventRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

$deviceIdParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$barcodeParam = trim((string) ($_GET['barcode'] ?? ''));

if ($barcodeParam !== '') {
    $device = $deviceRepository->findWithCustomerByBarcode($barcodeParam);
} elseif ($deviceIdParam) {
    $device = $deviceRepository->findWithCustomer((int) $deviceIdParam);
} else {
    $device = null;
}

if ($device === null) {
    http_response_code(404);
    echo '<h1>Apparaat niet gevonden</h1>';
    exit;
}

$deviceId = (int) $device['id'];
$barcode = $deviceRepository->ensureBarcode($deviceId);

$casesStatement = $pdo->prepare(
    'SELECT c.*, COUNT(re.id) AS events_count
     FROM cases c
     LEFT JOIN repair_events re ON re.case_id = c.id
     WHERE c.device_id = :device_id
     GROUP BY c.id
     ORDER BY c.updated_at DESC'
);
$casesStatement->execute(['device_id' => $deviceId]);
$cases = $casesStatement->fetchAll() ?: [];

$caseOptions = [];
foreach ($cases as $case) {
    $caseOptions[(int) $case['id']] = $case;
}

$photos = $photoRepository->forDevice($deviceId);
$events = $repairEventRepository->forDevice($deviceId);

$eventTypes = [
    'diagnose' => 'Diagnose',
    'component' => 'Onderdeel vervangen',
    'status' => 'Status update',
    'note' => 'Opmerking',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        $action = $_POST['action'] ?? '';

        if ($action === 'add-event') {
            $caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT);
            $eventType = InputValidator::requireString($_POST, 'event_type', 64);
            $description = InputValidator::requireString($_POST, 'description', 1000);
            $component = InputValidator::optionalString($_POST, 'component', 191);
            $statusUpdate = InputValidator::optionalString($_POST, 'status_update', 64);
            $costs = InputValidator::optionalString($_POST, 'costs', 64);

            if (!array_key_exists($eventType, $eventTypes)) {
                throw new ValidationException(['event_type' => 'Ongeldig type.']);
            }

            if ($caseId !== false && $caseId !== null && !isset($caseOptions[(int) $caseId])) {
                throw new ValidationException(['case_id' => 'Onbekende case geselecteerd.']);
            }

            $metadata = [];
            if ($component !== '') {
                $metadata['component'] = $component;
            }
            if ($costs !== '') {
                $metadata['costs'] = $costs;
            }
            if ($statusUpdate !== '') {
                $metadata['status'] = $statusUpdate;
            }

            $selectedCaseId = $caseId ? (int) $caseId : (isset($cases[0]['id']) ? (int) $cases[0]['id'] : null);

            $repairEventRepository->log(
                $deviceId,
                $selectedCaseId,
                $eventType,
                $description,
                Auth::username(),
                $metadata
            );

            if ($selectedCaseId !== null) {
                if ($statusUpdate !== '') {
                    $caseRepository->updateStatus($selectedCaseId, $statusUpdate);
                }

                $noteBody = 'Reparatie-activiteit (' . $eventTypes[$eventType] . '): ' . $description;
                if ($component !== '') {
                    $noteBody .= ' | Component: ' . $component;
                }
                if ($costs !== '') {
                    $noteBody .= ' | Kosten/onderdeel: ' . $costs;
                }
                $noteRepository->add($selectedCaseId, (int) $device['customer_id'], Auth::username(), $noteBody);
            }

            Response::redirect('device.php?id=' . $deviceId . '&event_added=1');
        }
    } catch (ValidationException $exception) {
        $errors = $exception->errors();
    }
}

$csrfToken = Csrf::token();
$created = isset($_GET['created']);
$eventAdded = isset($_GET['event_added']);
$selectedCasePost = isset($_POST['case_id']) ? (string) $_POST['case_id'] : '';
$selectedEventTypePost = isset($_POST['event_type']) ? (string) $_POST['event_type'] : '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Apparaat <?= htmlspecialchars((string) $barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/devices.css">
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
          <li><a href="devices.php" aria-current="page">Klanten &amp; apparaten</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php">Documenten</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>
  <main class="container">
    <a href="devices.php" class="back-link">&larr; Terug naar overzicht</a>
    <div class="page-header">
      <div>
        <h1><?= htmlspecialchars(trim((string) ($device['brand'] ?? '') . ' ' . ($device['model'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro">Barcode: <code><?= htmlspecialchars($barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
      </div>
      <a href="device-barcode.php?id=<?= $deviceId ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print barcode</a>
    </div>

    <?php if ($created): ?>
      <div class="alert alert--success">Intake succesvol geregistreerd. Barcode en dossier zijn aangemaakt.</div>
    <?php endif; ?>
    <?php if ($eventAdded): ?>
      <div class="alert alert--success">Nieuw reparatiemoment opgeslagen.</div>
    <?php endif; ?>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="card">
      <h2>Klantgegevens</h2>
      <dl class="data-list">
        <div>
          <dt>Naam</dt>
          <dd><?= htmlspecialchars((string) $device['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <?php if (!empty($device['email'])): ?>
          <div>
            <dt>E-mail</dt>
            <dd><a href="mailto:<?= htmlspecialchars((string) $device['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) $device['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['phone'])): ?>
          <div>
            <dt>Telefoon</dt>
            <dd><a href="tel:<?= htmlspecialchars((string) $device['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) $device['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['address']) || !empty($device['postal_code']) || !empty($device['city'])): ?>
          <div>
            <dt>Adres</dt>
            <dd><?= htmlspecialchars(trim(($device['address'] ?? '') . ' ' . ($device['postal_code'] ?? '') . ' ' . ($device['city'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="card">
      <h2>Apparaatgegevens</h2>
      <dl class="data-list">
        <div>
          <dt>Merk</dt>
          <dd><?= htmlspecialchars((string) ($device['brand'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <div>
          <dt>Model</dt>
          <dd><?= htmlspecialchars((string) ($device['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <?php if (!empty($device['serial_number'])): ?>
          <div>
            <dt>Serienummer</dt>
            <dd><?= htmlspecialchars((string) $device['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['notes'])): ?>
          <div>
            <dt>Notities</dt>
            <dd><?= nl2br(htmlspecialchars((string) $device['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
      <div class="barcode-preview">
        <img src="device-barcode.php?id=<?= $deviceId ?>" alt="Barcode" loading="lazy">
      </div>
    </section>

    <section class="card">
      <h2>Foto&#39;s van intake</h2>
      <?php if ($photos === []): ?>
        <p>Er zijn nog geen foto&#39;s opgeslagen.</p>
      <?php else: ?>
        <div class="photo-gallery">
          <?php foreach ($photos as $photo): ?>
            <a class="photo-gallery__item" href="device-photo.php?id=<?= (int) $photo['id'] ?>" target="_blank" rel="noopener">
              <img src="device-photo.php?id=<?= (int) $photo['id'] ?>" alt="<?= htmlspecialchars((string) $photo['orientation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
              <span><?= htmlspecialchars((string) $photo['orientation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Reparatiegeschiedenis</h2>
      <?php if ($events === []): ?>
        <p>Er zijn nog geen reparatieactiviteiten geregistreerd.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($events as $event): ?>
            <li class="timeline__item">
              <header class="timeline__header">
                <span class="timeline__type"><?= htmlspecialchars($eventTypes[$event['event_type']] ?? (string) $event['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="timeline__meta">door <?= htmlspecialchars((string) $event['performed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> op <?= htmlspecialchars((string) $event['performed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </header>
              <p><?= nl2br(htmlspecialchars((string) $event['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
              <?php if (!empty($event['metadata'])): ?>
                <dl class="timeline__meta-list">
                  <?php foreach ($event['metadata'] as $key => $value): ?>
                    <div>
                      <dt><?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                      <dd><?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              <?php endif; ?>
              <?php if (!empty($event['case_id'])): ?>
                <p class="timeline__case">Case: <a href="case.php?id=<?= (int) $event['case_id'] ?>">#<?= (int) $event['case_id'] ?> <?= htmlspecialchars((string) ($event['case_summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Nieuwe reparatie-activiteit</h2>
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="event-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add-event">
        <div class="form-grid">
          <label>
            Koppel aan case
            <select name="case_id">
              <option value="">(Meest recente)</option>
              <?php foreach ($cases as $case): ?>
                <?php $caseIdString = (string) $case['id']; ?>
                <option value="<?= (int) $case['id'] ?>" <?= $selectedCasePost === $caseIdString ? 'selected' : '' ?>>Case #<?= (int) $case['id'] ?> - <?= htmlspecialchars((string) $case['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['case_id'])): ?><span class="form-error"><?= htmlspecialchars($errors['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Type activiteit
            <select name="event_type" required>
              <?php foreach ($eventTypes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $selectedEventTypePost === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($errors['event_type'])): ?><span class="form-error"><?= htmlspecialchars($errors['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Betrokken onderdeel
            <input type="text" name="component" value="<?= htmlspecialchars((string) ($_POST['component'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. RAM module">
          </label>
          <label>
            Nieuwe status
            <input type="text" name="status_update" value="<?= htmlspecialchars((string) ($_POST['status_update'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. in_reparatie">
          </label>
          <label>
            Kosten / referentie
            <input type="text" name="costs" value="<?= htmlspecialchars((string) ($_POST['costs'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. €50 of ordernummer">
          </label>
        </div>
        <label>
          Beschrijving*
          <textarea name="description" rows="4" required><?= htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($errors['description'])): ?><span class="form-error"><?= htmlspecialchars($errors['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--primary">Activiteit opslaan</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>