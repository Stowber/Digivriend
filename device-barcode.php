<?php

declare(strict_types=1);

use App\Support\Barcode\BarcodeService;
use App\Support\Repositories\DeviceRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$deviceRepository = new DeviceRepository($pdo);

$barcodeParam = trim((string) ($_GET['barcode'] ?? ''));
$deviceIdParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$device = null;

if ($barcodeParam !== '') {
    $device = $deviceRepository->findWithCustomerByBarcode($barcodeParam);
} elseif ($deviceIdParam) {
    $device = $deviceRepository->findWithCustomer((int) $deviceIdParam);
}

if ($device === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Onbekend apparaat';
    exit;
}

$barcode = $deviceRepository->ensureBarcode((int) $device['id']);

try {
    $image = BarcodeService::renderToPng($barcode);
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Barcode kon niet worden gegenereerd.';
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($image));
echo $image;