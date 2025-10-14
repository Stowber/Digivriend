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
    $fullname = InputValidator::requireString($_POST, 'fullname', 150);
    $address = InputValidator::requireString($_POST, 'address', 200);
    $phone = InputValidator::requirePhone($_POST, 'phone', 32);
    $email = InputValidator::requireEmail($_POST, 'email', 150);

    $deviceBrand = InputValidator::requireString($_POST, 'deviceBrand', 120);
    $deviceModel = InputValidator::requireString($_POST, 'deviceModel', 150);
    $deviceSerial = InputValidator::optionalString($_POST, 'deviceSerial', 120);
    $deviceNotes = InputValidator::optionalString($_POST, 'deviceNotes', 500);

    $repairConsentOption = InputValidator::requireString($_POST, 'repairConsentOption', 20);
    $customAmount = InputValidator::optionalString($_POST, 'customAmount', 32);

    $signatureName = InputValidator::requireString($_POST, 'signatureName', 150);
    $signaturePlace = InputValidator::requireString($_POST, 'signaturePlace', 120);
    $signatureDate = InputValidator::requireDate($_POST, 'signatureDate');
    $signature = InputValidator::optionalString($_POST, 'signature', 255);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$consentFlags = [
    'repairConsent100' => $repairConsentOption === '100' ? 1 : 0,
    'repairConsentNotify' => $repairConsentOption === 'notify' ? 1 : 0,
    'repairConsentCustom' => $repairConsentOption === 'custom' ? 1 : 0,
];

