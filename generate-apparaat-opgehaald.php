<?php
declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

use App\Http\Response;
use App\Support\View;

require __DIR__ . '/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($id === false || $id === null) {
    Response::error('Ongeldig of ontbrekend ID.', 400);
}

try {
    $statement = $pdo->prepare('SELECT * FROM ophaalbevestigingen WHERE id = :id');
    $statement->execute(['id' => $id]);
    $record = $statement->fetch();
} catch (\PDOException $exception) {
    Response::error('Kon de ophaalbevestiging niet ophalen.', 500);
}

if (!$record) {
    Response::error('Geen ophaalbevestiging gevonden.', 404);
}

$klantnaam = htmlspecialchars((string) $record['klantnaam'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$merkmodel = htmlspecialchars((string) $record['merkmodel'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$ophaalcode = htmlspecialchars((string) $record['ophaalcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$datumgereed = htmlspecialchars((string) $record['datumgereed'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$pickupSignature = '';

if (is_string($record['pickup_signature']) && str_starts_with($record['pickup_signature'], 'data:image/')) {
    $pickupSignature = $record['pickup_signature'];
    $parts = explode(',', $pickupSignature, 2);
    if (count($parts) === 2) {
        $decoded = base64_decode($parts[1], true);
        if ($decoded === false || strlen($decoded) > 200_000) {
            $pickupSignature = '';
        }
    }
}

$documentTitel = 'Apparaat Opgehaald';
$bedrijfsNaam = 'Digivriend';
$huidigeDatum = date('d-m-Y');

$html = View::render('pdf/apparaat-opgehaald.php', [
    'documentTitel' => $documentTitel,
    'bedrijfsNaam' => $bedrijfsNaam,
    'huidigeDatum' => $huidigeDatum,
    'klantnaam' => $klantnaam,
    'ophaalcode' => $ophaalcode,
    'merkmodel' => $merkmodel,
    'datumgereed' => $datumgereed,
    'pickupSignature' => $pickupSignature,
]);

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$filename = sprintf('ApparaatOpgehaald[%s][%d].pdf', $huidigeDatum, $id);
$dompdf->stream($filename, ['Attachment' => true]);
