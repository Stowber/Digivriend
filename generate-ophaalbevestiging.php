<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Security\Auth;
use App\Support\Audit\AuditLogger;
use App\Services\DocumentGenerator;
use App\Services\DocumentRequest;
use App\Support\Codes\PickupCodeGenerator;
use App\Support\Documents\DocumentRepository;
use App\Support\Notifications\NotificationService;
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
    $klantnaam = InputValidator::requireString($_POST, 'klantnaam', 120);
    $klantemail = InputValidator::optionalEmail($_POST, 'klantemail', 120);
    $klanttelefoon = InputValidator::optionalPhone($_POST, 'klanttelefoon', 32);
    $apparaatmerk = InputValidator::requireString($_POST, 'apparaatmerk', 120);
    $apparaatmodel = InputValidator::requireString($_POST, 'apparaatmodel', 120);
    $ophaalcode = InputValidator::requireString($_POST, 'ophaalcode', 32);
    $datumgereed = InputValidator::requireDate($_POST, 'datumgereed');
    $opmerkingen = InputValidator::optionalString($_POST, 'opmerkingen', 500);
    $pickupScheduledAt = InputValidator::optionalDateTime($_POST, 'pickup_scheduled_at');
    $pickupWindow = InputValidator::optionalString($_POST, 'pickup_window', 120);
    $smsTemplate = InputValidator::optionalString($_POST, 'sms_template', 200);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$pickupCodeGenerator = new PickupCodeGenerator($pdo);

try {
    $ophaalcode = $pickupCodeGenerator->generate($ophaalcode);
} catch (\RuntimeException $exception) {
    Response::error($exception->getMessage(), 500);
}

$notifyEmail = isset($_POST['notify_email']);
$notifySms = isset($_POST['notify_sms']);

$merkmodel = trim($apparaatmerk . ' ' . $apparaatmodel);

try {
    $statement = $pdo->prepare(
        'INSERT INTO ophaalbevestigingen (klantnaam, klantemail, klanttelefoon, merkmodel, apparaatmerk, apparaatmodel, ophaalcode, case_reference, datumgereed, status, opmerkingen, pickup_scheduled_at, pickup_window)
         VALUES (:klantnaam, :klantemail, :klanttelefoon, :merkmodel, :apparaatmerk, :apparaatmodel, :ophaalcode, :case_reference, :datumgereed, :status, :opmerkingen, :pickup_scheduled_at, :pickup_window)'
    );
    $statement->execute([
        'klantnaam' => $klantnaam,
        'klantemail' => $klantemail ?: null,
        'klanttelefoon' => $klanttelefoon ?: null,
        'merkmodel' => $merkmodel,
        'apparaatmerk' => $apparaatmerk,
        'apparaatmodel' => $apparaatmodel,
        'ophaalcode' => $ophaalcode,
        'case_reference' => $ophaalcode,
        'datumgereed' => $datumgereed,
        'status' => 'klaar',
        'opmerkingen' => $opmerkingen ?: null,
        'pickup_scheduled_at' => $pickupScheduledAt !== '' ? $pickupScheduledAt : null,
        'pickup_window' => $pickupWindow !== '' ? $pickupWindow : null,
    ]);

        $insertId = (int) $pdo->lastInsertId();
} catch (\PDOException $exception) {
    Response::error('Opslaan van de ophaalbevestiging is mislukt.', 500);
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$notificationService = new NotificationService($pdo);
$documentRepository = new DocumentRepository($pdo);
$auditLogger = new AuditLogger($pdo);

$customer = $customerRepository->upsert($klantnaam, $klantemail ?: null, $klanttelefoon ?: null);
$device = $deviceRepository->findOrCreate((int) $customer['id'], $apparaatmerk, $apparaatmodel);
$case = $caseRepository->createOrUpdate(
    'pickup',
    (int) $customer['id'],
    $device['id'] ?? null,
    'klaar',
    sprintf('Ophaalbevestiging %s', $ophaalcode),
    $ophaalcode,
    [
        'datumgereed' => $datumgereed,
        'opmerkingen' => $opmerkingen,
        'pickup_scheduled_at' => $pickupScheduledAt,
        'pickup_window' => $pickupWindow,
    ]
);

if ($opmerkingen !== '') {
    $noteRepository->add((int) $case['id'], (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), $opmerkingen);
}

$updateStatement = $pdo->prepare('UPDATE ophaalbevestigingen SET case_id = :case_id WHERE id = :id');
$updateStatement->execute([
    'case_id' => (int) $case['id'],
    'id' => $insertId,
]);

$notifiedAt = null;
if ($notifyEmail && $klantemail !== '') {
    $notificationService->sendPickupReady(
        (int) $case['id'],
        (int) $customer['id'],
        $klantemail,
        [
            'customer_name' => $klantnaam,
            'device' => $merkmodel,
            'pickup_code' => $ophaalcode,
            'ready_date' => $datumgereed,
            'subject' => 'Uw apparaat staat klaar bij Digivriend',
        ]
    );
    $notifiedAt = $notifiedAt ?: date('Y-m-d H:i:s');
}

if ($notifySms && $klanttelefoon !== '' && $smsTemplate !== '') {
    $notificationService->sendSms(
        (int) $case['id'],
        (int) $customer['id'],
        $klanttelefoon,
        [
            'body' => $smsTemplate,
            'subject' => 'Pickup klaar',
        ]
    );
    $notifiedAt = $notifiedAt ?: date('Y-m-d H:i:s');
}

if ($notifiedAt !== null) {
    $notifiedStatement = $pdo->prepare('UPDATE ophaalbevestigingen SET notified_ready_at = :notified_at WHERE id = :id');
    $notifiedStatement->execute([
        'notified_at' => $notifiedAt,
        'id' => $insertId,
    ]);
}

$auditLogger->log(
    (int) $case['id'],
    Auth::id(),
    Auth::username(),
    'pickup_registered',
    [
        'ophaalcode' => $ophaalcode,
        'datumgereed' => $datumgereed,
        'pickup_scheduled_at' => $pickupScheduledAt,
        'notifications' => [
            'email' => $notifyEmail && $klantemail !== '',
            'sms' => $notifySms && $klanttelefoon !== '' && $smsTemplate !== '',
        ],
    ]
);

$documentTitel = 'Ophaalbevestiging';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');

$documentGenerator = new DocumentGenerator($documentRepository);
$filename = sprintf('Ophaalbevestiging[%s][%d].pdf', $huidigeDatum, $insertId);

$documentGenerator->generate(
    new DocumentRequest(
        template: 'pdf/ophaalbevestiging.php',
        context: [
            'documentTitel' => $documentTitel,
            'bedrijfsNaam' => $bedrijfsNaam,
            'huidigeDatum' => $huidigeDatum,
            'klantnaam' => $klantnaam,
            'ophaalcode' => $ophaalcode,
            'merkmodel' => $merkmodel,
            'datumgereed' => $datumgereed,
        ],
        filename: $filename,
        store: true,
        documentType: 'ophaalbevestiging',
        caseId: (int) $case['id'],
        metadata: [
            'klantnaam' => $klantnaam,
            'ophaalcode' => $ophaalcode,
            'datumgereed' => $datumgereed,
        ],
    )
);
