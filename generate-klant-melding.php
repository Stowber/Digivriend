<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
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

if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
    Response::error('Ongeldige of ontbrekende CSRF-token.', 419);
}

try {
    $klantnaam = InputValidator::requireString($_POST, 'klantnaam', 150);
    $klantadres = InputValidator::requireString($_POST, 'klantadres', 200);
    $klanttelefoon = InputValidator::requirePhone($_POST, 'klanttelefoon', 32);
    $klantemail = InputValidator::requireEmail($_POST, 'klantemail', 150);

$apparaatmerk = InputValidator::requireString($_POST, 'apparaatmerk', 120);
    $apparaatmodel = InputValidator::requireString($_POST, 'apparaatmodel', 120);
    $apparaatserienummer = InputValidator::optionalString($_POST, 'apparaatserienummer', 120);

    $meldingonderwerp = InputValidator::requireString($_POST, 'meldingonderwerp', 200);
    $meldingomschrijving = InputValidator::requireString($_POST, 'meldingomschrijving', 2000);
    $meldingdatum = InputValidator::requireDate($_POST, 'meldingdatum');
    $opmerkingen = InputValidator::optionalString($_POST, 'opmerkingen', 2000);

    $reparatiespoed = InputValidator::requireString($_POST, 'reparatiespoed', 20);
    $magcontact = InputValidator::requireString($_POST, 'magcontact', 5);
    $kostenoptie = InputValidator::requireString($_POST, 'kostenoptie', 32);
    $bedragzelf = InputValidator::optionalString($_POST, 'bedragzelf', 32);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$postalCode = null;
if (preg_match('/(\d{4}\s?[A-Z]{2})/i', $klantadres, $match)) {
    $postalCode = strtoupper(str_replace(' ', '', $match[1]));
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

$customer = $customerRepository->upsert(
    $klantnaam,
    $klantemail,
    $klanttelefoon,
    $klantadres,
    $postalCode,
    null
);
$device = $deviceRepository->findOrCreate((int) $customer['id'], $apparaatmerk, $apparaatmodel, $apparaatserienummer ?: null);
$caseReference = 'MEL-' . strtoupper(bin2hex(random_bytes(3)));

$details = [
    'meldingonderwerp' => $meldingonderwerp,
    'meldingomschrijving' => $meldingomschrijving,
    'meldingdatum' => $meldingdatum,
    'reparatiespoed' => $reparatiespoed,
    'magcontact' => $magcontact,
    'kostenoptie' => $kostenoptie,
    'bedragzelf' => $bedragzelf,
];

$case = $caseRepository->createOrUpdate(
    'service_request',
    (int) $customer['id'],
    $device['id'] ?? null,
    'open',
    $meldingonderwerp,
    $caseReference,
    $details
);

$noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Nieuwe klantmelding geregistreerd.');
$noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Omschrijving: ' . $meldingomschrijving);

if ($opmerkingen !== '') {
    $noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Opmerkingen: ' . $opmerkingen);
}

$documentTitel = 'Klant Melding';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');
$jaartal = date('Y');

$spoedTeksten = [
    'standard' => 'Standaard (3–5 werkdagen, geen kosten)',
    'snel' => 'Snel (2–3 werkdagen, +15€)',
    'spoed' => 'Spoed (24 uur, +50€)',
];
$contactTekst = $magcontact === 'ja'
    ? 'Ja. Telefonisch contact met de klant is toegestaan. De partner mag de klant rechtstreeks benaderen om prijsafspraken of werkzaamheden te bespreken.'
    : 'Nee. De klant wil niet door andere partijen benaderd worden. Alleen Digivriend is bevoegd om contact op te nemen met de klant.';

switch ($kostenoptie) {
    case 'allekosten':
        $kostenText = 'Alle kosten laten weten.';
        break;
    case 'tot100':
        $kostenText = 'Toestemming tot 100€ zonder kennisgeving.';
        break;
    case 'zelfbedrag':
        $kostenText = 'Toestemming tot ' . ($bedragzelf !== '' ? $bedragzelf : 'een afgesproken bedrag') . ' zonder kennisgeving.';
        break;
    default:
        $kostenText = 'Onbekende optie.';
}

$meldingOmschrijvingHtml = nl2br($meldingomschrijving);
$opmerkingenHtml = $opmerkingen !== '' ? nl2br($opmerkingen) : '';

ob_start();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title><?= $documentTitel ?> - <?= $bedrijfsNaam ?></title>
  <style>
    body {
      margin: 0;
      padding: 0;
      font-family: Arial, sans-serif;
      color: #333;
    }
    @page {
      margin: 20mm;
    }
    .header {
      background-color: #F05A28;
      color: #fff;
      padding: 15px;
      text-align: center;
    }
    .header h1 {
      margin: 0;
      font-size: 24px;
    }
    .header p {
      margin: 5px 0 0;
      font-size: 14px;
    }
    .container {
      margin: 20px;
    }
    .card {
      border: 1px solid #ccc;
      border-radius: 4px;
      padding: 15px;
      margin-bottom: 20px;
      background-color: #fafafa;
    }
    .card h2 {
      margin-top: 0;
      font-size: 18px;
      color: #F05A28;
      border-bottom: 1px solid #ddd;
      padding-bottom: 5px;
    }
    .row {
      margin: 5px 0;
    }
    .label {
      display: inline-block;
      width: 120px;
      font-weight: bold;
    }
    .two-column-table {
      width: 100%;
      border-spacing: 15px;
    }
    .two-column-table td {
      vertical-align: top;
      width: 50%;
    }
    .footer {
      text-align: center;
      font-size: 12px;
      color: #666;
      border-top: 1px solid #ccc;
      padding-top: 10px;
      margin-top: 20px;
    }
  </style>
</head>
<body>
  <div class="header">
    <h1><?= $bedrijfsNaam ?></h1>
    <p><?= $documentTitel ?> - <?= $huidigeDatum ?></p>
  </div>
  <div class="container">

    <table class="two-column-table">
      <tr>
        <td>
          <div class="card">
            <h2>Klantgegevens</h2>
            <div class="row"><span class="label">Naam:</span> <?= $klantnaam ?></div>
            <div class="row"><span class="label">Adres:</span> <?= $klantadres ?></div>
            <div class="row"><span class="label">Telefoon:</span> <?= $klanttelefoon ?></div>
            <div class="row"><span class="label">E-mail:</span> <?= $klantemail ?></div>
          </div>
        </td>
        <td>
          <div class="card">
            <h2>Apparaatgegevens</h2>
            <div class="row"><span class="label">Merk:</span> <?= $apparaatmerk ?></div>
            <div class="row"><span class="label">Model:</span> <?= $apparaatmodel ?></div>
            <?php if ($apparaatserienummer !== ''): ?>
              <div class="row"><span class="label">Serienr.:</span> <?= $apparaatserienummer ?></div>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    </table>

    <div class="card">
      <h2>Melding</h2>
      <div class="row"><span class="label">Onderwerp:</span> <?= $meldingonderwerp ?></div>
      <div class="row" style="margin-top:8px;"><strong>Omschrijving:</strong><br><?= $meldingOmschrijvingHtml ?></div>
      <div class="row" style="margin-top:8px;"><span class="label">Datum:</span> <?= $meldingdatum ?></div>
      <?php if ($opmerkingenHtml !== ''): ?>
        <div class="row" style="margin-top:8px;"><strong>Opmerkingen:</strong><br><?= $opmerkingenHtml ?></div>
      <?php endif; ?>
    </div>

<div class="card">
      <h2>Afgesproken prioriteit</h2>
      <div class="row"><?= $spoedTeksten[$reparatiespoed] ?? 'Onbekend' ?></div>
    </div>
      <h2>Contactvoorkeur</h2>
      <div class="row"><?= $contactTekst ?></div>
    </div>
    <div class="card">
      <h2>Kostenafspraak</h2>
      <div class="row"><?= $kostenText ?></div>
    </div>

  </div>
  <div class="footer">
    <p>&copy; <?= $jaartal ?> <?= $bedrijfsNaam ?> - Referentie: <?= $caseReference ?></p>
  </div>
</body>
</html>
<?php
$html = (string) ob_get_clean();

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = sprintf('Klantmelding[%s][%s].pdf', $huidigeDatum, $caseReference);
$dompdf->stream($filename, ['Attachment' => true]);
