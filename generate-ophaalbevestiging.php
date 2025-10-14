<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\View;
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
    $klantnaam = InputValidator::requireString($_POST, 'klantnaam', 120);
    $klantemail = InputValidator::optionalEmail($_POST, 'klantemail', 120);
    $klanttelefoon = InputValidator::optionalPhone($_POST, 'klanttelefoon', 32);
    $apparaatmerk = InputValidator::requireString($_POST, 'apparaatmerk', 120);
    $apparaatmodel = InputValidator::requireString($_POST, 'apparaatmodel', 120);
    $ophaalcode = InputValidator::requireString($_POST, 'ophaalcode', 32);
    $datumgereed = InputValidator::requireDate($_POST, 'datumgereed');
    $opmerkingen = InputValidator::optionalString($_POST, 'opmerkingen', 500);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$merkmodel = trim($apparaatmerk . ' ' . $apparaatmodel);

try {
    $statement = $pdo->prepare(
        'INSERT INTO ophaalbevestigingen (klantnaam, klantemail, klanttelefoon, merkmodel, apparaatmerk, apparaatmodel, ophaalcode, case_reference, datumgereed, status, opmerkingen)
         VALUES (:klantnaam, :klantemail, :klanttelefoon, :merkmodel, :apparaatmerk, :apparaatmodel, :ophaalcode, :case_reference, :datumgereed, :status, :opmerkingen)'
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
    ]);

        $insertId = (int) $pdo->lastInsertId();
} catch (\PDOException $exception) {
    Response::error('Opslaan van de ophaalbevestiging is mislukt.', 500);
}

$customerRepository = new CustomerRepository($pdo);
$deviceRepository = new DeviceRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

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

$documentTitel = 'Ophaalbevestiging';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');

$html = View::render('pdf/ophaalbevestiging.php', [
    'documentTitel' => $documentTitel,
    'bedrijfsNaam' => $bedrijfsNaam,
    'huidigeDatum' => $huidigeDatum,
    'klantnaam' => $klantnaam,
    'ophaalcode' => $ophaalcode,
    'merkmodel' => $merkmodel,
    'datumgereed' => $datumgereed,
]);

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = sprintf('Ophaalbevestiging[%s][%d].pdf', $huidigeDatum, $insertId);
$dompdf->stream($filename, ['Attachment' => true]);
