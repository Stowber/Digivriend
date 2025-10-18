<?php

declare(strict_types=1);

use App\Http\Response;
use App\Support\Repositories\PcBuildRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$pcBuildRepository = new PcBuildRepository($pdo);
$buildStatusLabels = $pcBuildRepository->statusLabels();

$buildId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($buildId === false || $buildId === null || $buildId <= 0) {
    Response::error('Nieprawidłowy identyfikator buildu.', 422);
}

try {
    $details = $pcBuildRepository->buildDetails((int) $buildId);
} catch (Throwable $exception) {
    Response::error('Nie znaleziono buildu PC.', 404);
}

$build = is_array($details['build'] ?? null) ? $details['build'] : [];
$status = (string) ($build['status'] ?? '');
$statusLabel = $buildStatusLabels[$status] ?? $status;
$editable = $status !== 'completed';

$payload = [
    'build' => $build,
    'components' => is_array($details['components'] ?? null) ? $details['components'] : [],
    'leftovers' => is_array($details['leftovers'] ?? null) ? $details['leftovers'] : [],
    'workflow' => is_array($details['workflow'] ?? null) ? $details['workflow'] : [],
    'journal' => is_array($details['journal'] ?? null) ? $details['journal'] : [],
    'documents' => is_array($details['documents'] ?? null) ? $details['documents'] : [],
    'status_label' => $statusLabel,
    'editable' => $editable,
];

header('Content-Type: application/json; charset=utf-8');
echo json_encode($payload, JSON_THROW_ON_ERROR);