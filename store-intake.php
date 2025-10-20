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

    $problemSummary = '';
    $problemSummaryTruncated = false;
    if ($problemDescription !== '') {
        $maxCharacters = 600;
        $problemSummary = mb_substr($problemDescription, 0, $maxCharacters);
        if (mb_strlen($problemDescription) > $maxCharacters) {
            $problemSummary = rtrim($problemSummary) . '…';
            $problemSummaryTruncated = true;
        }
    }

    /* ===================== NOWY HTML/CSS ===================== */
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
      <meta charset="UTF-8">
      <title>Intake bevestiging <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
      <style>
        /* STRONA */
        @page { size: A4; margin: 28pt 32pt 30pt; }
        * { box-sizing: border-box; }
        html, body { margin:0; padding:0; }
        body {
          font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
          color:#0B1220; background:#fff;
          line-height:1.45; font-size:10pt;
          word-wrap:break-word;
        }
        img { display:block; }

        /* TYPO */
        h1, h2 { margin:0; font-weight:700; color:#0B1220; }
        h1 { font-size:16pt; margin-bottom:2pt; }
        h2 { font-size:9pt; text-transform:uppercase; letter-spacing:.12em; color:#7A8798; }
        .eyebrow { font-size:7pt; text-transform:uppercase; letter-spacing:.18em; color:#F05A28; margin:0 0 4pt; }
        .muted { color:#6E7B8F; }
        .strong { font-weight:600; }

        /* LAYOUT */
        .container { }
        .header {
          display:table; width:100%;
          border-bottom:.6pt solid #E6EAF2;
          padding-bottom:10pt; margin-bottom:16pt;
        }
        .brand, .ref { display:table-cell; vertical-align:top; }
        .brand { width:68%; }
        .ref   { width:32%; text-align:right; }
        .logo { height:26pt; width:auto; margin-bottom:8pt; }
        .intro { margin-top:6pt; color:#3F4A5A; max-width:380pt; }

        .ref-badge {
          display:inline-block; text-align:right;
          padding:8pt 10pt; border:.6pt solid #F4C6B4; border-radius:8pt; background:#FFF6F1;
          min-width:160pt;
        }
        .ref-label { font-size:7pt; letter-spacing:.14em; text-transform:uppercase; color:#F05A28; display:block; }
        .ref-value { font-size:12pt; font-weight:700; letter-spacing:.08em; color:#D83F19; display:block; margin-top:2pt; }
        .ref-hint  { font-size:7pt; color:#6B7280; margin-top:2pt; display:block; }

        .section { margin-bottom:16pt; }
        .grid     { width:100%; border-collapse:separate; border-spacing:0 12pt; }
        .col      { width:50%; padding-right:10pt; }
        .col:last-child { padding-right:0; }

        .card {
          border:.6pt solid #E6EAF2; border-radius:10pt; padding:12pt 14pt; background:#FAFBFC;
        }
        dl { margin:0; }
        .row { display:table; width:100%; margin-top:7pt; }
        .row:first-child { margin-top:0; }
        .dt { display:table-cell; width:120pt; font-size:8pt; color:#7A8798; letter-spacing:.06em; text-transform:uppercase; vertical-align:top; }
        .dd { display:table-cell; color:#111827; font-weight:600; }

        .notes { margin-top:6pt; white-space:pre-wrap; }

        /* PASS / BARCODE */
        .pass {
          border:.6pt dashed #F05A28; border-radius:10pt; background:#FFF3EE;
          padding:12pt 14pt;
        }
        .pass-grid { display:table; width:100%; }
        .pass-left, .pass-right { display:table-cell; vertical-align:middle; }
        .pass-left  { width:65%; }
        .pass-right { width:35%; text-align:right; }
        .pass-label { font-size:8pt; text-transform:uppercase; letter-spacing:.14em; color:#E25A2D; margin:0; }
        .pass-value { margin:4pt 0 0; font-size:13pt; font-weight:700; letter-spacing:.12em; color:#F05A28; }
        .barcode-wrap { display:inline-block; padding:6pt; background:#fff; border:.6pt solid #F4C6B4; border-radius:6pt; }
        .barcode { max-width:150pt; height:auto; }

        /* STOPKA */
        footer { margin-top:16pt; border-top:.6pt solid #E6EAF2; padding-top:8pt; font-size:8pt; color:#6E7B8F; text-align:right; }
      </style>
    </head>
    <body>
      <div class="container">

        <!-- HEADER -->
        <div class="header">
          <div class="brand">
            <img src="logo.png" alt="Digivriend logo" class="logo">
            <p class="eyebrow">Intake bevestiging</p>
            <h1>Uw intake afspraak is bevestigd</h1>
            <p class="intro">
              Beste <?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>,
              neem dit document mee naar het servicepunt en houd de referentiecode bij de hand.
            </p>
          </div>
          <div class="ref">
            <span class="ref-badge">
              <span class="ref-label">Referentie</span>
              <span class="ref-value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <span class="ref-hint">Afspraak op <?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </span>
          </div>
        </div>

        <!-- 2-KOLUMNOWA SIATKA -->
        <div class="section">
          <table class="grid">
            <tr>
              <td class="col">
                <div class="card">
                  <h2>Afspraakgegevens</h2>
                  <dl>
                    <div class="row">
                      <div class="dt">Datum &amp; tijd</div>
                      <div class="dd"><?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                    <div class="row">
                      <div class="dt">Locatie</div>
                      <div class="dd strong">Digivriend Servicepunt</div>
                    </div>
                    <div class="row">
                      <div class="dt">Behandelaar</div>
                      <div class="dd"><?= htmlspecialchars(Auth::username(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                  </dl>
                </div>
              </td>
              <td class="col">
                <div class="card">
                  <h2>Klantgegevens</h2>
                  <dl>
                    <div class="row">
                      <div class="dt">Naam</div>
                      <div class="dd"><?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                    <div class="row">
                      <div class="dt">Contact</div>
                      <div class="dd"><?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br><?= htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    </div>
                    <div class="row">
                      <div class="dt">Adres</div>
                      <div class="dd"><?= $formattedAddress ?></div>
                    </div>
                  </dl>
                </div>
              </td>
            </tr>
          </table>
        </div>

        <!-- URZĄDZENIE -->
        <div class="section">
          <div class="card">
            <h2>Apparaatinformatie</h2>
            <dl>
              <div class="row">
                <div class="dt">Type</div>
                <div class="dd"><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Niet opgegeven', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <div class="row">
                <div class="dt">Details</div>
                <div class="dd"><?= htmlspecialchars($deviceInfo !== '' ? $deviceInfo : 'Onbekend apparaat', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <div class="row">
                <div class="dt">Serienummer</div>
                <div class="dd"><?= htmlspecialchars($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
            </dl>
          </div>
        </div>

        <!-- PROBLEM -->
        <?php if ($problemDescription !== ''): ?>
        <div class="section">
          <div class="card">
            <h2>Probleemomschrijving</h2>
            <div class="notes"><?= nl2br(htmlspecialchars($problemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
            <?php if ($problemSummaryTruncated): ?>
              <div class="muted" style="margin-top:6pt;">De omschrijving is ingekort om het document op één pagina te houden.</div>
            <?php endif; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- PASS / BARCODE -->
        <div class="section">
          <div class="pass">
            <div class="pass-grid">
              <div class="pass-left">
                <p class="pass-label">Toegangscode bij aankomst</p>
                <div class="pass-value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              </div>
              <div class="pass-right">
                <span class="barcode-wrap">
                  <img class="barcode" src="<?= htmlspecialchars($barcodeDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Barcode voor intake">
                </span>
              </div>
            </div>
          </div>
        </div>

        <footer>
          Bewaar dit document als bevestiging van uw intakeafspraak. Voor vragen kunt u contact opnemen met Digivriend via
          <?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> of telefonisch.
        </footer>
      </div>
    </body>
    </html>
    <?php
    $documentHtml = (string) ob_get_clean();

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isFontSubsettingEnabled', true);

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

/** Generuje unikalny kod referencyjny */
function generateIntakeReferenceCode(CaseRepository $caseRepository): string
{
    do {
        $reference = sprintf('IN-%s-%s', date('ymd'), strtoupper(bin2hex(random_bytes(2))));
        $existing = $caseRepository->findByReferenceCode($reference);
    } while ($existing !== null);

    return $reference;
}
