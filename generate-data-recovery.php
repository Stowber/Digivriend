<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Audit\AuditLogger;
use App\Services\DocumentGenerator;
use App\Services\DocumentRequest;
use App\Support\Documents\DocumentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Validation\InputValidator;

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
    $postcode = InputValidator::requireString($_POST, 'postcode', 16);
    $phone = InputValidator::requirePhone($_POST, 'phone', 32);
    $email = InputValidator::requireEmail($_POST, 'email', 150);
    $signatureDate = InputValidator::requireDate($_POST, 'signatureDate');
    $signature = InputValidator::optionalString($_POST, 'signature', 255);
    $caseReference = InputValidator::optionalString($_POST, 'caseReference', 64);
    $deviceBrand = InputValidator::optionalString($_POST, 'deviceBrand', 120);
    $deviceModel = InputValidator::optionalString($_POST, 'deviceModel', 191);
    $deviceSerial = InputValidator::optionalString($_POST, 'deviceSerial', 120);
    $notes = InputValidator::optionalString($_POST, 'notes', 2000);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$generatedReference = $caseReference !== '' ? $caseReference : 'DR-' . strtoupper(bin2hex(random_bytes(3)));

try {
    $statement = $pdo->prepare(
        'INSERT INTO data_recovery (
          fullname, address, postcode, phone, email,
          signature_date, signature, case_reference,
          device_brand, device_model, device_serial, notes
        ) VALUES (
          :fullname, :address, :postcode, :phone, :email,
          :sigDate, :sig, :caseReference,
          :deviceBrand, :deviceModel, :deviceSerial, :notes
        )'
    );
    $statement->execute([
        'fullname' => $fullname,
        'address' => $address,
        'postcode' => $postcode,
        'phone' => $phone,
        'email' => $email,
        'sigDate' => $signatureDate,
        'sig' => $signature ?: null,
        'caseReference' => $generatedReference,
        'deviceBrand' => $deviceBrand ?: null,
        'deviceModel' => $deviceModel ?: null,
        'deviceSerial' => $deviceSerial ?: null,
        'notes' => $notes ?: null,
    ]);
     $insertId = (int) $pdo->lastInsertId();
} catch (\PDOException $exception) {
    Response::error('Fout bij opslaan in de database.', 500);
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$documentRepository = new DocumentRepository($pdo);
$auditLogger = new AuditLogger($pdo);

$customer = $customerRepository->upsert($fullname, $email, $phone, $address, $postcode);
$device = $deviceRepository->findOrCreate((int) $customer['id'], $deviceBrand ?: null, $deviceModel ?: null, $deviceSerial ?: null);

$details = [
    'signature_date' => $signatureDate,
    'signature' => $signature,
    'notes' => $notes,
    'postcode' => $postcode,
];

$case = $caseRepository->createOrUpdate(
    'data_recovery',
    (int) $customer['id'],
    $device['id'] ?? null,
    'open',
    'Data recovery aanvraag',
    $generatedReference,
    $details
);

$noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Data recovery case geregistreerd.');
if ($notes !== '') {
    $noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), $notes);
}

$documentTitel = 'Toestemmingsverklaring Data Recovery';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');

$documentGenerator = new DocumentGenerator($documentRepository);

$filename = sprintf('DataRecovery[%s][%d].pdf', $huidigeDatum, $insertId);

$documentGenerator->generate(
    new DocumentRequest(
        template: 'pdf/data-recovery.php',
        context: [
            'documentTitel' => $documentTitel,
            'bedrijfsNaam' => $bedrijfsNaam,
            'huidigeDatum' => $huidigeDatum,
            'generatedReference' => $generatedReference,
            'fullname' => $fullname,
            'address' => $address,
            'postcode' => $postcode,
            'phone' => $phone,
            'email' => $email,
            'deviceBrand' => $deviceBrand,
            'deviceModel' => $deviceModel,
            'deviceSerial' => $deviceSerial,
            'signatureDate' => $signatureDate,
            'signature' => $signature,
            'notes' => $notes,
        ],
        filename: $filename,
        store: true,
        documentType: 'data_recovery',
        caseId: (int) $case['id'],
        metadata: [
            'klantnaam' => $fullname,
            'case_reference' => $generatedReference,
        ],
    )
);
