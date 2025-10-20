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

    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
      <meta charset="UTF-8">
      <title>Intake bevestiging <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
      <style>
        * {
        box-sizing: border-box;
      }
      body {
        margin: 0;
        font-family: 'Inter', 'Segoe UI', Helvetica, Arial, sans-serif;
        background: #f3f4f6;
        color: #111827;
      }
      .sheet {
        max-width: 770px;
        margin: 0 auto;
        padding: 24px 28px;
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid rgba(17, 24, 39, 0.08);
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.12);
        display: flex;
        flex-direction: column;
        gap: 18px;
      }
      .sheet__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 18px;
        padding-bottom: 16px;
        border-bottom: 1px solid rgba(148, 163, 184, 0.35);
      }
      .brand {
        display: flex;
        gap: 16px;
        align-items: flex-start;
      }
      .brand__logo {
        width: 58px;
        height: auto;
      }
      .eyebrow {
        margin: 0;
        font-size: 6.2px;
        text-transform: uppercase;
        letter-spacing: 0.16em;
        color: #f05a28;
      }
      h1 {
        margin: 6px 0 8px;
        font-size: 18px;
        line-height: 1.25;
        color: #0f172a;
      }
      .intro {
        margin: 0;
        font-size: 7.5px;
        color: #475569;
        line-height: 1.55;
        max-width: 360px;
      }
      .reference-card {
        min-width: 180px;
        padding: 12px 14px;
        border-radius: 12px;
        background: linear-gradient(155deg, rgba(240, 90, 40, 0.1), rgba(240, 90, 40, 0));
        border: 1px solid rgba(240, 90, 40, 0.18);
        text-align: right;
      }
      .reference-card__label {
        display: block;
        font-size: 6px;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: #f05a28;
      }
      .reference-card__value {
        display: block;
        margin-top: 4px;
        font-size: 13px;
        font-weight: 700;
        letter-spacing: 0.08em;
        color: #ef4444;
      }
      .reference-card__hint {
        display: block;
        margin-top: 6px;
        font-size: 6.2px;
        color: #6b7280;
      }
      .summary {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 14px;
      }
      .summary--full {
        grid-template-columns: minmax(0, 1fr);
      }
      .card {
        border: 1px solid rgba(148, 163, 184, 0.35);
        border-radius: 12px;
        padding: 14px 16px;
        background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
        box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
        display: flex;
        flex-direction: column;
        gap: 10px;
      }
      .card__title {
        margin: 0;
        font-size: 7px;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: #64748b;
      }
      .definition-list {
        margin: 0;
        padding: 0;
        list-style: none;
        display: grid;
        gap: 8px;
      }
      .definition {
        display: grid;
        grid-template-columns: 120px 1fr;
        gap: 6px;
        align-items: start;
      }
      .definition dt {
        font-size: 7px;
        text-transform: uppercase;
        letter-spacing: 0.12em;
        color: #9ca3af;
        margin: 0;
      }
      .definition dd {
        margin: 0;
        font-size: 9px;
        font-weight: 600;
        color: #1f2937;
      }
      .stacked dt {
        grid-column: span 2;
        margin-bottom: 2px;
      }
      .stacked dd {
        grid-column: span 2;
      }
      .notes {
        font-size: 8.2px;
        color: #334155;
        line-height: 1.6;
        white-space: pre-wrap;
      }
      .muted {
        font-size: 6.4px;
        color: #94a3b8;
      }
      .access-pass {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 16px;
        padding: 12px 16px;
        border: 1px dashed rgba(240, 90, 40, 0.4);
        border-radius: 12px;
        background: rgba(240, 90, 40, 0.05);
      }
      .access-pass__label {
        margin: 0;
        font-size: 7px;
        text-transform: uppercase;
        letter-spacing: 0.18em;
        color: #f97316;
      }
      .access-pass__value {
        margin: 4px 0 0;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: 0.12em;
        color: #f05a28;
      }
      .access-pass img {
        max-width: 120px;
        height: auto;
      }
      footer {
        font-size: 6.4px;
        color: #94a3b8;
        line-height: 1.6;
        text-align: right;
      }
    </style>
  </head>
  <body>
    <div class="sheet">
      <header class="sheet__header">
        <div class="brand">
          <img src="logo.png" alt="Digivriend logo" class="brand__logo">
          <div>
            <p class="eyebrow">Intake bevestiging</p>
            <h1>Uw intake afspraak is bevestigd</h1>
            <p class="intro">Beste <?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>,<br>
              We kijken ernaar uit u te verwelkomen. Neem dit document mee naar het servicepunt en houd de referentiecode bij de hand.</p>
          </div>
        </div>
        <div class="reference-card">
          <span class="reference-card__label">Referentie</span>
          <span class="reference-card__value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="reference-card__hint">Afspraak op <?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </div>
      </header>

        <section class="summary">
        <article class="card">
          <h2 class="card__title">Afspraakgegevens</h2>
          <dl class="definition-list">
            <div class="definition">
              <dt>Datum &amp; tijd</dt>
              <dd><?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="definition">
              <dt>Locatie</dt>
              <dd>Digivriend Servicepunt</dd>
            </div>
            <div class="definition">
              <dt>Behandelaar</dt>
              <dd><?= htmlspecialchars(Auth::username(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          </dl>
        </article>

        <article class="card">
          <h2 class="card__title">Klantgegevens</h2>
          <dl class="definition-list">
            <div class="definition">
              <dt>Naam</dt>
              <dd><?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="definition stacked">
              <dt>Contact</dt>
              <dd><?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br><?= htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="definition stacked">
              <dt>Adres</dt>
              <dd><?= $formattedAddress ?></dd>
            </div>
          </dl>
        </article>
      </section>

      <section class="summary summary--full">
        <article class="card">
          <h2 class="card__title">Apparaatinformatie</h2>
          <dl class="definition-list">
            <div class="definition">
              <dt>Type</dt>
              <dd><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Niet opgegeven', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="definition stacked">
              <dt>Details</dt>
              <dd><?= htmlspecialchars($deviceInfo !== '' ? $deviceInfo : 'Onbekend apparaat', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
            <div class="definition">
              <dt>Serienummer</dt>
              <dd><?= htmlspecialchars($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
            </div>
          </dl>
        </article>
      </section>

      <?php if ($problemDescription !== ''): ?>
        <section class="summary summary--full">
          <article class="card">
            <h2 class="card__title">Probleemomschrijving</h2>
            <div class="notes"><?= nl2br(htmlspecialchars($problemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
            <?php if ($problemSummaryTruncated): ?>
              <p class="muted">De omschrijving is ingekort om het document op één pagina te houden.</p>
            <?php endif; ?>
          </article>
        </section>
      <?php endif; ?>

        <div class="access-pass">
        <div>
          <p class="access-pass__label">Toegangscode bij aankomst</p>
          <p class="access-pass__value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <img src="<?= htmlspecialchars($barcodeDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Barcode voor intake">
      </div>

      <footer>
          Bewaar dit document als bevestiging van uw intakeafspraak. Voor vragen kunt u contact opnemen met Digivriend via <?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> of telefonisch.
      </footer>
    </div>
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