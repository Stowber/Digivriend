<?php

declare(strict_types=1);

use App\Support\Repositories\DevicePhotoRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$photoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$photoId) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Ongeldige foto';
    exit;
}

$photoRepository = new DevicePhotoRepository($pdo);
$photo = $photoRepository->findById((int) $photoId);

if ($photo === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Foto niet gevonden';
    exit;
}

$filePath = __DIR__ . '/storage/' . ltrim((string) $photo['file_path'], '/');
if (!is_file($filePath) || !is_readable($filePath)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Bestand niet gevonden';
    exit;
}

$mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
readfile($filePath);