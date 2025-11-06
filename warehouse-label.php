<?php

declare(strict_types=1);

use App\Support\Lang\Translator;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$warehouseRepository = new WarehouseRepository($pdo);

$itemId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($itemId === null || $itemId === false) {
    http_response_code(400);
    echo __('warehouse.errors.label.invalid_id');
    exit;
}

$item = $warehouseRepository->findItem((int) $itemId);
if ($item === null) {
    http_response_code(404);
    echo __('warehouse.errors.label.not_found');
    exit;
}

$barcodeValue = $warehouseRepository->ensureBarcode((int) $item['id']);
$caseId = isset($item['case_id']) ? (int) $item['case_id'] : null;
$caseReference = trim((string) ($item['case_reference_code'] ?? ''));
$customerName = trim((string) ($item['customer_name'] ?? ''));
$statusLabel = $warehouseRepository->statusLabels()[$item['status'] ?? ''] ?? ucfirst((string) ($item['status'] ?? ''));
$quantity = (int) ($item['quantity'] ?? 0);
$referenceCode = trim((string) ($item['reference_code'] ?? ''));
$location = trim((string) ($item['location'] ?? ''));

$barcodeUrl = 'warehouse-barcode.php?id=' . (int) $item['id'];
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('warehouse.label.meta_title', [
      'reference' => $referenceCode !== '' ? $referenceCode : (string) ($item['id'] ?? ''),
  ]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <style>
    body {
      font-family: "Inter", Arial, sans-serif;
      margin: 0;
      padding: 2rem;
      background: #f8fafc;
      color: #0f172a;
    }
    .label {
      width: min(320px, 100%);
      background: #fff;
      border-radius: 16px;
      padding: 1.4rem;
      box-shadow: 0 20px 38px -24px rgba(15, 23, 42, 0.4);
      border: 1px solid rgba(15, 23, 42, 0.12);
    }
    .label h1 {
      font-size: 1.2rem;
      margin: 0 0 0.6rem;
    }
    .label dl {
      margin: 0;
      display: grid;
      gap: 0.35rem;
      font-size: 0.9rem;
    }
    .label dt {
      font-weight: 600;
    }
    .label dd {
      margin: 0;
      color: #334155;
    }
    .barcode {
      margin: 1rem auto;
      text-align: center;
      padding: 0.5rem;
      border: 1px dashed rgba(15, 23, 42, 0.18);
      border-radius: 12px;
      background: rgba(248, 250, 252, 0.8);
    }
    .barcode img {
      max-width: 100%;
      height: auto;
    }
    @media print {
      body {
        padding: 0;
        background: #fff;
      }
      .label {
        box-shadow: none;
        border: 1px solid #000;
        width: 320px;
      }
    }
  </style>
</head>
<body>
  <div class="label">
    <h1><?= htmlspecialchars((string) ($item['name'] ?? __('warehouse.label.default_name')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
    <div class="barcode">
       <img src="<?= htmlspecialchars($barcodeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" alt="<?= htmlspecialchars(__('warehouse.label.barcode_alt'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <div><?= htmlspecialchars($barcodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    </div>
    <dl>
      <?php if ($referenceCode !== ''): ?>
        <div><dt><?= htmlspecialchars(__('warehouse.label.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
      <?php endif; ?>
      <div><dt><?= htmlspecialchars(__('warehouse.label.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
      <div><dt><?= htmlspecialchars(__('warehouse.label.quantity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= (int) $quantity ?></dd></div>
      <?php if ($location !== ''): ?>
        <div><dt><?= htmlspecialchars(__('warehouse.label.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
      <?php endif; ?>
      <?php if ($caseId !== null && $caseId > 0): ?>
        <div><dt><?= htmlspecialchars(__('warehouse.label.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= htmlspecialchars($caseReference !== ''
            ? __('warehouse.label.case_with_reference', ['number' => $caseId, 'reference' => $caseReference])
            : __('warehouse.label.case_basic', ['number' => $caseId]),
            ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
      <?php endif; ?>
      <?php if ($customerName !== ''): ?>
        <div><dt><?= htmlspecialchars(__('warehouse.label.customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt><dd><?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd></div>
      <?php endif; ?>
    </dl>
  </div>
</body>
</html>