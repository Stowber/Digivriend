<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\DevicePhotoRepository;
use App\Support\Repositories\DeviceComponentRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\RepairEventRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$deviceRepository = new DeviceRepository($pdo);
$photoRepository = new DevicePhotoRepository($pdo);
$componentRepository = new DeviceComponentRepository($pdo);
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
$components = $componentRepository->forDevice($deviceId);

$eventTypes = [
    'diagnose' => 'Diagnose',
    'component' => 'Onderdeel vervangen',
    'status' => 'Status update',
    'note' => 'Opmerking',
];

$deviceTypeSuggestions = [
    'Desktop PC',
    'Laptop',
    'Workstation',
    'Server',
    'Smartphone',
    'Tablet',
    'Gameconsole',
    'Netwerkapparaat',
    'All-in-one',
    'Overig',
];

$componentCategories = [
    'cpu' => 'Processor (CPU)',
    'gpu' => 'Grafische kaart (GPU)',
    'motherboard' => 'Moederbord',
    'memory' => 'Geheugen (RAM)',
    'storage' => 'Opslag (SSD/HDD)',
    'psu' => 'Voeding (PSU)',
    'cooling' => 'Koeling',
    'display' => 'Scherm / Display',
    'battery' => 'Batterij / Accu',
    'network' => 'Netwerkkaart / Modem',
    'peripheral' => 'Randapparatuur',
    'other' => 'Overig',
];

$eventErrors = [];
$deviceFormErrors = [];
$componentFormErrors = [];
$componentActionErrors = [];
$generalErrors = [];

$componentFormValues = [
    'component_category' => '',
    'component_category_custom' => '',
    'component_name' => '',
    'component_manufacturer' => '',
    'component_model' => '',
    'component_serial' => '',
    'component_specifications' => '',
    'component_notes' => '',
    'component_installed_at' => '',
];

