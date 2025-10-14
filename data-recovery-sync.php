<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Audit\AuditLogger;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    Response::error('Alleen POST-verzoeken zijn toegestaan.', 405);
}

if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
    Response::error('Ongeldige of ontbrekende CSRF-token.', 419);
}

$inboxDir = __DIR__ . '/storage/data-recovery/inbox';
$archiveDir = __DIR__ . '/storage/data-recovery/archive';

if (!is_dir($inboxDir)) {
    mkdir($inboxDir, 0775, true);
}
if (!is_dir($archiveDir)) {
    mkdir($archiveDir, 0775, true);
}

$files = glob($inboxDir . '/*.json') ?: [];

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$auditLogger = new AuditLogger($pdo);

$imported = 0;
$errors = [];

foreach ($files as $file) {
    $raw = file_get_contents($file);
    if ($raw === false) {
        $errors[] = basename($file) . ': kon niet worden gelezen';
        continue;
    }

    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        $errors[] = basename($file) . ': ongeldige JSON-structuur';
        continue;
    }

    try {
        $fullname = trim((string) ($payload['fullname'] ?? ''));
        $email = trim((string) ($payload['email'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $address = trim((string) ($payload['address'] ?? ''));
        $postcode = trim((string) ($payload['postcode'] ?? ''));
        $signatureDate = trim((string) ($payload['signature_date'] ?? ''));
        $caseReference = trim((string) ($payload['case_reference'] ?? '')) ?: 'DR-' . strtoupper(bin2hex(random_bytes(3)));
        $deviceBrand = trim((string) ($payload['device_brand'] ?? ''));
        $deviceModel = trim((string) ($payload['device_model'] ?? ''));
        $deviceSerial = trim((string) ($payload['device_serial'] ?? ''));
        $notes = trim((string) ($payload['notes'] ?? ''));

        if ($fullname === '' || $email === '' || $phone === '' || $address === '' || $postcode === '' || $signatureDate === '') {
            throw new ValidationException(['bestand' => 'Ontbrekende verplichte velden.']);
        }

        $customer = $customerRepository->upsert($fullname, $email, $phone, $address, $postcode);
        $device = $deviceRepository->findOrCreate((int) $customer['id'], $deviceBrand !== '' ? $deviceBrand : null, $deviceModel !== '' ? $deviceModel : null, $deviceSerial !== '' ? $deviceSerial : null);

        $case = $caseRepository->createOrUpdate(
            'data_recovery',
            (int) $customer['id'],
            $device['id'] ?? null,
            'open',
            'Data recovery synchronisatie',
            $caseReference,
            [
                'signature_date' => $signatureDate,
                'notes' => $notes,
                'source' => 'partner-sync',
            ]
        );

        $noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), 'Data recovery aanvraag geïmporteerd vanuit partnerinbox.');
        if ($notes !== '') {
            $noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), $notes);
        }

        $auditLogger->log(
            (int) $case['id'],
            Auth::id(),
            Auth::username(),
            'data_recovery_imported',
            [
                'bron' => basename($file),
                'case_reference' => $caseReference,
            ]
        );

        rename($file, $archiveDir . '/' . basename($file));
        $imported++;
    } catch (ValidationException $exception) {
        $errors[] = basename($file) . ': ' . implode(', ', $exception->errors());
    }
}

$query = ['imported' => $imported];
if ($errors !== []) {
    $query['errors'] = urlencode(json_encode($errors, JSON_THROW_ON_ERROR));
}

Response::redirect('data-recovery.php?' . http_build_query($query));