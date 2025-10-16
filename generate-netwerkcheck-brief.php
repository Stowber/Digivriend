<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Csrf;
use App\Support\Documents\DocumentRepository;
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
    $letterDate = InputValidator::requireDate($_POST, 'letter_date');
    $startDate = InputValidator::requireDate($_POST, 'start_date');
    $endDate = InputValidator::requireDate($_POST, 'end_date');
    $area = InputValidator::requireString($_POST, 'area', 150);
    $city = InputValidator::optionalString($_POST, 'city', 120);
    $focusLine = InputValidator::requireString($_POST, 'focus_line', 200);
    $salutation = InputValidator::requireString($_POST, 'salutation', 150);
    $timeSlotsRaw = InputValidator::requireString($_POST, 'time_slots', 800);
    $additionalNote = InputValidator::optionalString($_POST, 'additional_note', 400);
    $contactName = InputValidator::requireString($_POST, 'contact_name', 120);
    $contactRole = InputValidator::optionalString($_POST, 'contact_role', 150);
    $contactPhone = InputValidator::optionalString($_POST, 'contact_phone', 64);
    $contactEmail = InputValidator::optionalEmail($_POST, 'contact_email', 180);
    $contactUrl = InputValidator::optionalString($_POST, 'contact_url', 200);
    $signatureName = InputValidator::requireString($_POST, 'signature_name', 120);
    $signatureRole = InputValidator::requireString($_POST, 'signature_role', 150);
} catch (ValidationException $exception) {
    Response::error($exception->errors(), 422);
}

$rsvpDeadline = '';
if (!empty($_POST['rsvp_deadline'])) {
    try {
        $rsvpDeadline = InputValidator::requireDate($_POST, 'rsvp_deadline');
    } catch (ValidationException $exception) {
        Response::error($exception->errors(), 422);
    }
}

$monthNames = [
    '01' => 'januari',
    '02' => 'februari',
    '03' => 'maart',
    '04' => 'april',
    '05' => 'mei',
    '06' => 'juni',
    '07' => 'juli',
    '08' => 'augustus',
    '09' => 'september',
    '10' => 'oktober',
    '11' => 'november',
    '12' => 'december',
];

$formatDutchDateShort = static function (string $dateString) use ($monthNames): string {
    $date = date_create_immutable($dateString);
    if (!$date instanceof DateTimeImmutable) {
        return $dateString;
    }

    $monthKey = $date->format('m');
    $month = $monthNames[$monthKey] ?? strtolower($date->format('F'));

    return sprintf('%s %s %s', $date->format('j'), $month, $date->format('Y'));
};

$startDateHuman = $formatDutchDateShort($startDate);
$endDateHuman = $formatDutchDateShort($endDate);
$letterDateHuman = $formatDutchDateShort($letterDate);
$rsvpDeadlineHuman = $rsvpDeadline !== '' ? $formatDutchDateShort($rsvpDeadline) : '';

$periodSummary = $startDate === $endDate
    ? sprintf('op %s', $startDateHuman)
    : sprintf('tussen %s en %s', $startDateHuman, $endDateHuman);

$areaSummary = $city !== '' ? sprintf('%s, %s', $area, $city) : $area;

$timeSlotLines = preg_split("/(\r\n|\r|\n)/", html_entity_decode($timeSlotsRaw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?: [];
$timeSlots = array_values(array_filter(array_map(
    static function (string $line): string {
        $trimmed = trim($line);
        if ($trimmed === '') {
            return '';
        }

        return htmlspecialchars($trimmed, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    },
    $timeSlotLines
)));

if ($timeSlots === []) {
    Response::error(['time_slots' => 'Voeg minimaal één tijdslot toe.'], 422);
}

$additionalNoteHtml = $additionalNote !== '' ? nl2br($additionalNote) : '';

$documentRepository = new DocumentRepository($pdo);

$html = View::render('pdf/netwerkcheck-brief.php', [
    'bedrijfsNaam' => 'Digivriend',
    'documentTitel' => 'Buurtbrief netwerkscan',
    'focusLine' => $focusLine,
    'letterDateHuman' => $letterDateHuman,
    'salutation' => $salutation,
    'periodSummary' => $periodSummary,
    'areaSummary' => $areaSummary,
    'timeSlots' => $timeSlots,
    'contactName' => $contactName,
    'contactRole' => $contactRole,
    'contactPhone' => $contactPhone,
    'contactEmail' => $contactEmail,
    'contactUrl' => $contactUrl,
    'signatureName' => $signatureName,
    'signatureRole' => $signatureRole,
    'rsvpDeadlineHuman' => $rsvpDeadlineHuman,
    'additionalNoteHtml' => $additionalNoteHtml,
    'startDateHuman' => $startDateHuman,
    'endDateHuman' => $endDateHuman,
]);

$options = new Options();
$options->set('isRemoteEnabled', true);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = sprintf('Netwerkcheck-brief[%s].pdf', date('Ymd_His'));
$pdfContent = $dompdf->output();

$documentDirectory = __DIR__ . '/storage/documents';
if (!is_dir($documentDirectory)) {
    mkdir($documentDirectory, 0775, true);
}

$storagePath = sprintf('storage/documents/%s', $filename);
file_put_contents(__DIR__ . '/' . $storagePath, $pdfContent);

$documentRepository->store(
    null,
    'netwerkcheck_brief',
    $storagePath,
    [
        'area' => $areaSummary,
        'start_date' => $startDate,
        'end_date' => $endDate,
        'letter_date' => $letterDate,
        'contact_name' => $contactName,
    ]
);

$dompdf->stream($filename, ['Attachment' => true]);