$deviceFormValues = [
    'brand' => (string) ($device['brand'] ?? ''),
    'model' => (string) ($device['model'] ?? ''),
    'serial_number' => (string) ($device['serial_number'] ?? ''),
    'device_type' => (string) ($device['device_type'] ?? ''),
    'notes' => (string) ($device['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        switch ($action) {
            case 'update-device':
                $deviceFormValues['brand'] = InputValidator::requireString($_POST, 'brand', 120);
                $deviceFormValues['model'] = InputValidator::optionalString($_POST, 'model', 191);
                $deviceFormValues['serial_number'] = InputValidator::optionalString($_POST, 'serial_number', 120);
                $deviceFormValues['device_type'] = InputValidator::optionalString($_POST, 'device_type', 120);
                $deviceFormValues['notes'] = InputValidator::optionalString($_POST, 'notes', 1000);

                $deviceRepository->updateDeviceProfile(
                    $deviceId,
                    $deviceFormValues['brand'],
                    $deviceFormValues['model'] !== '' ? $deviceFormValues['model'] : null,
                    $deviceFormValues['serial_number'] !== '' ? $deviceFormValues['serial_number'] : null,
                    $deviceFormValues['device_type'] !== '' ? $deviceFormValues['device_type'] : null,
                    $deviceFormValues['notes'] !== '' ? $deviceFormValues['notes'] : null
                );

                Response::redirect('device.php?id=' . $deviceId . '&device_updated=1');
                break;

            case 'add-component':
                $componentFormValues['component_category'] = InputValidator::requireString($_POST, 'component_category', 64);
                $categoryKey = $componentFormValues['component_category'];

                if ($categoryKey === 'other') {
                    $componentFormValues['component_category_custom'] = InputValidator::requireString($_POST, 'component_category_custom', 120);
                    $categoryLabel = $componentFormValues['component_category_custom'];
                } elseif (isset($componentCategories[$categoryKey])) {
                    $categoryLabel = $componentCategories[$categoryKey];
                    $componentFormValues['component_category_custom'] = '';
                } else {
                    throw new ValidationException(['component_category' => 'Selecteer een geldige categorie.']);
                }

                $componentFormValues['component_name'] = InputValidator::requireString($_POST, 'component_name', 191);
                $componentFormValues['component_manufacturer'] = InputValidator::optionalString($_POST, 'component_manufacturer', 120);
                $componentFormValues['component_model'] = InputValidator::optionalString($_POST, 'component_model', 191);
                $componentFormValues['component_serial'] = InputValidator::optionalString($_POST, 'component_serial', 120);
                $componentFormValues['component_specifications'] = InputValidator::optionalString($_POST, 'component_specifications', 500);
                $componentFormValues['component_notes'] = InputValidator::optionalString($_POST, 'component_notes', 500);

                $installedAtInput = trim((string) ($_POST['component_installed_at'] ?? ''));
                $componentFormValues['component_installed_at'] = htmlspecialchars($installedAtInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $installedAt = null;
                if ($installedAtInput !== '') {
                    $normalized = str_replace('T', ' ', $installedAtInput);
                    $dateTime = date_create_immutable($normalized);
                    if ($dateTime === false) {
                        throw new ValidationException(['component_installed_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $installedAt = $dateTime->format('Y-m-d H:i:s');
                }

                $componentRepository->add(
                    $deviceId,
                    $categoryLabel,
                    $componentFormValues['component_name'],
                    $componentFormValues['component_manufacturer'] !== '' ? $componentFormValues['component_manufacturer'] : null,
                    $componentFormValues['component_model'] !== '' ? $componentFormValues['component_model'] : null,
                    $componentFormValues['component_serial'] !== '' ? $componentFormValues['component_serial'] : null,
                    $componentFormValues['component_specifications'] !== '' ? $componentFormValues['component_specifications'] : null,
                    $componentFormValues['component_notes'] !== '' ? $componentFormValues['component_notes'] : null,
                    $installedAt
                );

                Response::redirect('device.php?id=' . $deviceId . '&component_added=1');
                break;

            case 'retire-component':
                $componentId = filter_var($_POST['component_id'] ?? null, FILTER_VALIDATE_INT);
                if ($componentId === false || $componentId === null) {
                    throw new ValidationException(['component' => 'Ongeldig component geselecteerd.']);
                }

                $component = $componentRepository->find((int) $componentId);
                if ($component === null || (int) $component['device_id'] !== $deviceId) {
                    throw new ValidationException(['component' => 'Component hoort niet bij dit apparaat.']);
                }

                if (!empty($component['removed_at'])) {
                    throw new ValidationException(['component' => 'Dit component is al gearchiveerd.']);
                }

                $removalReason = InputValidator::optionalString($_POST, 'removal_reason', 500);
                $componentRepository->retire((int) $componentId, $removalReason !== '' ? $removalReason : null);

                Response::redirect('device.php?id=' . $deviceId . '&component_retired=1');
                break;

            case 'replace-component':
                $componentId = filter_var($_POST['component_id'] ?? null, FILTER_VALIDATE_INT);
                if ($componentId === false || $componentId === null) {
                    throw new ValidationException(['component' => 'Ongeldig component geselecteerd.']);
                }

                $component = $componentRepository->find((int) $componentId);
                if ($component === null || (int) $component['device_id'] !== $deviceId) {
                    throw new ValidationException(['component' => 'Component hoort niet bij dit apparaat.']);
                }

                if (!empty($component['removed_at'])) {
                    throw new ValidationException(['component' => 'Dit component is al gearchiveerd.']);
                }

                $replacementCategoryKey = InputValidator::requireString($_POST, 'replacement_category', 64);
                if ($replacementCategoryKey === 'other') {
                    $replacementCategoryLabel = InputValidator::requireString($_POST, 'replacement_category_custom', 120);
                } elseif (isset($componentCategories[$replacementCategoryKey])) {
                    $replacementCategoryLabel = $componentCategories[$replacementCategoryKey];
                } else {
                    throw new ValidationException(['replacement_category' => 'Selecteer een geldige categorie.']);
                }

                $replacementName = InputValidator::requireString($_POST, 'replacement_name', 191);
                $replacementManufacturer = InputValidator::optionalString($_POST, 'replacement_manufacturer', 120);
                $replacementModel = InputValidator::optionalString($_POST, 'replacement_model', 191);
                $replacementSerial = InputValidator::optionalString($_POST, 'replacement_serial', 120);
                $replacementSpecs = InputValidator::optionalString($_POST, 'replacement_specifications', 500);
                $replacementNotes = InputValidator::optionalString($_POST, 'replacement_notes', 500);
                $removalReason = InputValidator::optionalString($_POST, 'replacement_removal_reason', 500);

                $replacementInstalledAtInput = trim((string) ($_POST['replacement_installed_at'] ?? ''));
                $replacementInstalledAt = null;
                if ($replacementInstalledAtInput !== '') {
                    $normalizedReplacement = str_replace('T', ' ', $replacementInstalledAtInput);
                    $replacementDate = date_create_immutable($normalizedReplacement);
                    if ($replacementDate === false) {
                        throw new ValidationException(['replacement_installed_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $replacementInstalledAt = $replacementDate->format('Y-m-d H:i:s');
                }

                $pdo->beginTransaction();
                try {
                    $newComponentId = $componentRepository->add(
                        $deviceId,
                        $replacementCategoryLabel,
                        $replacementName,
                        $replacementManufacturer !== '' ? $replacementManufacturer : null,
                        $replacementModel !== '' ? $replacementModel : null,
                        $replacementSerial !== '' ? $replacementSerial : null,
                        $replacementSpecs !== '' ? $replacementSpecs : null,
                        $replacementNotes !== '' ? $replacementNotes : null,
                        $replacementInstalledAt
                    );

                    $componentRepository->retire((int) $componentId, $removalReason !== '' ? $removalReason : null, $newComponentId);
                    $pdo->commit();
                } catch (\Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $throwable;
                }

                Response::redirect('device.php?id=' . $deviceId . '&component_replaced=1');
                break;

            case 'add-event':
                $caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT);
                $eventType = InputValidator::requireString($_POST, 'event_type', 64);
                $description = InputValidator::requireString($_POST, 'description', 1000);
                $componentName = InputValidator::optionalString($_POST, 'component', 191);
                $statusUpdate = InputValidator::optionalString($_POST, 'status_update', 64);
                $costs = InputValidator::optionalString($_POST, 'costs', 64);

                if (!array_key_exists($eventType, $eventTypes)) {
                    throw new ValidationException(['event_type' => 'Ongeldig type.']);
                }

                if ($caseId !== false && $caseId !== null && !isset($caseOptions[(int) $caseId])) {
                    throw new ValidationException(['case_id' => 'Onbekende case geselecteerd.']);
                }

                $metadata = [];
                if ($componentName !== '') {
                    $metadata['component'] = $componentName;
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
                    if ($componentName !== '') {
                        $noteBody .= ' | Component: ' . $componentName;
                    }
                    if ($costs !== '') {
                        $noteBody .= ' | Kosten/onderdeel: ' . $costs;
                    }
                    $noteRepository->add($selectedCaseId, (int) $device['customer_id'], Auth::username(), $noteBody);
                }

                Response::redirect('device.php?id=' . $deviceId . '&event_added=1');
                break;

            default:
                throw new ValidationException(['general' => 'Onbekende actie.']);
        }
    } catch (ValidationException $exception) {
        $errorBag = $exception->errors();
        switch ($action) {
            case 'update-device':
                $deviceFormErrors = $errorBag;
                break;
            case 'add-component':
                $componentFormErrors = $errorBag;
                break;
            case 'retire-component':
            case 'replace-component':
                $componentId = isset($_POST['component_id']) ? (int) $_POST['component_id'] : 0;
                $componentActionErrors[$componentId] = $errorBag;
                if ($componentId === 0 && !empty($errorBag['component'])) {
                    $generalErrors[] = $errorBag['component'];
                }
                break;
            case 'add-event':
                $eventErrors = $errorBag;
                break;
            default:
                if (!empty($errorBag['general'])) {
                    $generalErrors[] = $errorBag['general'];
                }
                break;
        }
    } catch (\Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $generalErrors[] = 'Er is een onverwachte fout opgetreden bij het verwerken van het formulier.';
    }
}

$csrfToken = Csrf::token();
$created = isset($_GET['created']);
$eventAdded = isset($_GET['event_added']);
$deviceUpdated = isset($_GET['device_updated']);
$componentAdded = isset($_GET['component_added']);
$componentReplaced = isset($_GET['component_replaced']);
$componentRetired = isset($_GET['component_retired']);
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
    <?php if ($deviceUpdated): ?>
      <div class="alert alert--success">Apparaatgegevens bijgewerkt.</div>
    <?php endif; ?>
    <?php if ($componentAdded): ?>
      <div class="alert alert--success">Nieuw component opgeslagen.</div>
    <?php endif; ?>
    <?php if ($componentReplaced): ?>
      <div class="alert alert--success">Component vervangen en historiek bijgewerkt.</div>
    <?php endif; ?>
    <?php if ($componentRetired): ?>
      <div class="alert alert--success">Component gearchiveerd.</div>
    <?php endif; ?>
    <?php if ($eventAdded): ?>
      <div class="alert alert--success">Nieuw reparatiemoment opgeslagen.</div>
    <?php endif; ?>
    <?php foreach ($generalErrors as $message): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endforeach; ?>

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
        <?php if (!empty($device['device_type'])): ?>
          <div>
            <dt>Type</dt>
            <dd><?= htmlspecialchars((string) $device['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
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
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="device-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="update-device">
        <div class="form-grid">
          <label>
            Merk*
            <input type="text" name="brand" value="<?= htmlspecialchars($deviceFormValues['brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            <?php if (!empty($deviceFormErrors['brand'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Model
            <input type="text" name="model" value="<?= htmlspecialchars($deviceFormValues['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($deviceFormErrors['model'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Serienummer
            <input type="text" name="serial_number" value="<?= htmlspecialchars($deviceFormValues['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($deviceFormErrors['serial_number'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Type apparaat
            <input type="text" name="device_type" list="device-type-options" value="<?= htmlspecialchars($deviceFormValues['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. Laptop">
            <?php if (!empty($deviceFormErrors['device_type'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
        </div>
        <datalist id="device-type-options">
          <?php foreach ($deviceTypeSuggestions as $suggestion): ?>
            <option value="<?= htmlspecialchars($suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <label>
          Interne notities
          <textarea name="notes" rows="3"><?= htmlspecialchars($deviceFormValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($deviceFormErrors['notes'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--secondary">Opslaan</button>
        </div>
      </form>
      <div class="barcode-preview">
        <img src="device-barcode.php?id=<?= $deviceId ?>" alt="Barcode" loading="lazy">
      </div>
    </section>

    <section class="card">
      <h2>Hardwarecomponenten</h2>
      <p>Leg alle aanwezige hardwarecomponenten vast, markeer vervangen onderdelen en bewaak zo de volledige servicegeschiedenis.</p>
      <?php if ($components === []): ?>
        <p>Er zijn nog geen hardwarecomponenten geregistreerd.</p>
      <?php else: ?>
        <div class="component-list">
          <?php foreach ($components as $component): ?>
            <?php
              $componentId = (int) $component['id'];
              $isActive = empty($component['removed_at']);
              $componentStatus = $isActive ? 'Actief' : 'Gearchiveerd';
              $installedAtDisplay = '';
              if (!empty($component['installed_at'])) {
                  $installedAtDisplay = date('d-m-Y H:i', strtotime((string) $component['installed_at']));
              }
              $removedAtDisplay = '';
              if (!empty($component['removed_at'])) {
                  $removedAtDisplay = date('d-m-Y H:i', strtotime((string) $component['removed_at']));
              }
              $replacementName = (string) ($component['replacement_component_name'] ?? '');
              $categoryKey = array_search($component['category'], $componentCategories, true);
              $replacementCategoryDefault = $categoryKey !== false ? (string) $categoryKey : 'other';
              $replacementCategoryCustomDefault = $categoryKey === false ? (string) $component['category'] : '';
            ?>
            <article class="component-card<?= $isActive ? '' : ' component-card--archived' ?>">
              <header class="component-card__header">
                <h3><?= htmlspecialchars((string) $component['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                <span class="component-card__status"><?= htmlspecialchars($componentStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </header>
              <dl class="data-list data-list--compact">
                <div>
                  <dt>Categorie</dt>
                  <dd><?= htmlspecialchars((string) $component['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <?php if (!empty($component['manufacturer'])): ?>
                  <div>
                    <dt>Fabrikant</dt>
                    <dd><?= htmlspecialchars((string) $component['manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['model'])): ?>
                  <div>
                    <dt>Model</dt>
                    <dd><?= htmlspecialchars((string) $component['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['serial_number'])): ?>
                  <div>
                    <dt>Serienummer</dt>
                    <dd><?= htmlspecialchars((string) $component['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <div>
                  <dt>Geplaatst op</dt>
                  <dd><?= htmlspecialchars($installedAtDisplay !== '' ? $installedAtDisplay : 'Onbekend', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <?php if (!$isActive && $removedAtDisplay !== ''): ?>
                  <div>
                    <dt>Verwijderd op</dt>
                    <dd><?= htmlspecialchars($removedAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!$isActive && !empty($component['removal_reason'])): ?>
                  <div>
                    <dt>Reden</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['removal_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['specifications'])): ?>
                  <div>
                    <dt>Specificaties</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['notes'])): ?>
                  <div>
                    <dt>Notities</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!$isActive && $replacementName !== ''): ?>
                  <div>
                    <dt>Vervangen door</dt>
                    <dd><?= htmlspecialchars($replacementName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
              </dl>
              <?php if (!empty($componentActionErrors[$componentId])): ?>
                <div class="alert alert--danger component-card__alert">
                  <ul>
                    <?php foreach ($componentActionErrors[$componentId] as $errorMessage): ?>
                      <li><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($isActive): ?>
                <details class="component-card__details">
                  <summary>Nieuw onderdeel plaatsen</summary>
                  <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="replace-component">
                    <input type="hidden" name="component_id" value="<?= $componentId ?>">
                    <div class="form-grid">
                      <label>
                        Categorie*
                        <select name="replacement_category" required>
                          <?php foreach ($componentCategories as $key => $label): ?>
                            <?php if ($key === 'other') { continue; } ?>
                            <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $replacementCategoryDefault === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <?php endforeach; ?>
                          <option value="other" <?= $replacementCategoryDefault === 'other' ? 'selected' : '' ?>>Overig (vrij invullen)</option>
                        </select>
                      </label>
                      <label>
                        Categorie (vrij)
                        <input type="text" name="replacement_category_custom" value="<?= htmlspecialchars($replacementCategoryCustomDefault, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. Ventilator">
                      </label>
                      <label>
                        Onderdeelnaam*
                        <input type="text" name="replacement_name" required>
                      </label>
                      <label>
                        Fabrikant
                        <input type="text" name="replacement_manufacturer">
                      </label>
                      <label>
                        Model
                        <input type="text" name="replacement_model">
                      </label>
                      <label>
                        Serienummer
                        <input type="text" name="replacement_serial">
                      </label>
                      <label>
                        Geplaatst op
                        <input type="datetime-local" name="replacement_installed_at">
                      </label>
                    </div>
                    <label>
                      Specificaties
                      <textarea name="replacement_specifications" rows="2"></textarea>
                    </label>
                    <label>
                      Notities
                      <textarea name="replacement_notes" rows="2"></textarea>
                    </label>
                    <label>
                      Reden van vervanging
                      <textarea name="replacement_removal_reason" rows="2" placeholder="Bijv. defecte ventilator of upgrade"></textarea>
                    </label>
                    <div class="form-actions">
                      <button type="submit" class="btn btn--primary">Vervanging registreren</button>
                    </div>
                  </form>
                </details>
                <details class="component-card__details">
                  <summary>Component archiveren</summary>
                  <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="retire-component">
                    <input type="hidden" name="component_id" value="<?= $componentId ?>">
                    <label>
                      Reden (optioneel)
                      <textarea name="removal_reason" rows="2" placeholder="Bijv. klant neemt onderdeel mee"></textarea>
                    </label>
                    <div class="form-actions">
                      <button type="submit" class="btn btn--ghost">Markeren als verwijderd</button>
                    </div>
                  </form>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <hr>
      <h3>Nieuw component toevoegen</h3>
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add-component">
        <div class="form-grid">
          <label>
            Categorie*
            <select name="component_category" required>
              <?php foreach ($componentCategories as $key => $label): ?>
                <?php if ($key === 'other') { continue; } ?>
                <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $componentFormValues['component_category'] === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
              <option value="other" <?= $componentFormValues['component_category'] === 'other' ? 'selected' : '' ?>>Overig (vrij invullen)</option>
            </select>
            <?php if (!empty($componentFormErrors['component_category'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Categorie (vrij)
            <input type="text" name="component_category_custom" value="<?= htmlspecialchars($componentFormValues['component_category_custom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Alleen invullen bij &#39;Overig&#39;">
            <?php if (!empty($componentFormErrors['component_category_custom'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_category_custom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Onderdeelnaam*
            <input type="text" name="component_name" value="<?= htmlspecialchars($componentFormValues['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            <?php if (!empty($componentFormErrors['component_name'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Fabrikant
            <input type="text" name="component_manufacturer" value="<?= htmlspecialchars($componentFormValues['component_manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_manufacturer'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Model
            <input type="text" name="component_model" value="<?= htmlspecialchars($componentFormValues['component_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_model'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Serienummer
            <input type="text" name="component_serial" value="<?= htmlspecialchars($componentFormValues['component_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_serial'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Geplaatst op
            <input type="datetime-local" name="component_installed_at" value="<?= htmlspecialchars($componentFormValues['component_installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_installed_at'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
        </div>
        <label>
          Specificaties
          <textarea name="component_specifications" rows="2"><?= htmlspecialchars($componentFormValues['component_specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($componentFormErrors['component_specifications'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <label>
          Notities
          <textarea name="component_notes" rows="2"><?= htmlspecialchars($componentFormValues['component_notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($componentFormErrors['component_notes'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--secondary">Component toevoegen</button>
        </div>
      </form>
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
             <?php if (!empty($eventErrors['case_id'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Type activiteit
            <select name="event_type" required>
              <?php foreach ($eventTypes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $selectedEventTypePost === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($eventErrors['event_type'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
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
          <?php if (!empty($eventErrors['description'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--primary">Activiteit opslaan</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>