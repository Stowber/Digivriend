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
          @page { size: A4; margin: 34pt 36pt 36pt; }
          * { box-sizing: border-box; }
          html, body { margin: 0; padding: 0; }
          body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            color: #10131F;
            background: #F3F5FA;
            font-size: 10pt;
            line-height: 1.5;
          }
          img { display: block; }

          h1, h2, h3 { margin: 0; }
          h1 {
            font-size: 17pt;
            color: #111827;
            letter-spacing: -0.01em;
            margin-bottom: 4pt;
          }
          .masthead-name {
            display: block;
          }
          h2 {
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: .16em;
            color: #6B7280;
          }
          h3 {
            font-size: 9pt;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: .12em;
            margin-bottom: 8pt;
          }
          p { margin: 0; }
          .muted { color: #6B7280; }
          .label { font-size: 7pt; letter-spacing: .18em; text-transform: uppercase; color: #F05A28; margin-bottom: 4pt; }
          .small { font-size: 8pt; }

          .sheet {
            background: #FFFFFF;
            border: 0.6pt solid #E5E7EF;
            border-radius: 16pt;
            padding: 26pt 28pt;
          }

          .masthead {
            width: 100%;
            border-bottom: 0.6pt solid #E5E7EF;
            padding-bottom: 16pt;
            margin-bottom: 18pt;
          }
          .masthead-table { width: 100%; border-collapse: collapse; }
          .masthead-left { width: 65%; vertical-align: top; }
          .masthead-right { width: 35%; vertical-align: top; text-align: right; }
          .logo { height: 28pt; width: auto; margin-bottom: 10pt; }
          .intro { color: #4B5563; max-width: 360pt; margin-top: 8pt; }

          .reference-card {
            display: inline-block;
            border-radius: 12pt;
            padding: 10pt 14pt;
            background: linear-gradient(135deg, #FFF4EC, #FFE6D7);
            border: 0.6pt solid #FCD5C3;
            min-width: 164pt;
          }
          .reference-value {
            font-size: 13pt;
            font-weight: 700;
            color: #D94817;
            letter-spacing: .1em;
            margin-top: 4pt;
          }
          .reference-meta { font-size: 8pt; color: #7C8799; margin-top: 4pt; }

          .highlight {
            border-radius: 12pt;
            border: 0.6pt solid #D1D5ED;
            background: #F7F9FF;
            padding: 14pt 16pt;
            margin-bottom: 18pt;
          }
          .highlight-title { font-size: 9pt; text-transform: uppercase; letter-spacing: .14em; color: #4C51BF; margin-bottom: 6pt; }
          .highlight-content { font-size: 10pt; color: #1F2937; }

          .section { margin-bottom: 18pt; }
          .grid { width: 100%; border-collapse: separate; border-spacing: 0 14pt; }
          .grid-col { width: 50%; padding-right: 14pt; vertical-align: top; }
          .grid-col:last-child { padding-right: 0; }

          .card {
            background: #FFFFFF;
            border: 0.6pt solid #E5E7EF;
            border-radius: 12pt;
            padding: 14pt 16pt;
            box-shadow: 0 6pt 14pt rgba(17, 24, 39, 0.06);
          }
          .card--compact { padding: 3.5pt 4pt; }
          .card--compact dl { margin-top: 2.5pt; }
          .card--compact .row { margin-top: 2pt; }
          .card--compact .row:first-child { margin-top: 0; }
          dl { margin: 10pt 0 0; }
          .row { display: table; width: 100%; margin-top: 8pt; }
          .row:first-child { margin-top: 0; }
          .dt {
            display: table-cell;
            width: 122pt;
            font-size: 8pt;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: #6B7280;
            vertical-align: top;
          }
          .dd { display: table-cell; color: #111827; font-weight: 600; }

          .device-meta { font-weight: 500; color: #1F2937; }
          .notes { margin-top: 8pt; color: #1F2937; }
          .note-box { margin-top: 8pt; border-radius: 10pt; background: #FFF7ED; padding: 10pt 12pt; color: #92400E; font-size: 8pt; }

          .steps {
            margin: 12pt 0 0;
            padding: 0;
            list-style: none;
            counter-reset: step;
          }
          .steps li {
            counter-increment: step;
            display: table;
            width: 100%;
            background: #FFFFFF;
            border: 0.6pt solid #E5E7EF;
            border-radius: 10pt;
            padding: 10pt 12pt;
            margin-top: 8pt;
          }
          .steps li:first-child { margin-top: 0; }
          .steps-number {
            display: table-cell;
            width: 26pt;
            height: 26pt;
            border-radius: 50%;
            background: #F05A28;
            color: #FFFFFF;
            font-weight: 700;
            text-align: center;
            vertical-align: middle;
            font-size: 9pt;
            line-height: 26pt;
          }
          .steps-content {
            display: table-cell;
            padding-left: 10pt;
            vertical-align: middle;
            color: #374151;
            font-size: 9pt;
          }

          .pass {
            border-radius: 14pt;
            background: #111827;
            color: #F9FAFB;
            padding: 14pt 18pt;
          }
          .pass-table { width: 100%; border-collapse: collapse; }
          .pass-code {
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: .16em;
            text-transform: uppercase;
            margin-top: 6pt;
          }
          .barcode-shell {
            display: inline-block;
            padding: 8pt;
            border-radius: 10pt;
            background: #FFFFFF;
          }
          .barcode { width: 160pt; max-width: 100%; height: auto; }

          footer {
            margin-top: 18pt;
            padding-top: 10pt;
            border-top: 0.6pt solid #E5E7EF;
            text-align: center;
            font-size: 8pt;
            color: #6B7280;
          }
        </style>
      </head>
      <body>
        <div class="sheet">
          <section class="masthead">
            <table class="masthead-table">
              <tr>
                <td class="masthead-left">
                  <img src="logo.png" alt="Digivriend logo" class="logo">
                  <div class="label">Intake bevestiging</div>
                  <h1>
                    Afspraak bevestigd voor
                    <span class="masthead-name"><?= htmlspecialchars($fullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </h1>
                  <p class="intro">
                    Neem dit document mee naar uw bezoek en houd de referentiecode gereed bij het servicepunt.
                  </p>
                </td>
                <td class="masthead-right">
                  <div class="reference-card">
                    <div class="label">Referentiecode</div>
                    <div class="reference-value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div class="reference-meta">Afspraak op <?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </div>
                </td>
              </tr>
            </table>
          </section>

          <section class="highlight">
            <div class="highlight-title">Belangrijke herinnering</div>
            <div class="highlight-content">
              Zorg dat het apparaat goed is verpakt en dat u indien mogelijk de voedingskabel meeneemt.
            </div>
          </section>

          <section class="section">
            <table class="grid">
              <tr>
                <td class="grid-col">
                  <div class="card card--compact">
                    <h3>Afspraakgegevens</h3>
                    <dl>
                      <div class="row">
                        <div class="dt">Datum &amp; tijd</div>
                        <div class="dd"><?= htmlspecialchars($formattedAppointment, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      </div>
                      <div class="row">
                        <div class="dt">Locatie</div>
                        <div class="dd">Digivriend Servicepunt</div>
                      </div>
                      <div class="row">
                        <div class="dt">Contactpersoon</div>
                        <div class="dd"><?= htmlspecialchars(Auth::username(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      </div>
                    </dl>
                  </div>
                </td>
                <td class="grid-col">
                  <div class="card card--compact">
                    <h3>Klantgegevens</h3>
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
          </section>

          <section class="section">
            <div class="card">
              <h3>Apparaatinformatie</h3>
              <dl>
                <div class="row">
                  <div class="dt">Type</div>
                  <div class="dd device-meta"><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Niet opgegeven', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
              <div class="row">
                  <div class="dt">Details</div>
                  <div class="dd device-meta"><?= htmlspecialchars($deviceInfo !== '' ? $deviceInfo : 'Onbekend apparaat', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
             <div class="row">
                  <div class="dt">Serienummer</div>
                  <div class="dd device-meta"><?= htmlspecialchars($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
              </dl>
            </div>
          </section>

          <?php if ($problemDescription !== ''): ?>
            <section class="section">
              <div class="card">
                <h3>Probleemomschrijving</h3>
                <div class="notes"><?= nl2br(htmlspecialchars($problemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                <?php if ($problemSummaryTruncated): ?>
                  <div class="note-box">De omschrijving is ingekort zodat het document op één pagina blijft.</div>
                <?php endif; ?>
              </div>
              </section>
          <?php endif; ?>

          <section class="section">
            <h3>Bij aankomst</h3>
            <ul class="steps">
              <li>
                <div class="steps-number">1</div>
                <div class="steps-content">Meld u bij de balie en toon deze intakebevestiging.</div>
              </li>
              <li>
                <div class="steps-number">2</div>
                <div class="steps-content">Overhandig het apparaat samen met accessoires en beschrijf het probleem kort.</div>
              </li>
              <li>
                <div class="steps-number">3</div>
                <div class="steps-content">Bewaar het ontvangstbewijs dat u na afgifte ontvangt.</div>
              </li>
            </ul>
          </section>

          <section class="section">
            <div class="pass">
              <table class="pass-table">
                <tr>
                  <td>
                    <div class="small">Toegangscode bij aankomst</div>
                    <div class="pass-code"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </td>
                  <td style="text-align: right;">
                    <div class="barcode-shell">
                      <img class="barcode" src="<?= htmlspecialchars($barcodeDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Barcode voor intake">
                    </div>
                  </td>
                </tr>
              </table>
            </div>
          </section>

        <footer>
            Bewaar dit document als bevestiging van uw intakeafspraak. Voor vragen kunt u contact opnemen via <?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> of telefonisch.
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
