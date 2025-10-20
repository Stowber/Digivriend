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
        $maxCharacters = 480;
        $problemSummary = mb_substr($problemDescription, 0, $maxCharacters);
        if (mb_strlen($problemDescription) > $maxCharacters) {
            $problemSummary = rtrim($problemSummary) . '…';
            $problemSummaryTruncated = true;
        }
    }

    $truncate = static function (string $value, int $limit): string {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $limit - 1)) . '…';
    };

    $displayFullName = $truncate($fullName, 90);
    $displayAddress = $truncate(htmlspecialchars_decode($formattedAddress, ENT_QUOTES), 160);
    $displayEmail = $truncate($email, 120);
    $displayPhone = $truncate($phone, 40);
    $displayDeviceInfo = $truncate($deviceInfo !== '' ? $deviceInfo : 'Onbekend apparaat', 140);
    $displayDeviceSerial = $truncate($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', 60);
    $logoSource = 'file://' . str_replace('\\', '/', __DIR__ . '/logo.svg');

    /* ===================== NOWY HTML/CSS ===================== */
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <title>Intake bevestiging <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
        <style>
          @page { size: A4; margin: 32pt 34pt 36pt; }
          * { box-sizing: border-box; }
          html, body { margin: 0; padding: 0; }
          body {
            font-family: DejaVu Sans, Arial, Helvetica, sans-serif;
            color: #111827;
            background: #E9EDF6
            font-size: 10pt;
            line-height: 1.48;
          }
          img { display: block; }

          h1, h2, h3, h4 { margin: 0; }
          h1 {
            font-size: 18pt;
            letter-spacing: -0.01em;
          }
          h2 {
            font-size: 11pt;
            text-transform: uppercase;
            letter-spacing: .22em;
            color: #6B7280;
          }
          h3 {
            font-size: 10pt;
            text-transform: uppercase;
            letter-spacing: .16em;
            color: #4B5563;
          }
          h4 {
            font-size: 9.5pt;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: #6B7280;
          }
          p { margin: 0; }
          .page {
            page-break-after: always;
          }
          .page:last-of-type { page-break-after: auto; }

          .page-surface {
            background: linear-gradient(180deg, #FFFFFF 0%, #FBFCFF 52%, #F3F5FB 100%);
            border-radius: 20pt;
            border: 0.75pt solid #D8DEF1;
            min-height: calc(842pt - 68pt);
            padding: 30pt 34pt;
            display: flex;
            flex-direction: column;
            position: relative;
            overflow: hidden;
          }
          .page-surface::after {
            content: '';
            position: absolute;
            inset: 32pt 28pt auto auto;
            width: 128pt;
            height: 128pt;
            background: radial-gradient(circle at top, rgba(240,90,40,0.18), rgba(240,90,40,0));
            z-index: 0;
          }

          .page-header, .page-footer, .page-body { position: relative; z-index: 1; }
          .page-body {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 18pt;
          }

          .brand {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18pt;
            border-bottom: 0.75pt solid #D9DFEE;
            padding-bottom: 14pt;
          }
          .brand-meta {
            text-align: right;
            color: #6B7280;
            font-size: 8.5pt;
            letter-spacing: .08em;
            text-transform: uppercase;
          }
          .logo {
            width: 124pt;
            height: auto;
          }

          .reference-card {
            margin-top: 12pt;
            display: inline-flex;
            flex-direction: column;
            align-items: flex-start;
            gap: 4pt;
            border-radius: 14pt;
            border: 0.75pt solid #FCD5C3;
            background: linear-gradient(135deg, #FFF3EA 0%, #FFE3D0 100%);
            padding: 12pt 18pt;
            color: #9A3412;
            letter-spacing: .12em;
            text-transform: uppercase;
          }
          .reference-value {
            font-size: 14pt;
            font-weight: 700;
            letter-spacing: .16em;
            color: #C2410C;
          }
          .tagline { color: #4B5563; max-width: 360pt; margin-top: 10pt; }

          .main-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16pt;
            align-content: stretch;
          }
          .panel {
            background: rgba(255,255,255,0.94);
            border-radius: 16pt;
            border: 0.75pt solid rgba(210, 216, 236, 0.9);
            padding: 16pt 20pt;
            display: flex;
            flex-direction: column;
            gap: 12pt;
            box-shadow: 0 14pt 36pt rgba(15, 23, 42, 0.08);
          }
          .panel-title {
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 10pt;
          }
          .panel-title span {
            font-size: 8pt;
            letter-spacing: .18em;
            text-transform: uppercase;
            color: #A855F7;
          }
          .panel-title strong {
            font-size: 12pt;
            letter-spacing: -0.01em;
            color: #0F172A;
          }
          .info-table {
            width: 100%;
            border-collapse: collapse;
          }
          .info-table tr + tr td { padding-top: 6pt; }
          .info-label {
            width: 110pt;
            font-size: 7.5pt;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #6B7280;
            vertical-align: top;
          }
          .info-value {
            font-size: 10pt;
            font-weight: 600;
            color: #111827;
            letter-spacing: -0.005em;
            word-break: break-word;
            hyphens: auto;
          }

          .highlight-box {
            background: #10131F;
            color: #F9FAFB;
            border-radius: 16pt;
            padding: 18pt 20pt;
            display: flex;
            justify-content: space-between;
            gap: 16pt;
            align-items: center;
          }
          .highlight-box small {
            font-size: 8pt;
            letter-spacing: .22em;
            text-transform: uppercase;
            color: rgba(255,255,255,0.72);
          }
          .highlight-box strong {
            font-size: 14pt;
            letter-spacing: .18em;
          }
          .barcode-shell {
            background: #fff;
            padding: 8pt 12pt;
            border-radius: 10pt;
            border: 0.5pt solid #CBD5F5;
          }
          .barcode {
            width: 140pt;
            max-width: 100%;
            height: auto;
          }

          .steps {
            display: flex;
            flex-direction: column;
            gap: 8pt;
            margin: 0;
            padding: 0;
            list-style: none;
          }
          .steps li {
            display: flex;
            gap: 10pt;
            align-items: flex-start;
            background: rgba(243, 244, 255, 0.9);
            border: 0.75pt solid rgba(195, 206, 255, 0.8);
            border-radius: 12pt;
            padding: 10pt 12pt;
          }
          .step-number {
            width: 26pt;
            height: 26pt;
            border-radius: 8pt;
            background: linear-gradient(135deg, #F97316, #EA580C);
            color: #fff;
            font-weight: 700;
            letter-spacing: .08em;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 9pt;
          }
          .step-content { font-size: 9.3pt; color: #1F2937; }

          .checklist {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 10pt 14pt;
          }
          .checklist-item {
            display: flex;
            gap: 8pt;
            align-items: flex-start;
            font-size: 9pt;
            color: #1F2937;
          }
          .checklist-bullet {
            width: 12pt;
            height: 12pt;
            border-radius: 4pt;
            background: #34D399;
          }

          .clamp-box {
            max-height: 110pt;
            overflow: hidden;
          }
          .note-box {
            margin-top: 8pt;
            border-radius: 10pt;
            background: #FEF3C7;
            border: 0.75pt solid #FBBF24;
            padding: 10pt 12pt;
            font-size: 8pt;
            color: #92400E;
          }

          .page-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 8pt;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #6B7280;
            padding-top: 12pt;
            border-top: 0.75pt solid #D9DFEE;
          }
          .terms-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16pt;
            align-content: stretch;
          }
          .terms-card {
            background: rgba(255,255,255,0.94);
            border-radius: 16pt;
            border: 0.75pt solid rgba(210,216,236,0.9);
            padding: 18pt 20pt;
            display: flex;
            flex-direction: column;
            gap: 10pt;
            box-shadow: 0 12pt 30pt rgba(15,23,42,0.06);
          }
          .terms-card ul {
            margin: 0;
            padding-left: 16pt;
            display: flex;
            flex-direction: column;
            gap: 6pt;
          }
          .terms-card li { font-size: 9pt; color: #1F2937; }

          .timeline {
            display: flex;
            flex-direction: column;
            gap: 8pt;
          }
          .timeline-step {
            display: flex;
            gap: 10pt;
            align-items: flex-start;
          }
          .timeline-step strong {
            min-width: 64pt;
            display: inline-block;
            font-size: 8.5pt;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: #2563EB;
          }
          .timeline-step span { font-size: 9pt; color: #1F2937; }

          .cost-table {
            width: 100%;
            border-collapse: collapse;
          }
          .cost-table th,
          .cost-table td {
            font-size: 9pt;
            padding: 6pt 0;
            border-bottom: 0.5pt solid #E5E7EB;
            text-align: left;
          }
          .cost-table th { text-transform: uppercase; letter-spacing: .12em; font-size: 7.5pt; color: #6B7280; }
          .cost-table td:last-child { text-align: right; font-weight: 600; }

          .signature-box {
            margin-top: auto;
            border-top: 0.75pt dashed #94A3B8;
            padding-top: 10pt;
            font-size: 8pt;
            color: #475569;
          }

          .text-muted { color: #6B7280; }
          .text-wrap { word-break: break-word; hyphens: auto; }
        </style>
      </head>
    <body>
      <div class="page">
        <div class="page-surface">
          <header class="page-header brand">
            <div>
              <img class="logo" src="<?= htmlspecialchars($logoSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Digivriend logo">
              <h2>Intake afspraak</h2>
              <h1 class="text-wrap">Intake voor <?= htmlspecialchars($displayFullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
              <p class="tagline">Bevestiging van uw geplande intakebezoek bij het servicepunt van Digivriend. Neem dit document mee als leidraad voor een vlotte afhandeling.</p>
              <div class="reference-card">
                <span>Referentie</span>
                <div class="reference-value"><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                <small>Gegenereerd op <?= date('d-m-Y H:i') ?></small>
              </div>
            </div>
          <div class="brand-meta">
              <div>Digivriend Amersfoort</div>
              <div>De Ganskuijl 103B · 3817 EZ Amersfoort</div>
              <div>KVK 82070741 · BTW NL003637003B84</div>
              <div>servicedesk@digivriend.nl · 033 - 785 4284</div>
            </div>
          </header>

          <main class="page-body">
            <div class="main-grid">
              <section class="panel">
                <div class="panel-title">
                  <span>Afspraak</span>
                  <strong><?= htmlspecialchars($appointmentAt->format('d F Y'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </div>
             <table class="info-table">
                  <tr>
                    <td class="info-label">Tijdslot</td>
                    <td class="info-value"><?= htmlspecialchars($appointmentAt->format('H:i') . ' - ' . $appointmentEnd->format('H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                  <tr>
                    <td class="info-label">Locatie</td>
                    <td class="info-value text-wrap">Servicebalie Digivriend, Stationsstraat 12, Utrecht</td>
                  </tr>
                  <tr>
                    <td class="info-label">Contact</td>
                    <td class="info-value text-wrap"><?= htmlspecialchars($displayEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br><?= htmlspecialchars($displayPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                  <tr>
                    <td class="info-label">Adres klant</td>
                    <td class="info-value text-wrap"><?= htmlspecialchars($displayAddress, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                </table>
              </section>

              <section class="panel">
                <div class="panel-title">
                  <span>Apparaat</span>
                  <strong><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Onbekend', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                </div>
              <table class="info-table">
                  <tr>
                    <td class="info-label">Details</td>
                    <td class="info-value text-wrap"><?= htmlspecialchars($displayDeviceInfo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                  <tr>
                    <td class="info-label">Serienummer</td>
                    <td class="info-value text-wrap"><?= htmlspecialchars($displayDeviceSerial, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                  <tr>
                    <td class="info-label">Gemeld door</td>
                    <td class="info-value text-wrap"><?= htmlspecialchars(Auth::username(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                  <tr>
                    <td class="info-label">Omschrijving</td>
                    <td class="info-value">
                      <?php if ($problemDescription !== ''): ?>
                        <div class="clamp-box text-wrap"><?= nl2br(htmlspecialchars($problemSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                        <?php if ($problemSummaryTruncated): ?>
                          <div class="note-box">De omschrijving is ingekort zodat de opmaak intact blijft. Volledige tekst beschikbaar in het dossier.</div>
                        <?php endif; ?>
                      <?php else: ?>
                        Geen omschrijving opgegeven.
                      <?php endif; ?>
                    </td>
                  </tr>
                </table>
              </section>
            </div>
          <section class="panel">
              <div class="panel-title">
                <span>Voorbereiding</span>
                <strong>Wat u meeneemt</strong>
              </div>
              <div class="checklist">
                <div class="checklist-item"><div class="checklist-bullet"></div><span>Dit intakeformulier of de referentiecode <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>.</span></div>
                <div class="checklist-item"><div class="checklist-bullet"></div><span>Het apparaat inclusief oplader en accessoires die nodig zijn voor testen.</span></div>
                <div class="checklist-item"><div class="checklist-bullet"></div><span>Eventuele wachtwoorden of inlogcodes die nodig zijn om het probleem te reproduceren.</span></div>
                <div class="checklist-item"><div class="checklist-bullet"></div><span>Bewijs van aankoop indien garantie of servicecontract van toepassing is.</span></div>
              </div>
            </section>

            <section class="panel">
              <div class="panel-title">
                <span>Aankomst</span>
                <strong>Stappen op locatie</strong>
              </div>
              <ul class="steps">
                <li>
                  <div class="step-number">01</div>
                  <div class="step-content">Meld u bij de ontvangstbalie en toon deze bevestiging zodat wij uw dossier direct kunnen openen.</div>
                </li>
                <li>
                  <div class="step-number">02</div>
                  <div class="step-content">Overhandig het apparaat met toebehoren. Onze technicus controleert ter plaatse de staat van het toestel.</div>
                </li>
                <li>
                  <div class="step-number">03</div>
                  <div class="step-content">U ontvangt een ontvangstbewijs en wij plannen direct het onderzoek of de reparatie in.</div>
                </li>
              </ul>
            </section>

            <div class="highlight-box">
              <div>
                <small>Toegangscode</small>
                <strong><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                <div class="text-muted">Gebruik deze code als u ons belt of wanneer u updates opvraagt.</div>
              </div>
              <div class="barcode-shell">
                <img class="barcode" src="<?= htmlspecialchars($barcodeDataUri, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="Barcode intake">
              </div>
            </div>
          </main>

          <footer class="page-footer">
            <span>Digivriend · Intake <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span>Pagina 1 van 2</span>
          </footer>
        </div>
      </div>

      <div class="page">
        <div class="page-surface">
          <header class="page-header brand">
            <div>
              <h2>Onderzoek en Reparatie</h2>
              <h1>Belangrijke afspraken</h1>
              <p class="tagline">Samenvatting van de meest relevante bepalingen uit de &ldquo;Algemene Voorwaarden Onderzoek en Reparatie&rdquo; die van toepassing zijn op uw intake.</p>
            </div>
            <div class="brand-meta">
              Referentie <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
              Klant <?= htmlspecialchars($displayFullName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
              Document aangemaakt <?= date('d-m-Y H:i') ?>
            </div>
          </header>

          <main class="page-body">
            <div class="terms-grid">
              <section class="terms-card">
                <h3>Onderzoeksvoorwaarden</h3>
                <ul>
                  <li>Voor het uitvoeren van een diagnose kunnen onderzoekskosten in rekening worden gebracht, ook wanneer u na het advies afziet van reparatie.</li>
                  <li>Wij starten het onderzoek pas na uw expliciete goedkeuring van de geraamde kosten en verwachte doorlooptijd.</li>
                  <li>Indien verborgen schade wordt aangetroffen, informeren wij u vooraf over aanvullende werkzaamheden of meerkosten.</li>
                </ul>
              </section>

              <section class="terms-card">
                <h3>Reparatie en garantie</h3>
                <ul>
                  <li>Gebruik van originele of gelijkwaardige onderdelen, met standaardgarantie van 3 maanden op de uitgevoerde reparatie.</li>
                  <li>Software- of dataproblemen vallen buiten hardwaregarantie; wij adviseren altijd een eigen back-up te maken.</li>
                  <li>Niet opgehaalde apparaten worden na 3 maanden opgeslagen en kunnen conform de voorwaarden worden afgevoerd.</li>
                </ul>
              </section>

              <section class="terms-card">
                <h3>Privacy en data</h3>
                <ul>
                  <li>Wij behandelen persoonlijke gegevens en opgeslagen data vertrouwelijk en uitsluitend voor onderzoek- en reparatiedoeleinden.</li>
                  <li>Maak bij voorkeur zelf een back-up; Digivriend is niet aansprakelijk voor dataverlies als gevolg van noodzakelijke tests.</li>
                  <li>Verwijder accounts en beveiligingscodes indien mogelijk of lever de toegangen beveiligd aan in overleg met onze technicus.</li>
                </ul>
              </section>
          <section class="terms-card">
                <h3>Communicatie</h3>
                <ul>
                  <li>Updates ontvangt u via e-mail of telefoon. Controleer regelmatig uw contactgegevens en spamfilter.</li>
                  <li>Wij bewaren een volledig logboek van uitgevoerde handelingen, meetwaarden en overlegmomenten in uw klantdossier.</li>
                  <li>Kiest u voor annulering nadat onderdelen zijn besteld, dan worden gemaakte kosten doorberekend.</li>
                </ul>
              </section>
            </div>

            <section class="terms-card">
              <h3>Procesoverzicht</h3>
              <div class="timeline">
                <div class="timeline-step"><strong>Intake</strong><span>Controle van apparatuur, registreren van accessoires en vastleggen van de klachtomschrijving.</span></div>
                <div class="timeline-step"><strong>Diagnose</strong><span>Technicus voert testen uit en rapporteert de resultaten inclusief een herstelvoorstel.</span></div>
                <div class="timeline-step"><strong>Goedkeuring</strong><span>U ontvangt een kostenraming. Werkzaamheden starten na schriftelijke of digitale bevestiging.</span></div>
                <div class="timeline-step"><strong>Reparatie</strong><span>Reparatie of dataherstel volgens planning. Eventuele afwijkingen worden direct afgestemd.</span></div>
                <div class="timeline-step"><strong>Afhalen</strong><span>Na afronding ontvangt u een melding. Neem bij afhalen dit document en de toegangscode mee.</span></div>
              </div>
            </section>

            <section class="terms-card">
              <h3>Kostenoverzicht</h3>
              <table class="cost-table">
                <tr>
                  <th>Post</th>
                  <th>Toelichting</th>
                  <th>Indicatie</th>
                </tr>
                <tr>
                  <td>Onderzoek</td>
                  <td>Eerste diagnose inclusief rapportage.</td>
                  <td>€ 45,00</td>
                </tr>
                <tr>
                  <td>Reparatie</td>
                  <td>Wordt bepaald na goedkeuring van het voorstel.</td>
                  <td>Op aanvraag</td>
                </tr>
                <tr>
                  <td>Data back-up</td>
                  <td>Optionele dienst, enkel bij akkoord klant.</td>
                  <td>Vanaf € 35,00</td>
                </tr>
                <tr>
                  <td>Opslagkosten</td>
                  <td>Bij niet-afhalen na 30 dagen na gereedmelding.</td>
                  <td>€ 2,50 p/dag</td>
                </tr>
              </table>
            <div class="note-box">De uiteindelijke factuur volgt na afronding. Alle bedragen zijn inclusief btw tenzij anders vermeld.</div>
            </section>

            <section class="terms-card">
              <h3>Ruimte voor aanvullende notities</h3>
              <p class="text-muted">Gebruik dit veld voor interne opmerkingen of specifieke klantafspraken.</p>
              <div class="signature-box">_______________________________<br>Handtekening medewerker · Datum</div>
            </section>
          </main>

          <footer class="page-footer">
            <span>Digivriend · Voorwaarden onderzoek &amp; reparatie</span>
            <span>Pagina 2 van 2</span>
          </footer>
        </div>
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
