<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Barcode\BarcodeService;
use App\Support\Documents\DocumentRepository;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Validation\InputValidator;
use Dompdf\Dompdf;
use Dompdf\Options;

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Alleen POST-verzoeken zijn toegestaan.', 405);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
$rawInput = (string) file_get_contents('php://input');
$payload = [];

if (str_contains(strtolower($contentType), 'application/json')) {
    $decoded = json_decode($rawInput, true);
    if (is_array($decoded)) {
        $payload = $decoded;
    }
} else {
    $payload = $_POST;
}

if (!is_array($payload) || $payload === []) {
    Response::error('Ongeldige payload ontvangen.', 422);
}

if (!Csrf::validate((string) ($payload['csrf_token'] ?? ''))) {
    Response::error('Ongeldige sessie, vernieuw de pagina en probeer opnieuw.', 419);
}

try {
    $fullName = InputValidator::requireString($payload, 'full_name', 191);
    $email = InputValidator::requireEmail($payload, 'email', 191);
    $phone = InputValidator::requirePhone($payload, 'phone', 32);
    $address = InputValidator::requireString($payload, 'address', 255);
    $postalCode = InputValidator::requireString($payload, 'postal_code', 16);
    $city = InputValidator::requireString($payload, 'city', 120);
    $appointmentRaw = InputValidator::requireString($payload, 'appointment_at', 32);
    $deviceType = InputValidator::optionalString($payload, 'device_type', 120);
    $deviceBrand = InputValidator::optionalString($payload, 'device_brand', 120);
    $deviceModel = InputValidator::optionalString($payload, 'device_model', 191);
    $deviceSerial = InputValidator::optionalString($payload, 'device_serial', 120);
    $problemDescription = InputValidator::optionalString($payload, 'problem_description', 500);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$appointmentAt = date_create_immutable($appointmentRaw);
if (!$appointmentAt instanceof DateTimeImmutable) {
    Response::error(['appointment_at' => 'Ongeldige datum opgegeven.'], 422);
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$appointmentRepository = new AppointmentRepository($pdo);
$notificationService = new NotificationService($pdo);
$documentRepository = new DocumentRepository($pdo);

try {
    $pdo->beginTransaction();

    $customer = $customerRepository->upsert(
        $fullName,
        $email,
        $phone,
        $address,
        $postalCode,
        $city
    );

    $deviceId = null;
    $hasDeviceDetails = $deviceBrand !== '' || $deviceModel !== '' || $deviceSerial !== '' || $deviceType !== '';
    if ($hasDeviceDetails) {
        $device = $deviceRepository->findOrCreate(
            (int) $customer['id'],
            $deviceBrand !== '' ? $deviceBrand : null,
            $deviceModel !== '' ? $deviceModel : null,
            $deviceSerial !== '' ? $deviceSerial : null,
            $deviceType !== '' ? $deviceType : null,
            null
        );
        if ($device !== null) {
            $deviceId = (int) $device['id'];
        }
    }

    $referenceCode = generateIntakeReferenceCode($caseRepository);
    $barcodeValue = $referenceCode;

    $appointmentEnd = $appointmentAt->add(new DateInterval('PT30M'));
    $summary = $problemDescription !== '' ? $problemDescription : 'Intake bezoek voor ' . $fullName;

    $caseDetails = [
        'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
        'appointment_end' => $appointmentEnd->format('Y-m-d H:i:s'),
        'problem_description' => $problemDescription,
        'device_brand' => $deviceBrand,
        'device_model' => $deviceModel,
        'device_serial' => $deviceSerial,
        'device_type' => $deviceType,
        'barcode' => $barcodeValue,
        'registered_by' => Auth::username(),
    ];

    $case = $caseRepository->createOrUpdate(
        'intake',
        (int) $customer['id'],
        $deviceId,
        'gepland',
        $summary,
        $referenceCode,
        $caseDetails
    );

    $noteRepository->add(
        (int) $case['id'],
        (int) $customer['id'],
        Auth::username(),
        sprintf('Intake afspraak gepland voor %s.', $appointmentAt->format('d-m-Y H:i'))
    );

    if ($problemDescription !== '') {
        $noteRepository->add(
            (int) $case['id'],
            (int) $customer['id'],
            Auth::username(),
            'Probleembeschrijving: ' . $problemDescription
        );
    }

    $resourceLabelParts = array_filter([$deviceBrand, $deviceModel], static fn ($value) => $value !== '');
    $resources = [];
    if ($deviceType !== '' || $resourceLabelParts !== []) {
        $resources[] = [
            'type' => 'device',
            'label' => $deviceType !== '' ? $deviceType : 'Apparaat',
            'details' => $resourceLabelParts !== [] ? implode(' ', $resourceLabelParts) : null,
        ];
    }

    $appointmentRepository->create(
        'Intake bezoek ' . $fullName,
        'intake_visit',
        'scheduled',
        $appointmentAt->format('Y-m-d H:i:s'),
        $appointmentEnd->format('Y-m-d H:i:s'),
        (int) $case['id'],
        (int) $customer['id'],
        'Servicepunt',
        $problemDescription !== '' ? $problemDescription : null,
        '#F05A28',
        'email',
        'pending',
        null,
        Auth::username(),
        Auth::username(),
        [],
        $resources
    );

    $barcodeImage = BarcodeService::renderToPng($barcodeValue);
    $barcodeDataUri = 'data:image/png;base64,' . base64_encode($barcodeImage);

    $formattedAppointment = $appointmentAt->format('d-m-Y H:i');
    $formattedAddress = htmlspecialchars($address . ', ' . $postalCode . ' ' . $city, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $deviceInfo = trim(implode(' ', array_filter([$deviceBrand, $deviceModel, $deviceType])));

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
      <meta charset="UTF-8">
      <title>Intake bevestiging <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
      <style>
        body { font-family: "Helvetica Neue", Arial, sans-serif; color: #1f2937; margin: 0; padding: 32px; }
        h1 { color: #F05A28; }
        .section { margin-top: 24px; }
        .section h2 { font-size: 16px; text-transform: uppercase; letter-spacing: 0.12em; color: #6b7280; margin-bottom: 12px; }
        .panel { background: #f8fafc; border-radius: 8px; padding: 16px 20px; border: 1px solid rgba(15, 23, 42, 0.08); }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px 24px; }
        dt { font-size: 12px; text-transform: uppercase; color: #64748b; letter-spacing: 0.08em; margin-bottom: 4px; }
        dd { margin: 0; font-weight: 600; font-size: 15px; }
        .barcode { margin-top: 32px; text-align: center; }
        .barcode img { max-width: 320px; }
        footer { margin-top: 40px; font-size: 12px; color: #6b7280; }
      </style>
    </head>
    <body>
      <h1>Bevestiging intake afspraak</h1>
      <p>Beste <?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>,</p>
      <p>Bedankt voor het plannen van uw bezoek. Neem deze bevestiging en het apparaat mee naar ons servicepunt.</p>

      <div class="section">
        <h2>Afspraakgegevens</h2>
        <div class="panel grid">
          <div>
            <dt>Referentie</dt>
            <dd><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Datum &amp; tijd</dt>
            <dd><?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Locatie</dt>
            <dd>Digivriend Servicepunt</dd>
          </div>
        </div>
      </div>

      <div class="section">
        <h2>Klant</h2>
        <div class="panel grid">
          <div>
            <dt>Naam</dt>
            <dd><?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Contact</dt>
            <dd><?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br><?= htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Adres</dt>
            <dd><?= $formattedAddress ?></dd>
          </div>
        </div>
      </div>

      <div class="section">
        <h2>Apparaat</h2>
        <div class="panel grid">
          <div>
            <dt>Type</dt>
            <dd><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Niet opgegeven', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Details</dt>
            <dd><?= htmlspecialchars($deviceInfo !== '' ? $deviceInfo : 'Onbekend apparaat', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
          <div>
            <dt>Serienummer</dt>
            <dd><?= htmlspecialchars($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        </div>
      </div>

      <?php if ($problemDescription !== ''): ?>
        <div class="section">
          <h2>Probleemomschrijving</h2>
          <div class="panel">
            <?= nl2br(htmlspecialchars($problemDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="barcode">
        <p>Toegangscode bij aankomst:</p>
        <img src="<?= htmlspecialchars($barcodeDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Barcode voor intake">
        <p><strong><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
      </div>

      <footer>
        Deze bevestiging bevat de toestemmingsgegevens voor het onderzoek en de reparatie. Neem voor vragen contact op met Digivriend.
      </footer>
    </body>
    </html>
    <?php
    $documentHtml = (string) ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($documentHtml, 'UTF-8');
    $dompdf->setPaper('A4');
    $dompdf->render();
    $pdfContents = $dompdf->output();

    $intakeDirectory = __DIR__ . '/storage/documents/intake';
    if (!is_dir($intakeDirectory)) {
        mkdir($intakeDirectory, 0775, true);
    }

    $pdfFilename = sprintf('intake-%s-%s.pdf', strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $referenceCode)), date('YmdHis'));
    $pdfPath = $intakeDirectory . '/' . $pdfFilename;
    file_put_contents($pdfPath, $pdfContents);

    $documentRepository->store(
        (int) $case['id'],
        'intake_confirmation',
        'storage/documents/intake/' . $pdfFilename,
        [
            'reference_code' => $referenceCode,
            'appointment_at' => $appointmentAt->format(DateTimeImmutable::ATOM),
        ]
    );

    $pdo->commit();
} catch (ValidationException $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::error($exception->errors(), 422);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    Response::error('Er is een fout opgetreden tijdens het opslaan van de intake.', 500);
}

$emailStatus = null;
$emailError = null;

try {
    $notificationService->sendIntakeConfirmation(
        (int) $case['id'],
        (int) $customer['id'],
        $email,
        [
            'customer_name' => $fullName,
            'appointment_at' => $appointmentAt,
            'location' => 'Digivriend Servicepunt',
            'reference_code' => $referenceCode,
            'notes' => $problemDescription,
            'subject' => 'Bevestiging intake afspraak ' . $referenceCode,
        ],
        [
            [
                'content' => $pdfContents,
                'name' => sprintf('intake-%s.pdf', $referenceCode),
                'mime' => 'application/pdf',
            ],
        ]
    );
    $emailStatus = 'sent';
} catch (Throwable $notificationException) {
    $emailStatus = 'failed';
    $emailError = $notificationException->getMessage();
    error_log('[Intake] E-mail verzenden mislukt: ' . $notificationException->getMessage());
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'case_id' => (int) $case['id'],
    'reference_code' => $referenceCode,
    'appointment_at' => $appointmentAt->format('Y-m-d H:i:s'),
    'appointment_at_formatted' => $appointmentAt->format('d-m-Y H:i'),
    'case_url' => 'case.php?id=' . (int) $case['id'],
    'pdf_url' => 'storage/documents/intake/' . $pdfFilename,
    'devices_url' => 'devices.php?highlight=' . urlencode($referenceCode),
    'notification_status' => $emailStatus,
    'notification_error' => $emailError,
], JSON_THROW_ON_ERROR);

function generateIntakeReferenceCode(CaseRepository $caseRepository): string
{
    do {
        $reference = sprintf('IN-%s-%s', date('ymd'), strtoupper(bin2hex(random_bytes(2))));
        $existing = $caseRepository->findByReferenceCode($reference);
    } while ($existing !== null);

    return $reference;
}