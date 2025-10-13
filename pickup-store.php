<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
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
        'UPDATE ophaalbevestigingen SET pickup_signature = :signature, status = :status WHERE id = :id'
    );
    $statement->execute([
        'signature' => $signatureData,
        'status' => 'opgehaald',
        'id' => (int) $idValue,
    ]);
} catch (\PDOException $exception) {
    Response::error('Opslaan van de handtekening is mislukt.', 500);
}

Response::redirect('generate-apparaat-opgehaald.php?id=' . (int) $idValue);
