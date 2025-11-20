<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DevicePhotoRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\RepairEventRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$photoRepository = new DevicePhotoRepository($pdo);
$repairEventRepository = new RepairEventRepository($pdo);

$orientations = [
    'front' => 'Voorkant',
    'back' => 'Achterkant',
    'left' => 'Linkerzijde',
    'right' => 'Rechterzijde',
    'top' => 'Bovenkant',
    'bottom' => 'Onderkant',
];

$values = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'address' => '',
    'postal_code' => '',
    'city' => '',
    'device_brand' => '',
    'device_model' => '',
    'device_serial' => '',
    'device_notes' => '',
    'intake_notes' => '',
    'device_type' => '',
];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer het opnieuw.']);
        }

        $values['full_name'] = InputValidator::requireString($_POST, 'full_name', 191);
        $values['email'] = InputValidator::optionalEmail($_POST, 'email', 191);
        $values['phone'] = InputValidator::optionalPhone($_POST, 'phone', 32);
        $values['address'] = InputValidator::optionalString($_POST, 'address', 255);
        $values['postal_code'] = InputValidator::optionalString($_POST, 'postal_code', 32);
        $values['city'] = InputValidator::optionalString($_POST, 'city', 120);
        $values['device_brand'] = InputValidator::requireString($_POST, 'device_brand', 120);
        $values['device_model'] = InputValidator::optionalString($_POST, 'device_model', 191);
        $values['device_serial'] = InputValidator::optionalString($_POST, 'device_serial', 120);
        $values['device_type'] = InputValidator::optionalString($_POST, 'device_type', 120);
        $values['device_notes'] = InputValidator::optionalString($_POST, 'device_notes', 500);
        $values['intake_notes'] = InputValidator::optionalString($_POST, 'intake_notes', 1000);

        $pdo->beginTransaction();

        $customer = $customerRepository->upsert(
            $values['full_name'],
            $values['email'] !== '' ? $values['email'] : null,
            $values['phone'] !== '' ? $values['phone'] : null,
            $values['address'] !== '' ? $values['address'] : null,
            $values['postal_code'] !== '' ? $values['postal_code'] : null,
            $values['city'] !== '' ? $values['city'] : null
        );

        $device = $deviceRepository->findOrCreate(
            (int) $customer['id'],
            $values['device_brand'] !== '' ? $values['device_brand'] : null,
            $values['device_model'] !== '' ? $values['device_model'] : null,
            $values['device_serial'] !== '' ? $values['device_serial'] : null,
            $values['device_type'] !== '' ? $values['device_type'] : null,
            $values['device_notes'] !== '' ? $values['device_notes'] : null
        );

        if ($device === null) {
            throw new ValidationException(['general' => 'Kon apparaat niet registreren.']);
        }

        $deviceId = (int) $device['id'];
        $barcode = $deviceRepository->ensureBarcode($deviceId);

        $photoMetadata = [];
        foreach ($orientations as $orientation => $label) {
            $fileKey = 'photo_' . $orientation;
            $photoMetadata[$orientation] = saveDevicePhoto(
                $deviceId,
                $orientation,
                $label,
                $_FILES[$fileKey] ?? null,
                $photoRepository
            );
        }

        $caseDetails = [
            'intake_notes' => $values['intake_notes'],
            'device_notes' => $values['device_notes'],
            'registered_by' => Auth::username(),
            'barcode' => $barcode,
        ];

        $case = $caseRepository->createOrUpdate(
            'repair',
            (int) $customer['id'],
            $deviceId,
            'intake',
            'Intake apparaat',
            null,
            $caseDetails
        );

        $noteRepository->add(
            (int) $case['id'],
            (int) $customer['id'],
            Auth::username(),
            'Apparaat intake geregistreerd inclusief foto\'s en barcode ' . $barcode
        );

        if ($values['intake_notes'] !== '') {
            $noteRepository->add(
                (int) $case['id'],
                (int) $customer['id'],
                Auth::username(),
                'Intake notities: ' . $values['intake_notes']
            );
        }

        $repairEventRepository->log(
            $deviceId,
            (int) $case['id'],
            'intake',
            'Intake afgerond en apparaat geregistreerd.',
            Auth::username(),
            [
                'photos' => $photoMetadata,
                'notes' => $values['intake_notes'],
            ]
        );

        $customerRepository->touch((int) $customer['id']);

        $pdo->commit();

        Response::redirect('device.php?id=' . $deviceId . '&created=1');
    } catch (ValidationException $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors = $exception->errors();
    } catch (\Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errors['general'] = 'Er is een fout opgetreden tijdens het opslaan van het intakeformulier.';
    }
}

