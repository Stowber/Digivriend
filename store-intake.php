<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Barcode\BarcodeService;
use App\Support\Documents\DocumentRepository;
use App\Support\Env;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Validation\InputValidator;

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
    $customerId = (int) filter_var($payload['customer_id'] ?? null, FILTER_VALIDATE_INT);
    if ($customerId <= 0) {
        throw new ValidationException(['customer_id' => __('intake.form.customer.errors.required')]);
    }
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

/**
 * Centralny, spójny blok informacji o firmie/serwisie na potrzeby PDF i e-maila.
 * W razie potrzeby można przenieść do .env / bazy i wczytywać dynamicznie.
 */
$company = [
    'name'       => 'Digivriend',
    'branch'     => 'Digivriend Amersfoort',
    'street'     => 'De Ganskuijl 103B',
    'postcode'   => '3817 EZ',
    'city'       => 'Amersfoort',
    'kvk'        => '82070741',
    'btw'        => 'NL003637003B84',
    'email'      => 'servicedesk@digivriend.nl',
    'phone'      => '033 - 785 4284',
];
$companyAddressLine = sprintf('%s · %s %s', $company['street'], $company['postcode'], $company['city']);
$serviceLocationLine = $company['branch'] . ', ' . $companyAddressLine;

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$appointmentRepository = new AppointmentRepository($pdo);
$notificationService = new NotificationService($pdo);
$documentRepository = new DocumentRepository($pdo);

$customer = $customerRepository->findById($customerId ?? 0);
if ($customer === null) {
    Response::error(['customer_id' => __('intake.form.customer.errors.not_found')], 404);
}

$customerName = (string) ($customer['full_name'] ?? 'Onbekende klant');
$customerEmail = (string) ($customer['email'] ?? '');
$customerPhone = (string) ($customer['phone'] ?? '');
$customerAddress = (string) ($customer['address'] ?? '');
$customerPostalCode = (string) ($customer['postal_code'] ?? '');
$customerCity = (string) ($customer['city'] ?? '');

