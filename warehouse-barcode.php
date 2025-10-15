<?php

declare(strict_types=1);

use App\Support\Barcode\BarcodeService;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$warehouseRepository = new WarehouseRepository($pdo);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$referenceCode = trim((string) ($_GET['reference'] ?? ''));
$item = null;

if ($itemId) {
    $item = $warehouseRepository->findItem((int) $itemId);
} elseif ($referenceCode !== '') {
    $item = $warehouseRepository->findByReference($referenceCode);
}

if ($item === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Magazynowa pozycja nie została znaleziona.';
    exit;
}

try {
    $barcodeValue = $warehouseRepository->ensureBarcode((int) $item['id']);
    $image = BarcodeService::renderToPng($barcodeValue);
} catch (\Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Nie udało się wygenerować kodu kreskowego.';
    exit;
}

header('Content-Type: image/png');
header('Content-Length: ' . strlen($image));
echo $image;