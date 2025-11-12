<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Audit\AuditLogger;
use App\Support\Clock;
use App\Support\Customers\SuspiciousFlagRegistry;
use App\Support\Documents\DocumentRepository;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
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
    $idValue = InputValidator::requireString($_POST, 'id', 20);
    if (!ctype_digit($idValue)) {
        throw new ValidationException(['id' => 'Ongeldig ID.']);
    }

    $signatureData = InputValidator::requireString($_POST, 'signatureData', 500000);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

if (!str_starts_with($signatureData, 'data:image/png;base64,')) {
    Response::error(['signatureData' => 'Ongeldig handtekeningformaat.'], 422);
}

[$metadata, $payload] = explode(',', $signatureData, 2) + ['', ''];
$decodedSignature = base64_decode($payload, true);
if ($decodedSignature === false || strlen($decodedSignature) > 200_000) {
    Response::error(['signatureData' => 'Handtekening is te groot of ongeldig.'], 422);
}

try {
    $statement = $pdo->prepare(
        'UPDATE ophaalbevestigingen SET pickup_signature = :signature, pickup_signed_at = :pickup_signed_at, status = :status, updated_at = :updated_at WHERE id = :id'
    );
    $statement->execute([
        'signature' => $signatureData,
        'status' => 'opgehaald',
        'id' => (int) $idValue,
        'updated_at' => Clock::nowFormatted(),
        'pickup_signed_at' => Clock::nowFormatted(),
    ]);
    $fetchStatement = $pdo->prepare('SELECT case_id, klantnaam, klantemail, klanttelefoon, ophaalcode FROM ophaalbevestigingen WHERE id = :id');
    $fetchStatement->execute(['id' => (int) $idValue]);
    $ophaalRecord = $fetchStatement->fetch();

    if ($ophaalRecord) {
        $caseId = isset($ophaalRecord['case_id']) ? (int) $ophaalRecord['case_id'] : null;
        if ($caseId) {
            $caseRepository = new CaseRepository($pdo);
            $noteRepository = new NoteRepository($pdo);
            $notificationService = new NotificationService($pdo);
            $documentRepository = new DocumentRepository($pdo);
            $auditLogger = new AuditLogger($pdo);
            $caseRepository->updateStatus($caseId, 'opgehaald');
            $customerRepository = new CustomerRepository($pdo);
            $case = $caseRepository->findById($caseId);
            if ($case !== null) {
                $customer = $customerRepository->findById((int) $case['customer_id']);
                if ($customer !== null) {
                    if (
                        isset($customer['is_suspicious'])
                        && (int) $customer['is_suspicious'] === 1
                        && SuspiciousFlagRegistry::hasBlock(
                            SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null),
                            SuspiciousFlagRegistry::BLOCK_PICKUPS
                        )
                    ) {
                        $reason = trim((string) ($customer['suspicious_reason'] ?? ''));
                        $reasonText = $reason !== '' ? $reason : __('customers.profile.suspicious.reason_unknown');
                        $_SESSION['pickup_error'] = __('customers.suspicious.blocked_action', [
                            'action' => __('customers.profile.suspicious.blocks.pickups.label'),
                            'reason' => $reasonText,
                        ]);
                        Response::redirect('pickup-sign.php?id=' . (int) $idValue);
                    }
                    $noteRepository->add($caseId, (int) $customer['id'], (string) ($_SESSION['username'] ?? 'Systeem'), sprintf('Ophaalbevestiging ondertekend door %s.', (string) $ophaalRecord['klantnaam']));
                    $signatureDir = __DIR__ . '/storage/documents/signatures';
                    if (!is_dir($signatureDir)) {
                        mkdir($signatureDir, 0775, true);
                    }

                    $signatureFilename = sprintf('pickup-signature-%s-%s.png', $idValue, date('YmdHis'));
                    file_put_contents($signatureDir . '/' . $signatureFilename, $decodedSignature);

                    $documentRepository->store(
                        $caseId,
                        'pickup_signature',
                        'storage/documents/signatures/' . $signatureFilename,
                        [
                            'klantnaam' => $ophaalRecord['klantnaam'],
                            'ophaalcode' => $ophaalRecord['ophaalcode'] ?? null,
                        ]
                    );

                    $notifiedAt = null;
                    if (!empty($ophaalRecord['klantemail'])) {
                        $notificationService->sendPickupConfirmation(
                            $caseId,
                            (int) $customer['id'],
                            (string) $ophaalRecord['klantemail'],
                            [
                                'customer_name' => $ophaalRecord['klantnaam'],
                                'pickup_code' => $ophaalRecord['ophaalcode'] ?? '',
                                'pickup_date' => date('d-m-Y'),
                            ]
                        );
                        $notifiedAt = Clock::nowFormatted();
                    }

                    if (!empty($ophaalRecord['klanttelefoon'])) {
                        $notificationService->sendSms(
                            $caseId,
                            (int) $customer['id'],
                            (string) $ophaalRecord['klanttelefoon'],
                            [
                                'body' => sprintf('Bedankt voor uw bezoek! Code %s is opgehaald.', $ophaalRecord['ophaalcode'] ?? ''),
                                'subject' => 'Pickup bevestigd',
                            ]
                        );
                        $notifiedAt = $notifiedAt ?? Clock::nowFormatted();
                    }

                    if ($notifiedAt !== null) {
                        $readyStatement = $pdo->prepare('UPDATE ophaalbevestigingen SET notified_collected_at = :notified WHERE id = :id');
                        $readyStatement->execute([
                            'notified' => $notifiedAt,
                            'id' => (int) $idValue,
                        ]);
                    }

                    $auditLogger->log(
                        $caseId,
                        Auth::id(),
                        Auth::username(),
                        'pickup_signed',
                        [
                            'ophaalcode' => $ophaalRecord['ophaalcode'] ?? null,
                            'notifications' => [
                                'email' => !empty($ophaalRecord['klantemail']),
                                'sms' => !empty($ophaalRecord['klanttelefoon']),
                            ],
                        ]
                    );
                }
            }
        }
    }
} catch (\PDOException $exception) {
    Response::error('Opslaan van de handtekening is mislukt.', 500);
}

Response::redirect('generate-apparaat-opgehaald.php?id=' . (int) $idValue);