$csrfToken = Csrf::token();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Intake apparaat - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/devices.css">
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
        <?php render_main_nav('device_register'); ?>
      </nav>
    </div>
  </header>
  <main class="container">
    <h1>Intake apparaat</h1>
    <p class="page-intro">Registreer een apparaat, koppel het aan een klant en voeg foto&#39;s van alle zijden toe voor de reparatiehistorie.</p>
    <?php if (!empty($errors['general'])): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>
    <form action="device-intake.php" method="post" enctype="multipart/form-data" class="card">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <section class="card__section">
        <h2>Klantgegevens</h2>
        <div class="form-grid">
          <label>
            Naam*
            <input type="text" name="full_name" value="<?= $values['full_name'] ?>" required>
            <?php if (!empty($errors['full_name'])): ?><span class="form-error"><?= htmlspecialchars($errors['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            E-mail
            <input type="email" name="email" value="<?= $values['email'] ?>">
            <?php if (!empty($errors['email'])): ?><span class="form-error"><?= htmlspecialchars($errors['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Telefoon
            <input type="text" name="phone" value="<?= $values['phone'] ?>">
            <?php if (!empty($errors['phone'])): ?><span class="form-error"><?= htmlspecialchars($errors['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Adres
            <input type="text" name="address" value="<?= $values['address'] ?>">
          </label>
          <label>
            Postcode
            <input type="text" name="postal_code" value="<?= $values['postal_code'] ?>">
          </label>
          <label>
            Plaats
            <input type="text" name="city" value="<?= $values['city'] ?>">
          </label>
        </div>
      </section>
      <section class="card__section">
        <h2>Apparaatgegevens</h2>
        <div class="form-grid">
          <label>
            Merk*
            <input type="text" name="device_brand" value="<?= $values['device_brand'] ?>" required>
            <?php if (!empty($errors['device_brand'])): ?><span class="form-error"><?= htmlspecialchars($errors['device_brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Model
            <input type="text" name="device_model" value="<?= $values['device_model'] ?>">
          </label>
          <label>
            Serienummer
            <input type="text" name="device_serial" value="<?= $values['device_serial'] ?>">
          </label>
          <label>
            Type apparaat
            <input type="text" name="device_type" value="<?= $values['device_type'] ?>" list="device-type-suggestions" placeholder="Bijv. Laptop">
            <?php if (!empty($errors['device_type'])): ?><span class="form-error"><?= htmlspecialchars($errors['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
        </div>
        <datalist id="device-type-suggestions">
          <option value="Desktop PC"></option>
          <option value="Laptop"></option>
          <option value="Workstation"></option>
          <option value="Server"></option>
          <option value="Smartphone"></option>
          <option value="Tablet"></option>
          <option value="Gameconsole"></option>
          <option value="Netwerkapparaat"></option>
        </datalist>
        <label>
          Interne notities
          <textarea name="device_notes" rows="3"><?= $values['device_notes'] ?></textarea>
        </label>
      </section>
      <section class="card__section">
        <h2>Foto&#39;s van het apparaat</h2>
        <p>Upload duidelijke foto&#39;s van alle zijden. Deze worden gebruikt om de staat van het apparaat vast te leggen.</p>
        <div class="photo-grid">
          <?php foreach ($orientations as $key => $label): ?>
            <label class="photo-field">
              <?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>*
              <input type="file" name="photo_<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" accept="image/jpeg,image/png" required>
              <?php if (!empty($errors['photo_' . $key])): ?><span class="form-error"><?= htmlspecialchars($errors['photo_' . $key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="card__section">
        <h2>Intake notities</h2>
        <textarea name="intake_notes" rows="4" placeholder="Beschrijf de klachten, zichtbare schade en bijzonderheden."><?= $values['intake_notes'] ?></textarea>
      </section>
      <div class="form-actions">
        <button type="submit" class="btn btn--primary">Apparaat registreren</button>
        <a href="devices.php" class="btn btn--ghost">Annuleren</a>
      </div>
    </form>
  </main>
</body>
</html>
<?php
/**
 * @param array<string, mixed>|null $file
 * @return array<string, string>
 */
function saveDevicePhoto(
    int $deviceId,
    string $orientation,
    string $label,
    ?array $file,
    DevicePhotoRepository $repository
): array {
    if ($file === null || !isset($file['error']) || (int) $file['error'] !== UPLOAD_ERR_OK) {
        throw new ValidationException(['photo_' . $orientation => 'Upload een geldige foto voor ' . $label . '.']);
    }

    $tmpPath = (string) ($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !is_uploaded_file($tmpPath)) {
        throw new ValidationException(['photo_' . $orientation => 'Bestand niet gevonden voor ' . $label . '.']);
    }

    $mime = mime_content_type($tmpPath) ?: '';
    $extension = match ($mime) {
        'image/jpeg', 'image/jpg' => 'jpg',
        'image/png' => 'png',
        default => null,
    };

    if ($extension === null) {
        throw new ValidationException(['photo_' . $orientation => 'Alleen JPG of PNG toegestaan voor ' . $label . '.']);
    }

    $directory = __DIR__ . '/storage/device-photos/' . $deviceId;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new ValidationException(['general' => 'Kan map voor foto\'s niet aanmaken.']);
    }

    $filename = $orientation . '-' . time() . '-' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $directory . '/' . $filename;

    if (!move_uploaded_file($tmpPath, $destination)) {
        throw new ValidationException(['photo_' . $orientation => 'Foto kon niet worden opgeslagen.']);
    }

    $relativePath = 'device-photos/' . $deviceId . '/' . $filename;
    $repository->store($deviceId, $orientation, $relativePath);

    return [
        'orientation' => $orientation,
        'path' => $relativePath,
    ];
}