try {
    $statement = $pdo->prepare(
        'INSERT INTO reparatie_onderzoek (
            fullname, address, phone, email,
            repair_consent_100, repair_consent_notify, repair_consent_custom, custom_amount,
            signature_name, signature_place, signature_date, signature,
            device_brand, device_model, device_serial, device_notes, case_reference
        ) VALUES (
            :fullname, :address, :phone, :email,
            :c100, :cNotify, :cCustom, :cAmount,
            :sigName, :sigPlace, :sigDate, :sig,
            :deviceBrand, :deviceModel, :deviceSerial, :deviceNotes, :caseReference
        )'
    );
    $caseReference = 'REP-' . strtoupper(bin2hex(random_bytes(3)));
    $statement->execute([
        'fullname' => $fullname,
        'address' => $address,
        'phone' => $phone,
        'email' => $email,
        'c100' => $consentFlags['repairConsent100'],
        'cNotify' => $consentFlags['repairConsentNotify'],
        'cCustom' => $consentFlags['repairConsentCustom'],
        'cAmount' => $customAmount ?: null,
        'sigName' => $signatureName,
        'sigPlace' => $signaturePlace,
        'sigDate' => $signatureDate,
        'sig' => $signature ?: null,
        'deviceBrand' => $deviceBrand,
        'deviceModel' => $deviceModel,
        'deviceSerial' => $deviceSerial ?: null,
        'deviceNotes' => $deviceNotes ?: null,
        'caseReference' => $caseReference,
    ]);
    $insertId = (int) $pdo->lastInsertId();
} catch (\PDOException $exception) {
    Response::error('Fout bij opslaan in de database.', 500);
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

$customer = $customerRepository->upsert($fullname, $email, $phone, $address);
$device = $deviceRepository->findOrCreate((int) $customer['id'], $deviceBrand, $deviceModel, $deviceSerial ?: null);

$details = [
    'repair_consent_option' => $repairConsentOption,
    'custom_amount' => $customAmount,
    'signature_place' => $signaturePlace,
    'signature_date' => $signatureDate,
    'device_notes' => $deviceNotes,
];

$case = $caseRepository->createOrUpdate(
    'repair_request',
    (int) $customer['id'],
    $device['id'] ?? null,
    'in_behandeling',
    'Reparatie & Onderzoek toestemmingsformulier',
    $caseReference,
    $details
);

$noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Formulier voor reparatie & onderzoek geregistreerd.');
$noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Voorkeur: ' . $repairConsentOption . ($customAmount !== '' ? ' (' . $customAmount . ')' : ''));

$documentTitel = 'Toestemmingsformulier Reparatie & Onderzoek';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');

$consentText = match ($repairConsentOption) {
    '100' => 'Reparaties tot €100 zonder kennisgeving.',
    'notify' => 'Eerst op de hoogte gebracht worden van alle reparatiekosten.',
    'custom' => 'Eigen budget zonder kennisgeving tot ' . ($customAmount !== '' ? $customAmount : 'het opgegeven bedrag') . '.',
    default => 'Geen voorkeur opgegeven.',
};

ob_start();
?>
<!DOCTYPE html>
<html lang="nl">
<head>
    <meta charset="UTF-8">
    <title><?= $documentTitel ?></title>
    <style>
        body {
            font-family: "Helvetica", sans-serif;
            color: #333;
            margin: 0; 
            padding: 0; 
            line-height: 1.4;
        }
        @page {
            margin: 40px;
        }
        .header {
            background-color: #0d6efd;
            color: #fff;
            padding: 20px;
            margin-bottom: 20px;
        }
        .header h1 {
            margin: 0;
            font-size: 1.6em;
        }
        .header p {
            margin: 5px 0 0 0;
            font-size: 0.9em;
        }
        .content {
            margin: 0 20px;
        }
        .title-block {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        .title-block h2 {
            margin: 0;
            font-size: 1.3em;
            color: #0d6efd;
        }
        .info-section {
            background: #f8f8f8;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .info-section h3 {
            margin-top: 0;
            font-size: 1.1em;
            color: #0d6efd;
        }
        .footer {
            margin: 30px 20px;
            padding-top: 10px;
            border-top: 1px solid #ccc;
            font-size: 0.85em;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1><?= $bedrijfsNaam ?></h1>
        <p>De Ganskuijl 103B, 3817 EZ Amersfoort | +31 (0)33 785 4284 | www.digivriend.nl</p>
    </div>

    <div class="content">
        <div class="title-block">
           <h2><?= $documentTitel ?></h2>
            <p>Gegenereerd op: <?= date('d-m-Y') ?> · Referentie: <?= $caseReference ?></p>
        </div>

        <div class="info-section">
            <h3>Klantinformatie</h3>
            <p><strong>Naam:</strong> <?= $fullname ?></p>
            <p><strong>Adres + Postcode:</strong> <?= $address ?></p>
            <p><strong>Telefoonnummer:</strong> <?= $phone ?></p>
            <p><strong>E-mail:</strong> <?= $email ?></p>
        </div>

        <div class="info-section">
            <h3>Apparaatgegevens</h3>
            <p><strong>Merk:</strong> <?= $deviceBrand ?></p>
            <p><strong>Model:</strong> <?= $deviceModel ?></p>
            <?php if ($deviceSerial !== ''): ?>
                <p><strong>Serienummer:</strong> <?= $deviceSerial ?></p>
            <?php endif; ?>
            <?php if ($deviceNotes !== ''): ?>
                <p><strong>Opmerkingen:</strong> <?= $deviceNotes ?></p>
            <?php endif; ?>
        </div>

        <div class="info-section">
            <h3>Onderzoekstoestemming</h3>
            <p>
                Hierbij geef ik, ondergetekende, toestemming aan Digivriend om onderzoek uit te voeren om de aard en omvang van de schade vast te stellen. 
                <br><br>
                <strong>Onderzoekskosten:</strong> €49,95, deze kosten zijn verschuldigd ongeacht de beslissing over verdere reparatie.
            </p>
        </div>

        <div class="info-section">
            <h3>Reparatietoestemming</h3>
            <p><?= $consentText ?></p>
        </div>

        <div class="info-section">
            <h3>Ondertekening</h3>
            <p><strong>Naam:</strong> <?= $signatureName ?></p>
            <p><strong>Plaats:</strong> <?= $signaturePlace ?></p>
            <p><strong>Datum:</strong> <?= $signatureDate ?></p>
            <?php if ($signature !== ''): ?>
                <p><strong>Handtekening:</strong> <?= $signature ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="footer">
        <p>&copy; <?= date('Y') ?> <?= $bedrijfsNaam ?>. Alle rechten voorbehouden.</p>
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
$filename = sprintf('ReparatieOnderzoek[%s][%d].pdf', $huidigeDatum, $insertId);
$dompdf->stream($filename, ['Attachment' => true]);