try {
    $pdo->beginTransaction();

    $customerRepository->touch((int) $customer['id']);

    $deviceId = null;
    $hasDeviceDetails = $deviceBrand !== '' || $deviceModel !== '' || $deviceSerial !== '' || $deviceType !== '';
    if ($hasDeviceDetails) {
        $device = $deviceRepository->findOrCreate(
            (int) $customer['id'],
            $deviceBrand !== '' ? $deviceBrand : null,
            $deviceModel !== '' ? $deviceModel : null,
            $deviceSerial !== '' ? $deviceSerial : null,
            $deviceType  !== '' ? $deviceType  : null,
            null
        );
        if ($device !== null) {
            $deviceId = (int) $device['id'];
        }
    }

    $referenceCode = generateIntakeReferenceCode($caseRepository);
    $barcodeValue  = $referenceCode;

    $appointmentEnd = $appointmentAt->add(new DateInterval('PT30M'));
    $summary = $problemDescription !== '' ? $problemDescription : 'Intake bezoek voor ' . $customerName;

    $caseDetails = [
        'appointment_at'       => $appointmentAt->format('Y-m-d H:i:s'),
        'appointment_end'      => $appointmentEnd->format('Y-m-d H:i:s'),
        'problem_description'  => $problemDescription,
        'device_brand'         => $deviceBrand,
        'device_model'         => $deviceModel,
        'device_serial'        => $deviceSerial,
        'device_type'          => $deviceType,
        'barcode'              => $barcodeValue,
        'registered_by'        => Auth::username(),
        'company_branch'       => $company['branch'],
        'company_address_line' => $companyAddressLine,
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

    $resourceLabelParts = array_filter([$deviceBrand, $deviceModel], static fn ($v) => $v !== '');
    $resources = [];
    if ($deviceType !== '' || $resourceLabelParts !== []) {
        $resources[] = [
            'type'    => 'device',
            'label'   => $deviceType !== '' ? $deviceType : 'Apparaat',
            'details' => $resourceLabelParts !== [] ? implode(' ', $resourceLabelParts) : null,
        ];
    }

    $appointmentRepository->create(
        'Intake bezoek ' . $customerName,
        'intake_visit',
        'scheduled',
        $appointmentAt->format('Y-m-d H:i:s'),
        $appointmentEnd->format('Y-m-d H:i:s'),
        (int) $case['id'],
        (int) $customer['id'],
        $company['branch'],
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

    $barcodeImage  = BarcodeService::renderToPng($barcodeValue);
    $barcodeDataUri = 'data:image/png;base64,' . base64_encode($barcodeImage);

    // NL lokalizacja daty (np. "20 oktober 2025")
    $fmt = new \IntlDateFormatter(
        'nl_NL',
        \IntlDateFormatter::LONG,
        \IntlDateFormatter::NONE,
        $appointmentAt->getTimezone()->getName(),
        \IntlDateFormatter::GREGORIAN,
        'd MMMM y'
    );
    $appointmentDateNl = $fmt->format($appointmentAt);

    $formattedAppointment = $appointmentAt->format('d-m-Y H:i');
    $addressSegments = [];
    if ($customerAddress !== '') {
        $addressSegments[] = $customerAddress;
    }
    $cityParts = array_filter([$customerPostalCode, $customerCity], static fn ($value) => $value !== '');
    if ($cityParts !== []) {
        $addressSegments[] = implode(' ', $cityParts);
    }
    $formattedAddress = htmlspecialchars(implode(', ', $addressSegments), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    // UWAGA (naprawa dublowania typu): w "Details" pokazujemy tylko marka + model.
    $deviceDetails = trim(implode(' ', array_filter([$deviceBrand, $deviceModel])));

    // Podsumowanie opisu z bezpiecznym obcięciem
    $problemSummary = '';
    $problemSummaryTruncated = false;
    if ($problemDescription !== '') {
        $maxCharacters = 180;
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

    $displayFullName     = $truncate($customerName, 90);
    $displayAddress      = $truncate(htmlspecialchars_decode($formattedAddress, ENT_QUOTES), 160);
    $displayEmail        = $truncate($customerEmail !== '' ? $customerEmail : '—', 120);
    $displayPhone        = $truncate($customerPhone !== '' ? $customerPhone : '—', 40);
    $displayDeviceInfo   = $truncate($deviceDetails !== '' ? $deviceDetails : 'Onbekend apparaat', 140);
    $displayDeviceSerial = $truncate($deviceSerial !== '' ? $deviceSerial : 'Niet beschikbaar', 60);

    // Ścieżka do logo — dla Chrome headless zostawiamy file://
    $logoSource = 'file://' . str_replace('\\', '/', __DIR__ . '/logo.svg');

    /* ===================== HTML/CSS dla Chromium ===================== */
    ob_start();
    ?>
    <!DOCTYPE html>
    <html lang="nl">
    <head>
        <meta charset="UTF-8">
        <title>Intake bevestiging <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
        <base href="file://<?= str_replace('\\', '/', __DIR__) ?>/">
        <style>
          @page { size: A4; margin: 24pt 28pt 28pt; }
          * { box-sizing: border-box; }
          html, body { margin: 0; padding: 0; }
          :root {
            --color-ink: #111827;
            --color-muted: #6B7280;
            --color-accent: #1E3A8A;
            --color-accent-light: #DBEAFE;
            --color-surface: rgba(255,255,255,0.96);
            --color-border: rgba(210, 216, 236, 0.9);
            --color-page: linear-gradient(180deg, #FFFFFF 0%, #F7F9FE 56%, #EEF2FB 100%);
            --radius-large: 20pt;
            --radius-medium: 16pt;
            --shadow-elevated: 0 14pt 34pt rgba(15, 23, 42, 0.06);
          }
          body {
            font-family: 'Segoe UI', 'Helvetica Neue', Arial, sans-serif;
            color: var(--color-ink);
            background: #E6EBF6;
            font-size: 10pt;
            line-height: 1.48;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
          }
          img { display: block; max-width: 100%; }

          h1, h2, h3, h4 { margin: 0; }
          h1 { font-size: 18pt; letter-spacing: -0.01em; }
          /* Delikatniejsze rozstrzelenie dla lepszej czytelności w PDF */
          h2 { font-size: 11pt; text-transform: uppercase; letter-spacing: .12em; color: #6B7280; }
          h3 { font-size: 10pt; text-transform: uppercase; letter-spacing: .12em; color: #4B5563; }
          h4 { font-size: 9.5pt; letter-spacing: .08em; text-transform: uppercase; color: #6B7280; }
          p { margin: 0; }

          .page { page-break-after: always; }
          .page:last-of-type { page-break-after: auto; }

          .page-surface {
            background: var(--color-page);
            border-radius: var(--radius-large);
            border: 0.75pt solid #D8DEF1;
            min-height: calc(842pt - 52pt);
            padding: 26pt 30pt 28pt;
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
            gap: 14pt;
          }

          .brand {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 18pt;
            border-bottom: 0.75pt solid #D9DFEE;
            padding-bottom: 16pt;
          }
          .brand-meta {
            text-align: right;
            color: var(--color-muted);
            font-size: 8.5pt;
            letter-spacing: .08em;
            text-transform: uppercase;
            line-height: 1.6;
            max-width: 196pt;
          }
          .logo { width: 124pt; height: auto; filter: drop-shadow(0 8pt 18pt rgba(15,23,42,0.08)); }
          .tagline { color: #4B5563; max-width: 360pt; margin-top: 9pt; }

          .main-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16pt;
            align-content: stretch;
          }
          .panel {
            background: var(--color-surface);
            border-radius: var(--radius-medium);
            border: 0.75pt solid var(--color-border);
            padding: 18pt 22pt;
            display: flex;
            flex-direction: column;
            gap: 12pt;
            box-shadow: var(--shadow-elevated);
          }
          .panel--summary { padding: 0; overflow: hidden; }
          .panel-summary-header {
            background: linear-gradient(135deg, #EEF2FF 0%, #DBEAFE 90%);
            padding: 20pt 24pt 16pt;
            display: flex;
            flex-direction: column;
            gap: 8pt;
            border-bottom: 0.75pt solid rgba(210, 216, 236, 0.9);
          }
          .panel-summary-header span {
            font-size: 7.5pt; letter-spacing: .18em; text-transform: uppercase; color: #1D4ED8;
          }
          .panel-summary-header strong,
          .panel-summary-value { font-size: 14pt; font-weight: 700; letter-spacing: -0.01em; color: #1E3A8A; }
          .panel-summary-body { padding: 20pt 24pt; display: flex; flex-direction: column; gap: 12pt; }
          .panel--summary .info-label { letter-spacing: .12em; color: #60708E; font-size: 7.5pt; }
          .panel--summary .info-value { font-size: 10pt; font-weight: 600; color: #0F172A; }
          .panel-title { display: flex; justify-content: space-between; align-items: baseline; gap: 10pt; }
          .panel-title span { font-size: 8pt; letter-spacing: .14em; text-transform: uppercase; color: #A855F7; }
          .panel-title strong { font-size: 12pt; letter-spacing: -0.01em; color: #0F172A; }

          .info-table { width: 100%; border-collapse: collapse; }
          .info-table tr + tr td { padding-top: 6pt; }
          .info-label {
            width: 110pt; font-size: 7.5pt; letter-spacing: .12em; text-transform: uppercase; color: #6B7280; vertical-align: top;
          }
          .info-value {
            font-size: 10pt; font-weight: 600; color: #111827; letter-spacing: -0.005em; word-break: break-word; hyphens: auto;
          }

          .highlight-box {
            background: linear-gradient(135deg, #1E3A8A 0%, #0F172A 100%);
            color: #F9FAFB;
            border-radius: var(--radius-medium);
            padding: 18pt 22pt;
            display: flex;
            flex-direction: column;
            gap: 14pt;
            align-items: stretch;
            grid-column: 1 / -1;
            box-shadow: 0 18pt 34pt rgba(15, 23, 42, 0.22);
          }
          .highlight-box small { font-size: 8pt; letter-spacing: .18em; text-transform: uppercase; color: rgba(255,255,255,0.82); }
          .highlight-box strong { font-size: 14pt; letter-spacing: .14em; }
          .barcode-shell { background: rgba(255,255,255,0.1); padding: 14pt; border-radius: 12pt; border: 0.5pt solid rgba(255,255,255,0.35); }
          .barcode { width: 100%; height: auto; max-height: 120pt; object-fit: contain; }

          .steps { display: flex; flex-direction: column; gap: 10pt; margin: 0; padding: 0; list-style: none; }
          .steps li {
            display: flex; gap: 10pt; align-items: flex-start; background: rgba(255,255,255,0.94);
            border: 0.75pt solid rgba(210,216,236,0.9); border-radius: 12pt; padding: 12pt 14pt; box-shadow: 0 8pt 22pt rgba(15,23,42,0.05);
          }
          .step-number {
            width: 26pt; height: 26pt; border-radius: 8pt; background: linear-gradient(135deg, #F97316, #EA580C);
            color: #fff; font-weight: 700; letter-spacing: .08em; display: flex; align-items: center; justify-content: center; font-size: 9pt;
          }
          .step-content { font-size: 9.3pt; color: #1F2937; }

          .checklist { display: flex; flex-direction: column; gap: 10pt; }
          .checklist-item {
            display: flex; gap: 10pt; align-items: flex-start; font-size: 9pt; color: #1F2937; background: rgba(255,255,255,0.94);
            border: 0.75pt solid rgba(210,216,236,0.9); border-radius: 12pt; padding: 11pt 14pt; box-shadow: 0 10pt 22pt rgba(15,23,42,0.05);
            line-height: 1.55;
          }
          .checklist-bullet { width: 12pt; height: 12pt; border-radius: 4pt; background: #34D399; margin-top: 4pt; flex-shrink: 0; box-shadow: 0 4pt 10pt rgba(16,185,129,0.25); }

          .clamp-box { max-height: 110pt; overflow: hidden; }
          .note-box { margin-top: 8pt; border-radius: 10pt; background: #FEF3C7; border: 0.75pt solid #FBBF24; padding: 10pt 12pt; font-size: 8pt; color: #92400E; line-height: 1.4; }

          .page-footer {
            display: flex; justify-content: space-between; align-items: center; font-size: 8pt; letter-spacing: .12em;
            text-transform: uppercase; color: #6B7280; padding-top: 12pt; border-top: 0.75pt solid #D9DFEE;
          }

          .terms-grid {
            flex: 1;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18pt;
            align-content: stretch;
          }
          .terms-card {
            background: var(--color-surface);
            border-radius: var(--radius-medium);
            border: 0.75pt solid var(--color-border);
            padding: 20pt 22pt;
            display: flex;
            flex-direction: column;
            gap: 10pt;
            box-shadow: var(--shadow-elevated);
          }
          .terms-card ul { margin: 0; padding-left: 16pt; display: flex; flex-direction: column; gap: 6pt; }
          .terms-card li { font-size: 9pt; color: #1F2937; }

          .timeline { display: flex; flex-direction: column; gap: 8pt; }
          .timeline-step { display: flex; gap: 10pt; align-items: flex-start; line-height: 1.52; }
          .timeline-step strong {
            min-width: 64pt; display: inline-block; font-size: 8.5pt; letter-spacing: .12em; text-transform: uppercase; color: #2563EB;
          }
          .timeline-step span { font-size: 9pt; color: #1F2937; }

          .cost-table { width: 100%; border-collapse: collapse; }
          .cost-table th, .cost-table td { font-size: 9pt; padding: 6pt 0; border-bottom: 0.5pt solid #E5E7EB; text-align: left; }
          .cost-table th { text-transform: uppercase; letter-spacing: .12em; font-size: 7.5pt; color: var(--color-muted); }
          .cost-table .cost-value { text-align: right; font-weight: 600; width: 92pt; }

          .signature-box { margin-top: auto; border-top: 0.75pt dashed #94A3B8; padding-top: 10pt; font-size: 8pt; color: #475569; }

          .text-muted { color: var(--color-muted); }
          .text-wrap { word-break: break-word; hyphens: auto; }

          .secondary-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16pt;
          }
          .secondary-grid .panel { height: 100%; }
          .secondary-grid .steps { margin-top: 4pt; }
          .secondary-grid .checklist { margin-top: 4pt; }
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
            </div>
            <div class="brand-meta">
              <div><?= htmlspecialchars($company['branch'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><?= htmlspecialchars($companyAddressLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div>KVK <?= htmlspecialchars($company['kvk'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · BTW <?= htmlspecialchars($company['btw'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <div><?= htmlspecialchars($company['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars($company['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
            </div>
          </header>

          <main class="page-body">
            <div class="main-grid">
              <section class="panel panel--summary">
                <div class="panel-summary-header">
                  <span>Afspraak</span>
                  <div class="panel-summary-value"><?= htmlspecialchars($appointmentDateNl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="panel-summary-body">
                  <table class="info-table">
                    <tr>
                      <td class="info-label">Tijdslot</td>
                      <td class="info-value"><?= htmlspecialchars($appointmentAt->format('H:i') . ' - ' . $appointmentEnd->format('H:i'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                    <tr>
                      <td class="info-label">Locatie</td>
                      <td class="info-value text-wrap"><?= htmlspecialchars($serviceLocationLine, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
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
                </div>
              </section>

              <section class="panel panel--summary">
                <div class="panel-summary-header">
                  <span>Apparaat</span>
                  <div class="panel-summary-value"><?= htmlspecialchars($deviceType !== '' ? $deviceType : 'Onbekend', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                </div>
                <div class="panel-summary-body">
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
                </div>
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
            </div>

            <div class="secondary-grid">
              <section class="panel">
                <div class="panel-title">
                  <span>Voorbereiding</span>
                  <strong>Wat u meeneemt</strong>
                </div>
                <div class="checklist">
                  <div class="checklist-item"><div class="checklist-bullet"></div><span>Neem het apparaat zonder losse accessoires of randapparatuur mee; alleen essentiële onderdelen zoals de originele adapter wanneer wij deze expliciet nodig hebben.</span></div>
                  <div class="checklist-item"><div class="checklist-bullet"></div><span>Maak vooraf een volledige back-up van uw gegevens. Digivriend is niet aansprakelijk voor dataverlies tijdens onderzoek of reparatie.</span></div>
                  <div class="checklist-item"><div class="checklist-bullet"></div><span>Voorzie ons van de toegangscodes of testgegevens die nodig zijn om de gemelde storing te verifiëren.</span></div>
                  <div class="checklist-item"><div class="checklist-bullet"></div><span>Wilt u dat wij een back-up uitvoeren of andere aanvullende diensten leveren? Meld dit bij aankomst zodat we het kunnen registreren.</span></div>
                </div>
              </section>

              <section class="panel">
                <div class="panel-title">
                  <span>Aankomst</span>
                  <strong>Stappen op locatie</strong>
                </div>
                <ul class="steps">
                  <li><div class="step-number">01</div><div class="step-content">Lever het apparaat zonder losse accessoires aan; samen registreren we de staat en eventuele aanwezige toebehoren.</div></li>
                  <li><div class="step-number">02</div><div class="step-content">We lopen de intake en de algemene voorwaarden door, vragen uw expliciete toestemming voor onderzoek of reparatie en noteren eventuele back-upverzoeken.</div></li>
                  <li><div class="step-number">03</div><div class="step-content">U ontvangt een ontvangstbewijs met informatie over de vervolgstappen en de termijn waarbinnen het apparaat moet worden opgehaald om opslagkosten te voorkomen.</div></li>
                </ul>
              </section>
            </div>
          </main>

          <footer class="page-footer">
            <span>Digivriend · Intake <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span><!-- Chromium page counter left intentionally blank --></span>
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
                  <li>Niet opgehaalde apparaten leveren na 30 dagen opslagkosten op (€ 2,50 p/dag) en kunnen na 3 maanden conform de voorwaarden worden afgevoerd.</li>
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
                  <th class="cost-value">Indicatie</th>
                </tr>
                <tr>
                  <td>Onderzoek</td>
                  <td>Eerste diagnose inclusief rapportage.</td>
                  <td class="cost-value">€ 49,95</td>
                </tr>
                <tr>
                  <td>Reparatie</td>
                  <td>Wordt bepaald na goedkeuring van het voorstel.</td>
                  <td>Op aanvraag</td>
                </tr>
                <tr>
                  <td>Data back-up</td>
                  <td>Optionele dienst, enkel bij akkoord klant.</td>
                  <td class="cost-value">Vanaf € 35,00</td>
                </tr>
                <tr>
                  <td>Opslagkosten</td>
                  <td>Bij niet-afhalen na 30 dagen na gereedmelding.</td>
                  <td class="cost-value">€ 2,50 p/dag</td>
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
            <span><!-- Chromium page counter left intentionally blank --></span>
          </footer>
        </div>
      </div>
    </body>
    </html>
    <?php
    $documentHtml = (string) ob_get_clean();

    // ===== Render PDF w Headless Chromium =====
    $intakeDirectory = __DIR__ . '/storage/documents/intake';
    if (!is_dir($intakeDirectory)) {
        mkdir($intakeDirectory, 0775, true);
    }

    $pdfFilename = sprintf('intake-%s-%s.pdf', strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $referenceCode)), date('YmdHis'));
    $pdfPath = $intakeDirectory . '/' . $pdfFilename;

    $pdfContents = renderPdfWithChromium($documentHtml, $pdfPath);

    if ($pdfContents === null) {
        throw new RuntimeException('Kon het PDF-bestand niet genereren met Chromium.');
    }

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
        $customerEmail,
        [
            'customer_name'  => $customerName,
            'appointment_at' => $appointmentAt,
            'location'       => $company['branch'],
            'reference_code' => $referenceCode,
            'notes'          => $problemDescription,
            'subject'        => 'Bevestiging intake afspraak ' . $referenceCode,
        ],
        [
            [
                'content' => $pdfContents,
                'name'    => sprintf('intake-%s.pdf', $referenceCode),
                'mime'    => 'application/pdf',
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
    'success'                    => true,
    'case_id'                    => (int) $case['id'],
    'reference_code'             => $referenceCode,
    'appointment_at'             => $appointmentAt->format('Y-m-d H:i:s'),
    'appointment_at_formatted'   => $appointmentAt->format('d-m-Y H:i'),
    'case_url'                   => 'case.php?id=' . (int) $case['id'],
    'pdf_url'                    => 'storage/documents/intake/' . $pdfFilename,
    'devices_url'                => 'devices.php?highlight=' . urlencode($referenceCode),
    'notification_status'        => $emailStatus,
    'notification_error'         => $emailError,
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

/**
 * Renderuje HTML do PDF z użyciem Headless Chromium/Chrome.
 * Zwraca bajty PDF lub null, gdy się nie uda.
 */
function renderPdfWithChromium(string $html, string $outputPdfPath): ?string
{
    // 1) Zapisz HTML do tymczasowego pliku
    $tmpDir = sys_get_temp_dir();
    $tmpHtmlPath = tempnam($tmpDir, 'intake_');
    if ($tmpHtmlPath === false) {
        return null;
    }
    // Zmień rozszerzenie na .html (Chrome lepiej rozpoznaje)
    $newTmpHtmlPath = $tmpHtmlPath . '.html';
    rename($tmpHtmlPath, $newTmpHtmlPath);
    $tmpHtmlPath = $newTmpHtmlPath;

    if (file_put_contents($tmpHtmlPath, $html) === false) {
        @unlink($tmpHtmlPath);
        return null;
    }

    // 2) Wykryj binarkę Chrome/Chromium
    $chromeBinary = detectChromeBinary();
    if ($chromeBinary === null) {
        error_log('[Chromium PDF] Geen geschikt Chromium/Chrome uitvoerbaar bestand gevonden.');
        @unlink($tmpHtmlPath);
        return null;
    }

    // 3) Tymczasowy katalog profilu
    $userDataDir = $tmpDir . '/chromium-profile-' . bin2hex(random_bytes(6));
    if (!mkdir($userDataDir, 0700) && !is_dir($userDataDir)) {
        @unlink($tmpHtmlPath);
        return null;
    }

    // 4) Zbuduj URL file://
    $htmlUrl = 'file://' . str_replace('\\', '/', $tmpHtmlPath);

    $commandVariants = [
        '--headless=new',
        '--headless',
    ];

    $combinedOutput = [];
    $exitCode = 0;
    $success = false;

    foreach ($commandVariants as $headlessFlag) {
        if (is_file($outputPdfPath)) {
            @unlink($outputPdfPath);
        }

        $commandArguments = [
            $chromeBinary,
            $headlessFlag,
            '--disable-gpu',
            '--disable-dev-shm-usage',
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--user-data-dir=' . $userDataDir,
            '--print-to-pdf=' . $outputPdfPath,
            '--print-to-pdf-no-header',
            '--virtual-time-budget=20000',
            '--run-all-compositor-stages-before-draw',
            '--disable-web-security',
            '--allow-file-access-from-files',
            $htmlUrl,
        ];

        [$exitCode, $combinedOutput] = runChromiumCommand($commandArguments);

        if ($exitCode === 0 && is_file($outputPdfPath)) {
            $success = true;
            break;
        }
    }

    // 5) Sprzątanie i odczyt
    @unlink($tmpHtmlPath);
    removeDirectory($userDataDir);

    if (!$success) {
        error_log('[Chromium PDF] Exit code: ' . $exitCode . ' Output: ' . implode("\n", $combinedOutput));
        if (is_file($outputPdfPath)) {
            @unlink($outputPdfPath);
        }
        return null;
    }

    $pdfBytes = file_get_contents($outputPdfPath);
    return $pdfBytes === false ? null : $pdfBytes;
}

/**
 * Próbuje znaleźć binarkę Chrome/Chromium w popularnych lokalizacjach
 * albo pobiera z CHROME_BINARY.
 */
function detectChromeBinary(): ?string
{
    $candidates = [];

    $envKeys = [
        'PDF_CHROME_BINARY',
        'PDF_CHROMIUM_BINARY',
        'PDF_CHROME_PATH',
        'PDF_CHROMIUM_PATH',
        'CHROME_BINARY',
        'CHROMIUM_BINARY',
        'CHROME_PATH',
        'CHROMIUM_PATH',
    ];

    foreach ($envKeys as $envKey) {
        $env = Env::get($envKey);
        if (is_string($env) && $env !== '') {
            $candidates = array_merge($candidates, normaliseChromiumCandidate($env));
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $programFiles   = Env::get('ProgramFiles');
        $programFilesX86 = Env::get('ProgramFiles(x86)');
        $localAppData   = Env::get('LOCALAPPDATA');

        $windowsCandidates = [];

        foreach (array_filter([$programFiles, $programFilesX86]) as $basePath) {
            $windowsCandidates[] = $basePath . '\\Google\\Chrome\\Application\\chrome.exe';
            $windowsCandidates[] = $basePath . '\\Chromium\\Application\\chrome.exe';
            $windowsCandidates[] = $basePath . '\\Microsoft\\Edge\\Application\\msedge.exe';
            $windowsCandidates[] = $basePath . '\\BraveSoftware\\Brave-Browser\\Application\\brave.exe';
        }

        if (is_string($localAppData) && $localAppData !== '') {
            $windowsCandidates[] = $localAppData . '\\Google\\Chrome\\Application\\chrome.exe';
            $windowsCandidates[] = $localAppData . '\\Chromium\\Application\\chrome.exe';
            $windowsCandidates[] = $localAppData . '\\Microsoft\\Edge\\Application\\msedge.exe';
            $windowsCandidates[] = $localAppData . '\\BraveSoftware\\Brave-Browser\\Application\\brave.exe';
        }

        foreach ($windowsCandidates as $candidate) {
            $candidates = array_merge($candidates, normaliseChromiumCandidate($candidate));
        }
    } else {
        foreach ([
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/snap/bin/chromium',
        ] as $candidate) {
            $candidates[] = $candidate;
        }
    }

    foreach ([
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
    ] as $candidate) {
        $candidates[] = $candidate;
    }

    $checked = [];

    foreach ($candidates as $bin) {
        if (!is_string($bin) || $bin === '') {
            continue;
        }

        $trimmed = trim($bin, " \"'\t");
        if ($trimmed === '') {
            continue;
        }

        if (DIRECTORY_SEPARATOR === '\\') {
            $trimmed = rtrim($trimmed, '\\/');
        }

        if (isset($checked[$trimmed])) {
            continue;
        }

        $checked[$trimmed] = true;

        if (chromiumBinaryExists($trimmed)) {
            return $trimmed;
        }
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $whereCommands = [
            'where chrome',
            'where chromium',
            'where msedge',
            'where brave',
        ];

        foreach ($whereCommands as $command) {
            $output = shell_exec($command . ' 2>NUL');
            if (!$output) {
                continue;
            }

            foreach (preg_split('/\r?\n/', trim((string) $output)) as $line) {
                if ($line === '') {
                    continue;
                }

                $expanded = normaliseChromiumCandidate($line);
                foreach ($expanded as $candidate) {
                    if (chromiumBinaryExists($candidate)) {
                        return $candidate;
                    }
                }
            }
        }

        return null;
    }

    foreach ([
        'which chromium',
        'which chromium-browser',
        'which google-chrome',
        'which google-chrome-stable',
    ] as $command) {
        $which = trim(shell_exec($command . ' 2>/dev/null') ?? '');
        if ($which === '') {
            continue;
        }

        if (chromiumBinaryExists($which)) {
            return $which;
        }
    }

    return null;
}

/**
 * @return string[]
 */
function normaliseChromiumCandidate(string $candidate): array
{
    $trimmed = trim($candidate, " \"'\t");
    if ($trimmed === '') {
        return [];
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        $trimmed = rtrim($trimmed, '\\/');

        if (is_dir($trimmed)) {
            $binaries = [];
            foreach (['chrome.exe', 'msedge.exe', 'brave.exe', 'chromium.exe'] as $binary) {
                $path = $trimmed . '\\' . $binary;
                if (!in_array($path, $binaries, true)) {
                    $binaries[] = $path;
                }
            }
            return $binaries;
        }
    }

    return [$trimmed];
}

function chromiumBinaryExists(string $path): bool
{
    if ($path === '') {
        return false;
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        return is_file($path);
    }

    return is_file($path) && is_executable($path);
}

/**
 * Executes the Chromium command using proc_open when possible.
 *
 * @param string[] $arguments
 * @return array{0:int,1:array<int,string>}
 */
function runChromiumCommand(array $arguments): array
{
    $exitCode = 1;
    $outputLines = [];

    if (function_exists('proc_open')) {
        $descriptorSpec = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($arguments, $descriptorSpec, $pipes);

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            $stdoutString = $stdout !== false ? $stdout : '';
            $stderrString = $stderr !== false ? $stderr : '';
            $combined = trim($stdoutString . ($stdoutString !== '' && $stderrString !== '' ? "\n" : '') . $stderrString);
            if ($combined !== '') {
                $outputLines = preg_split("/\r?\n/", $combined) ?: [];
            }

            return [$exitCode, $outputLines];
        }
    }

    // Fallback to exec when proc_open is not available
    $commandString = buildChromiumCommandString($arguments);
    $execOutput = [];
    exec($commandString . ' 2>&1', $execOutput, $exitCode);

    return [$exitCode, $execOutput];
}

/**
 * Builds a shell-safe command string when proc_open is unavailable.
 *
 * @param string[] $arguments
 */
function buildChromiumCommandString(array $arguments): string
{
    $escaped = array_map(static function (string $argument): string {
        if (DIRECTORY_SEPARATOR === '\\') {
            $replacements = [
                '"' => '""',
                '^' => '^^',
                '%' => '^%',
                '!' => '^!',
                '&' => '^&',
                '|' => '^|',
                '<' => '^<',
                '>' => '^>',
            ];
            $argument = strtr($argument, $replacements);
            return '"' . $argument . '"';
        }

        return escapeshellarg($argument);
    }, $arguments);

    return implode(' ', $escaped);
}

function removeDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = scandir($directory);
    if ($items === false) {
        @rmdir($directory);
        return;
    }

    foreach ($items as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($directory);
}
