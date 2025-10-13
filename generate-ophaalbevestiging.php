<?php
declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
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
    $merkmodel = InputValidator::requireString($_POST, 'merkmodel', 120);
    $ophaalcode = InputValidator::requireString($_POST, 'ophaalcode', 32);
    $datumgereed = InputValidator::requireDate($_POST, 'datumgereed');
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

try {
    $statement = $pdo->prepare(
        'INSERT INTO ophaalbevestigingen (klantnaam, merkmodel, ophaalcode, datumgereed) VALUES (:klantnaam, :merkmodel, :ophaalcode, :datumgereed)'
    );
    $statement->execute([
        'klantnaam' => $klantnaam,
        'merkmodel' => $merkmodel,
        'ophaalcode' => $ophaalcode,
        'datumgereed' => $datumgereed,
    ]);

        $insertId = (int) $pdo->lastInsertId();
} catch (\PDOException $exception) {
    Response::error('Opslaan van de ophaalbevestiging is mislukt.', 500);
}

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
