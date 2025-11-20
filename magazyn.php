<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$warehouseRepository = new WarehouseRepository($pdo);

$prefillCaseIdRaw = filter_input(INPUT_GET, 'case', FILTER_VALIDATE_INT);
$prefillCaseId = $prefillCaseIdRaw !== false && $prefillCaseIdRaw !== null ? (int) $prefillCaseIdRaw : null;

$statusFilterRaw = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS);
$statusFilter = is_string($statusFilterRaw) ? trim($statusFilterRaw) : null;
$searchTerm = trim((string) ($_GET['q'] ?? ''));

$statusLabels = $warehouseRepository->statusLabels();
$movementLabels = $warehouseRepository->movementLabels();

$errors = [
    'create' => [],
    'status' => [],
    'movement' => [],
];

$successMessage = null;
if (isset($_SESSION['warehouse_success'])) {
    $successMessage = (string) $_SESSION['warehouse_success'];
    unset($_SESSION['warehouse_success']);
}

$createValues = [
    'name' => '',
    'quantity' => 1,
    'category' => '',
    'status' => 'received',
    'case_id' => $prefillCaseId,
    'location' => '',
    'notes' => '',
    'reference_code' => '',
];

$statusValues = [
    'item_id' => '',
    'status' => 'received',
    'case_id' => $prefillCaseId,
    'location' => '',
    'notes' => '',
];

$movementValues = [
    'item_id' => '',
    'movement_type' => 'inbound',
    'quantity' => 1,
    'case_id' => $prefillCaseId,
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $target = &$errors['create'];
        if ($action === 'update-status') {
            $target = &$errors['status'];
        } elseif ($action === 'record-movement') {
            $target = &$errors['movement'];
        }
        $target['general'] = __('messages.session_expired');
    } else {
        switch ($action) {
            case 'create-item':
                $createValues['name'] = trim((string) ($_POST['name'] ?? ''));
                $createValues['category'] = trim((string) ($_POST['category'] ?? ''));
                $createValues['status'] = trim((string) ($_POST['status'] ?? 'received'));
                $createValues['location'] = trim((string) ($_POST['location'] ?? ''));
                $createValues['notes'] = trim((string) ($_POST['notes'] ?? ''));
                $createValues['reference_code'] = trim((string) ($_POST['reference_code'] ?? ''));

                $quantityInput = $_POST['quantity'] ?? 1;
                $quantity = filter_var($quantityInput, FILTER_VALIDATE_INT);
                if ($quantity === false || $quantity < 0) {
                    $errors['create']['quantity'] = __('warehouse.errors.create.quantity');
                } else {
                    $createValues['quantity'] = $quantity;
                }

                if ($createValues['name'] === '') {
                    $errors['create']['name'] = __('warehouse.errors.create.name_required');
                }

                if (!$warehouseRepository->isValidStatus($createValues['status'])) {
                    $errors['create']['status'] = __('warehouse.errors.common.invalid_status');
                }

                $caseIdInput = $_POST['case_id'] ?? null;
                $caseId = filter_var($caseIdInput, FILTER_VALIDATE_INT);
                if ($caseId === false) {
                    $caseId = null;
                }
                $createValues['case_id'] = $caseId;

                if ($errors['create'] === []) {
                    try {
                        $item = $warehouseRepository->createItem(
                            $createValues['name'],
                            $createValues['quantity'],
                            $createValues['category'] !== '' ? $createValues['category'] : null,
                            $createValues['location'] !== '' ? $createValues['location'] : null,
                            $caseId,
                            $createValues['notes'] !== '' ? $createValues['notes'] : null,
                            $createValues['status'],
                            $createValues['reference_code'] !== '' ? $createValues['reference_code'] : null,
                            Auth::username()
                        );

                        $itemName = trim((string) ($item['name'] ?? ''));
                        if ($itemName === '') {
                            $itemName = __('warehouse.messages.create.default_name');
                        }

                        $itemReference = trim((string) ($item['reference_code'] ?? ''));
                        if ($itemReference === '') {
                            $itemReference = __('warehouse.messages.create.reference_unknown');
                        }

                        $_SESSION['warehouse_success'] = __('warehouse.messages.create.success', [
                            'name' => $itemName,
                            'reference' => $itemReference,
                        ]);
                        $redirectTarget = 'magazyn.php?highlight=' . (int) ($item['id'] ?? 0);
                        if ($caseId !== null) {
                            $redirectTarget .= '&case=' . $caseId;
                        }
                        Response::redirect($redirectTarget);
                    } catch (\Throwable $exception) {
                        $errors['create']['general'] = __('warehouse.errors.create.general');
                    }
                }
                break;

            case 'update-status':
                $statusValues['item_id'] = trim((string) ($_POST['item_id'] ?? ''));
                $statusValues['status'] = trim((string) ($_POST['status'] ?? ''));
                $statusValues['location'] = trim((string) ($_POST['location'] ?? ''));
                $statusValues['notes'] = trim((string) ($_POST['notes'] ?? ''));

                $statusItemId = filter_var($statusValues['item_id'], FILTER_VALIDATE_INT);
                if ($statusItemId === false || $statusItemId <= 0) {
                    $errors['status']['item_id'] = __('warehouse.errors.status.item_required');
                }

                if (!$warehouseRepository->isValidStatus($statusValues['status'])) {
                    $errors['status']['status'] = __('warehouse.errors.common.invalid_status');
                }

                $statusCaseIdInput = $_POST['case_id'] ?? null;
                $statusCaseId = filter_var($statusCaseIdInput, FILTER_VALIDATE_INT);
                if ($statusCaseId === false) {
                    $statusCaseId = null;
                }
                $statusValues['case_id'] = $statusCaseId;

                if ($errors['status'] === []) {
                    try {
                        $updated = $warehouseRepository->updateStatus(
                            (int) $statusItemId,
                            $statusValues['status'],
                            $statusCaseId,
                            $statusValues['location'],
                            $statusValues['notes']
                        );
                        if ($updated) {
                            $_SESSION['warehouse_success'] = __('warehouse.messages.status.success');
                            $redirectTarget = 'magazyn.php?highlight=' . (int) $statusItemId;
                            if ($statusCaseId !== null) {
                                $redirectTarget .= '&case=' . $statusCaseId;
                            }
                            Response::redirect($redirectTarget);
                        } else {
                            $errors['status']['general'] = __('warehouse.errors.status.failed');
                        }
                    } catch (\Throwable $exception) {
                        $errors['status']['general'] = __('warehouse.errors.status.exception');
                    }
                }
                break;

            case 'record-movement':
                $movementValues['item_id'] = trim((string) ($_POST['item_id'] ?? ''));
                $movementValues['movement_type'] = trim((string) ($_POST['movement_type'] ?? 'inbound'));
                $movementValues['quantity'] = $_POST['quantity'] ?? 1;
                $movementValues['notes'] = trim((string) ($_POST['notes'] ?? ''));

                $movementItemId = filter_var($movementValues['item_id'], FILTER_VALIDATE_INT);
                if ($movementItemId === false || $movementItemId <= 0) {
                    $errors['movement']['item_id'] = __('warehouse.errors.movement.item_required');
                }

                if (!$warehouseRepository->isValidMovementType($movementValues['movement_type'])) {
                    $errors['movement']['movement_type'] = __('warehouse.errors.movement.type');
                }

                $movementQuantity = null;
                if ($movementValues['movement_type'] === 'adjustment') {
                    $movementQuantity = filter_var($movementValues['quantity'], FILTER_VALIDATE_INT);
                    if ($movementQuantity === false || $movementQuantity === 0) {
                        $errors['movement']['quantity'] = __('warehouse.errors.movement.adjustment_quantity');
                    }
                } else {
                    $quantityPositive = filter_var($movementValues['quantity'], FILTER_VALIDATE_INT);
                    if ($quantityPositive === false || $quantityPositive <= 0) {
                        $errors['movement']['quantity'] = __('warehouse.errors.movement.positive_quantity');
                    } else {
                        $movementQuantity = $quantityPositive;
                    }
                }

                $movementCaseIdInput = $_POST['case_id'] ?? null;
                $movementCaseId = filter_var($movementCaseIdInput, FILTER_VALIDATE_INT);
                if ($movementCaseId === false) {
                    $movementCaseId = null;
                }
                $movementValues['case_id'] = $movementCaseId;

                if ($errors['movement'] === [] && $movementQuantity !== null && $movementItemId !== false) {
                    try {
                        $recorded = $warehouseRepository->recordMovement(
                            (int) $movementItemId,
                            $movementValues['movement_type'],
                            $movementValues['movement_type'] === 'adjustment' ? (int) $movementQuantity : (int) $movementQuantity,
                            $movementCaseId,
                            $movementValues['notes'] !== '' ? $movementValues['notes'] : null,
                            Auth::username()
                        );
                        if ($recorded) {
                            $_SESSION['warehouse_success'] = __('warehouse.messages.movement.success');
                            $redirectTarget = 'magazyn.php?highlight=' . (int) $movementItemId . '#historia';
                            if ($movementCaseId !== null) {
                                $redirectTarget .= '&case=' . $movementCaseId;
                            }
                            Response::redirect($redirectTarget);
                        } else {
                            $errors['movement']['general'] = __('warehouse.errors.movement.failed');
                        }
                    } catch (\Throwable $exception) {
                        $errors['movement']['general'] = __('warehouse.errors.movement.exception');
                    }
                }
                break;

            default:
                $errors['create']['general'] = __('warehouse.errors.unknown_action');
        }
    }
}

if ($statusFilter !== null && $statusFilter !== '' && !$warehouseRepository->isValidStatus($statusFilter)) {
    $statusFilter = null;
}

$items = $warehouseRepository->listItems($statusFilter, $searchTerm !== '' ? $searchTerm : null, 160);
$statusCounts = $warehouseRepository->statusCounts();
$totalItems = $warehouseRepository->totalItems();
$totalQuantity = $warehouseRepository->totalQuantity();
$totalReserved = $warehouseRepository->totalReserved();
$readyCount = $statusCounts['ready'] ?? 0;
$completedCount = $statusCounts['completed'] ?? 0;
$receivedCount = $statusCounts['received'] ?? 0;
$reservedCount = $statusCounts['reserved'] ?? 0;
$inServiceCount = $statusCounts['in_service'] ?? 0;
$availableQuantity = max(0, $totalQuantity - $totalReserved);

$itemOptions = $warehouseRepository->itemOptions();
$caseOptions = $warehouseRepository->caseOptions();
$recentMovements = $warehouseRepository->recentMovements(12);

$csrfToken = Csrf::token();
$highlightIdRaw = filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);
$highlightId = $highlightIdRaw !== false && $highlightIdRaw !== null ? (int) $highlightIdRaw : null;
$modalShouldOpen = $_SERVER['REQUEST_METHOD'] === 'POST' && (
    $errors['create'] !== [] ||
    $errors['status'] !== [] ||
    $errors['movement'] !== []
);

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars(__('warehouse.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/warehouse.css">
</head>
<body<?= platform_body_attributes(); ?>>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('inventory'); ?>
      </nav>
    </div>
  </header>

  <main class="container warehouse">
    <div class="warehouse__header">
      <div>
        <h1><?= htmlspecialchars(__('warehouse.header.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(__('warehouse.header.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="warehouse-actions">
        <button type="button" class="btn" data-open-intake><?= htmlspecialchars(__('warehouse.header.actions.open_modal'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
        <a class="btn btn--ghost" href="index.php"><?= htmlspecialchars(__('warehouse.header.actions.back'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
      </div>
    </div>

    <?php if ($successMessage !== null): ?>
      <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="warehouse__stats">
      <article class="warehouse__stat">
        <h3><?= htmlspecialchars(__('warehouse.stats.items.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
        <strong><?= number_format($totalItems, 0, ',', ' ') ?></strong>
        <span><?= htmlspecialchars(__('warehouse.stats.items.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </article>
      <article class="warehouse__stat">
        <h3><?= htmlspecialchars(__('warehouse.stats.available.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
        <strong><?= number_format($availableQuantity, 0, ',', ' ') ?></strong>
        <span><?= htmlspecialchars(__('warehouse.stats.available.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </article>
      <article class="warehouse__stat">
        <h3><?= htmlspecialchars(__('warehouse.stats.reserved.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
        <strong><?= number_format($totalReserved, 0, ',', ' ') ?></strong>
        <span><?= htmlspecialchars(__('warehouse.stats.reserved.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </article>
      <article class="warehouse__stat">
        <h3><?= htmlspecialchars(__('warehouse.stats.ready.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
        <strong><?= number_format($readyCount, 0, ',', ' ') ?></strong>
        <span><?= htmlspecialchars(__('warehouse.stats.ready.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
      </article>
    </section>

    <section class="warehouse__layout">
      <div class="warehouse__main">
        <section class="card warehouse-card">
          <div class="warehouse__filters">
            <form method="get" aria-label="<?= htmlspecialchars(__('warehouse.filters.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <div>
                <label for="status"><?= htmlspecialchars(__('warehouse.filters.status.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <select id="status" name="status">
                  <option value=""><?= htmlspecialchars(__('warehouse.filters.status.all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                    <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="q"><?= htmlspecialchars(__('warehouse.filters.search.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
                <input id="q" type="text" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('warehouse.filters.search.placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </div>
              <?php if ($prefillCaseId !== null): ?>
                <input type="hidden" name="case" value="<?= (int) $prefillCaseId ?>">
              <?php endif; ?>
              <button type="submit" class="btn"><?= htmlspecialchars(__('warehouse.filters.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>
          </div>

          <div class="table-wrapper">
            <table class="warehouse-table">
              <thead>
                <tr>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.quantity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  <th><?= htmlspecialchars(__('warehouse.table.headers.actions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                </tr>
              </thead>
              <tbody>
                <?php if ($items === []): ?>
                  <tr>
                    <td colspan="7"><?= htmlspecialchars(__('warehouse.table.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($items as $item): ?>
                    <?php
                      $rowClass = $highlightId !== null && $highlightId === (int) ($item['id'] ?? 0) ? ' class="highlight"' : '';
                      $statusKey = (string) ($item['status'] ?? '');
                      $statusLabel = $statusLabels[$statusKey] ?? ucfirst($statusKey);
                      $caseId = isset($item['case_id']) ? (int) $item['case_id'] : null;
                      $lastMovement = (string) ($item['last_movement_at'] ?? $item['updated_at'] ?? '');
                      $timestamp = $lastMovement !== '' ? date('d-m-Y H:i', strtotime($lastMovement)) : '—';
                      $quantity = (int) ($item['quantity'] ?? 0);
                      $reserved = (int) ($item['reserved_quantity'] ?? 0);
                      $location = trim((string) ($item['location'] ?? ''));
                      $referenceCode = trim((string) ($item['reference_code'] ?? ''));
                      $barcode = trim((string) ($item['barcode'] ?? ''));
                    ?>
                    <tr<?= $rowClass ?>>
                      <td>
                        <strong><?= htmlspecialchars((string) ($item['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <?php if ($referenceCode !== ''): ?>
                          <small><?= htmlspecialchars(__('warehouse.table.reference_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if ($barcode !== ''): ?>
                          <small><?= htmlspecialchars(__('warehouse.table.barcode_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars($barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if (!empty($item['category'])): ?>
                          <small><?= htmlspecialchars(__('warehouse.table.category_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $item['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="status-badge status-badge--<?= htmlspecialchars(str_replace('-', '_', $statusKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </td>
                      <td>
                        <?= number_format($quantity, 0, ',', ' ') ?>
                        <small><?= htmlspecialchars(__('warehouse.table.reserved_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= number_format($reserved, 0, ',', ' ') ?></small>
                      </td>
                      <td><?= $location !== '' ? htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                      <td>
                        <?php if ($caseId !== null && $caseId > 0): ?>
                          <a href="case.php?id=<?= $caseId ?>"><?= htmlspecialchars(__('warehouse.table.case_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $caseId ?></a>
                          <?php if (!empty($item['customer_name'])): ?>
                            <small><?= htmlspecialchars((string) $item['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="muted"><?= htmlspecialchars(__('warehouse.table.no_link'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($timestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td>
                        <div class="warehouse-actions">
                          <a class="btn btn--ghost" href="warehouse-label.php?id=<?= (int) ($item['id'] ?? 0) ?>" target="_blank" rel="noopener"><?= htmlspecialchars(__('warehouse.table.actions.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                          <?php if ($caseId !== null && $caseId > 0): ?>
                            <a class="btn btn--ghost" href="case.php?id=<?= $caseId ?>"><?= htmlspecialchars(__('warehouse.table.actions.case_details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a>
                          <?php endif; ?>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </section>

        <section class="card warehouse-card" id="historia">
          <h2><?= htmlspecialchars(__('warehouse.history.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <?php if (!empty($errors['movement']['general']) && empty($successMessage)): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($errors['movement']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <div class="warehouse-history">
            <?php if ($recentMovements === []): ?>
              <p class="muted"><?= htmlspecialchars(__('warehouse.history.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php else: ?>
              <?php foreach ($recentMovements as $movement): ?>
                <?php
                  $movementType = (string) ($movement['movement_type'] ?? '');
                  $movementLabel = $movementLabels[$movementType] ?? ucfirst($movementType);
                  $movementQuantity = (int) ($movement['quantity'] ?? 0);
                  if (in_array($movementType, ['outbound', 'release'], true)) {
                      $movementQuantity *= -1;
                  }
                  $movementCaseId = isset($movement['case_id']) ? (int) $movement['case_id'] : null;
                  $movementTime = (string) ($movement['created_at'] ?? '');
                  $movementTimestamp = $movementTime !== '' ? date('d-m-Y H:i', strtotime($movementTime)) : '';
                ?>
                <article class="warehouse-history__item">
                  <strong><?= htmlspecialchars($movementLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $movementQuantity !== 0 ? ' (' . ($movementQuantity > 0 ? '+' : '') . number_format($movementQuantity, 0, ',', ' ') . ')' : '' ?></strong>
                  <?php if (!empty($movement['item_name'])): ?>
                    <div><?= htmlspecialchars(__('warehouse.history.item_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $movement['item_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($movement['item_reference'])): ?> · <?= htmlspecialchars(__('warehouse.history.reference_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $movement['item_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></div>
                  <?php endif; ?>
                  <?php if ($movementCaseId !== null && $movementCaseId > 0): ?>
                    <div><?= htmlspecialchars(__('warehouse.history.link_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <a href="case.php?id=<?= $movementCaseId ?>"><?= htmlspecialchars(__('warehouse.table.case_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= $movementCaseId ?></a><?php if (!empty($movement['customer_name'])): ?> · <?= htmlspecialchars((string) $movement['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></div>
                  <?php endif; ?>
                  <?php if (!empty($movement['notes'])): ?>
                    <div><?= htmlspecialchars(__('warehouse.history.notes_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= nl2br(htmlspecialchars((string) $movement['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                  <?php endif; ?>
                  <time datetime="<?= htmlspecialchars((string) $movement['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($movementTimestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                  <?php if (!empty($movement['performed_by'])): ?>
                    <small><?= htmlspecialchars(__('warehouse.history.performed_by_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $movement['performed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>

       </section>
  </main>

  <div
    class="intake-modal"
    id="warehouse-intake-modal"
    aria-hidden="true"
    data-open-on-load="<?= $modalShouldOpen ? 'true' : 'false' ?>"
  >
    <div class="intake-modal__backdrop" data-close-intake></div>
    <div class="intake-modal__dialog" role="document" aria-modal="true" aria-labelledby="warehouse-intake-title">
      <header class="intake-modal__header">
        <div>
          <span class="intake-modal__eyebrow"><?= htmlspecialchars(__('warehouse.modal.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <h2 id="warehouse-intake-title"><?= htmlspecialchars(__('warehouse.modal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(__('warehouse.modal.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <button type="button" class="intake-modal__close" aria-label="<?= htmlspecialchars(__('warehouse.modal.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" data-close-intake>&times;</button>
      </header>

      <section class="intake-modal__summary" aria-label="<?= htmlspecialchars(__('warehouse.modal.summary.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="intake-modal__summary-item">
          <span><?= htmlspecialchars(__('warehouse.modal.summary.received'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= number_format($receivedCount, 0, ',', ' ') ?></strong>
        </div>
        <div class="intake-modal__summary-item">
          <span><?= htmlspecialchars(__('warehouse.modal.summary.reserved'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= number_format($reservedCount, 0, ',', ' ') ?></strong>
        </div>
        <div class="intake-modal__summary-item">
          <span><?= htmlspecialchars(__('warehouse.modal.summary.in_service'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= number_format($inServiceCount, 0, ',', ' ') ?></strong>
        </div>
        <div class="intake-modal__summary-item">
          <span><?= htmlspecialchars(__('warehouse.modal.summary.completed'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <strong><?= number_format($completedCount, 0, ',', ' ') ?></strong>
        </div>
      </section>

        <div class="intake-modal__body">
        <nav class="intake-modal__steps" aria-label="<?= htmlspecialchars(__('warehouse.modal.steps.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <button type="button" class="intake-modal__step is-active" data-intake-step="intake">
            <span class="intake-modal__step-number">1</span>
            <div>
              <strong><?= htmlspecialchars(__('warehouse.modal.steps.intake.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <small><?= htmlspecialchars(__('warehouse.modal.steps.intake.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
            </div>
          </button>
          <button type="button" class="intake-modal__step" data-intake-step="status">
            <span class="intake-modal__step-number">2</span>
            <div>
              <strong><?= htmlspecialchars(__('warehouse.modal.steps.status.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <small><?= htmlspecialchars(__('warehouse.modal.steps.status.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
            </div>
          </button>
          <button type="button" class="intake-modal__step" data-intake-step="movement">
            <span class="intake-modal__step-number">3</span>
            <div>
              <strong><?= htmlspecialchars(__('warehouse.modal.steps.movement.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
              <small><?= htmlspecialchars(__('warehouse.modal.steps.movement.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
            </div>
          </button>
        </nav>

        <div class="intake-modal__panels">
          <section class="intake-modal__panel is-active" data-intake-panel="intake">
            <article class="operations-step">
              <header class="operations-step__header">
                <span class="operations-step__badge"><?= htmlspecialchars(__('warehouse.modal.steps.intake.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div>
                  <h3><?= htmlspecialchars(__('warehouse.modal.steps.intake.header.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <p><?= htmlspecialchars(__('warehouse.modal.steps.intake.header.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                </header>
              <?php if (!empty($errors['create']['general'])): ?>
                <div class="alert alert--danger"><?= htmlspecialchars($errors['create']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
              <form method="post" class="operations-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="create-item">

                <fieldset class="operations-form__group">
                  <legend><?= htmlspecialchars(__('warehouse.modal.steps.intake.form.basic_legend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="text" name="name" value="<?= htmlspecialchars($createValues['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
                      <?php if (!empty($errors['create']['name'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.quantity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="number" min="0" name="quantity" value="<?= (int) $createValues['quantity'] ?>">
                      <?php if (!empty($errors['create']['quantity'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                  </div>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="status">
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                          <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $createValues['status'] === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($errors['create']['status'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.category'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="text" name="category" value="<?= htmlspecialchars($createValues['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.intake.form.category_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </label>
                  </div>
                </fieldset>

                <fieldset class="operations-form__group">
                  <legend><?= htmlspecialchars(__('warehouse.modal.steps.intake.form.logistics_legend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="text" name="location" value="<?= htmlspecialchars($createValues['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.intake.form.location_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="case_id">
                        <option value=""><?= htmlspecialchars(__('warehouse.modal.common.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php foreach ($caseOptions as $caseOption): ?>
                          <?php
                            $caseOptionId = (int) ($caseOption['id'] ?? 0);
                            $caseOptionName = trim((string) ($caseOption['full_name'] ?? ''));
                            $caseOptionLabel = __('warehouse.table.case_prefix') . $caseOptionId;
                            if ($caseOptionName !== '') {
                                $caseOptionLabel .= ' · ' . $caseOptionName;
                            }
                          ?>
                          <option value="<?= $caseOptionId ?>"<?= $createValues['case_id'] === $caseOptionId ? ' selected' : '' ?>><?= htmlspecialchars($caseOptionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.reference'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="text" name="reference_code" value="<?= htmlspecialchars($createValues['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.intake.form.reference_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </label>
                    <label class="operations-form__label--notes">
                      <?= htmlspecialchars(__('warehouse.modal.steps.intake.form.notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <textarea name="notes" rows="3" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.intake.form.notes_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($createValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </label>
                  </div>
                </fieldset>

                <div class="operations-step__footer">
                  <div>
                    <span class="operations-step__hint-title"><?= htmlspecialchars(__('warehouse.modal.steps.intake.checklist.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <ul class="operations-step__checklist">
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.intake.checklist.items.0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.intake.checklist.items.1'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.intake.checklist.items.2'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    </ul>
                  </div>
                  <button type="submit" class="btn"><?= htmlspecialchars(__('warehouse.modal.steps.intake.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
              </form>
            </article>
          </section>

          <section class="intake-modal__panel" data-intake-panel="status">
            <article class="operations-step">
              <header class="operations-step__header">
                <span class="operations-step__badge"><?= htmlspecialchars(__('warehouse.modal.steps.status.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div>
                  <h3><?= htmlspecialchars(__('warehouse.modal.steps.status.header.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <p><?= htmlspecialchars(__('warehouse.modal.steps.status.header.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                </header>
              <?php if (!empty($errors['status']['general'])): ?>
                <div class="alert alert--danger"><?= htmlspecialchars($errors['status']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
              <form method="post" class="operations-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="update-status">

                <fieldset class="operations-form__group">
                  <legend><?= htmlspecialchars(__('warehouse.modal.steps.status.form.current_legend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.status.form.item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="item_id" required>
                        <option value=""><?= htmlspecialchars(__('warehouse.modal.steps.status.form.item_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php foreach ($itemOptions as $option): ?>
                          <?php $optionId = (int) ($option['id'] ?? 0); ?>
                          <option value="<?= $optionId ?>"<?= (string) $statusValues['item_id'] === (string) $optionId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($option['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($option['barcode'])): ?> · <?= htmlspecialchars((string) $option['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($errors['status']['item_id'])): ?><span class="form-error"><?= htmlspecialchars($errors['status']['item_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.status.form.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="status" required>
                        <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                          <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $statusValues['status'] === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($errors['status']['status'])): ?><span class="form-error"><?= htmlspecialchars($errors['status']['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                  </div>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.status.form.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="case_id">
                        <option value=""><?= htmlspecialchars(__('warehouse.modal.common.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php foreach ($caseOptions as $caseOption): ?>
                          <?php
                            $caseOptionId = (int) ($caseOption['id'] ?? 0);
                            $caseOptionName = trim((string) ($caseOption['full_name'] ?? ''));
                            $caseOptionLabel = __('warehouse.table.case_prefix') . $caseOptionId;
                            if ($caseOptionName !== '') {
                                $caseOptionLabel .= ' · ' . $caseOptionName;
                            }
                          ?>
                          <option value="<?= $caseOptionId ?>"<?= $statusValues['case_id'] === $caseOptionId ? ' selected' : '' ?>><?= htmlspecialchars($caseOptionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.status.form.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="text" name="location" value="<?= htmlspecialchars($statusValues['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.status.form.location_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    </label>
                  </div>
                  <label class="operations-form__label--notes">
                    <?= htmlspecialchars(__('warehouse.modal.steps.status.form.notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <textarea name="notes" rows="3" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.status.form.notes_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  </label>
                </fieldset>

                <div class="operations-step__footer">
                  <div>
                    <span class="operations-step__hint-title"><?= htmlspecialchars(__('warehouse.modal.steps.status.checklist.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <ul class="operations-step__checklist">
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.status.checklist.items.0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.status.checklist.items.1'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.status.checklist.items.2'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    </ul>
                  </div>
                  <button type="submit" class="btn"><?= htmlspecialchars(__('warehouse.modal.steps.status.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
                </form>
            </article>
          </section>

          <section class="intake-modal__panel" data-intake-panel="movement">
            <article class="operations-step">
              <header class="operations-step__header">
                <span class="operations-step__badge"><?= htmlspecialchars(__('warehouse.modal.steps.movement.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <div>
                  <h3><?= htmlspecialchars(__('warehouse.modal.steps.movement.header.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <p><?= htmlspecialchars(__('warehouse.modal.steps.movement.header.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                </div>
                 </header>
              <?php if (!empty($errors['movement']['general'])): ?>
                <div class="alert alert--danger"><?= htmlspecialchars($errors['movement']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
              <?php endif; ?>
              <form method="post" class="operations-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="record-movement">

                <fieldset class="operations-form__group">
                  <legend><?= htmlspecialchars(__('warehouse.modal.steps.movement.form.legend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.movement.form.item'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="item_id" required>
                        <option value=""><?= htmlspecialchars(__('warehouse.modal.steps.movement.form.item_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php foreach ($itemOptions as $option): ?>
                          <?php $optionId = (int) ($option['id'] ?? 0); ?>
                          <option value="<?= $optionId ?>"<?= (string) $movementValues['item_id'] === (string) $optionId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($option['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($option['barcode'])): ?> · <?= htmlspecialchars((string) $option['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($errors['movement']['item_id'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['item_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.movement.form.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="movement_type" required>
                        <?php foreach ($movementLabels as $movementKey => $movementLabel): ?>
                          <option value="<?= htmlspecialchars((string) $movementKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $movementValues['movement_type'] === $movementKey ? ' selected' : '' ?>><?= htmlspecialchars($movementLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                      <?php if (!empty($errors['movement']['movement_type'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['movement_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                  </div>
                  <div class="operations-form__row operations-form__row--two">
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.movement.form.quantity'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <input type="number" name="quantity" value="<?= htmlspecialchars((string) $movementValues['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <?php if (!empty($errors['movement']['quantity'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
                    </label>
                    <label>
                      <?= htmlspecialchars(__('warehouse.modal.steps.movement.form.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <select name="case_id">
                        <option value=""><?= htmlspecialchars(__('warehouse.modal.common.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php foreach ($caseOptions as $caseOption): ?>
                          <?php
                            $caseOptionId = (int) ($caseOption['id'] ?? 0);
                            $caseOptionName = trim((string) ($caseOption['full_name'] ?? ''));
                            $caseOptionLabel = __('warehouse.table.case_prefix') . $caseOptionId;
                            if ($caseOptionName !== '') {
                                $caseOptionLabel .= ' · ' . $caseOptionName;
                            }
                          ?>
                          <option value="<?= $caseOptionId ?>"<?= $movementValues['case_id'] === $caseOptionId ? ' selected' : '' ?>><?= htmlspecialchars($caseOptionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                  </div>
                  <label class="operations-form__label--notes">
                    <?= htmlspecialchars(__('warehouse.modal.steps.movement.form.notes'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <textarea name="notes" rows="3" placeholder="<?= htmlspecialchars(__('warehouse.modal.steps.movement.form.notes_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($movementValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                  </label>
                </fieldset>

                <div class="operations-step__footer">
                  <div>
                    <span class="operations-step__hint-title"><?= htmlspecialchars(__('warehouse.modal.steps.movement.checklist.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <ul class="operations-step__checklist">
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.movement.checklist.items.0'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.movement.checklist.items.1'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                      <li><?= htmlspecialchars(__('warehouse.modal.steps.movement.checklist.items.2'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    </ul>
                  </div>
                  <button type="submit" class="btn"><?= htmlspecialchars(__('warehouse.modal.steps.movement.form.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
                </div>
                </form>
            </article>
          </section>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const modal = document.getElementById('warehouse-intake-modal');
      if (!modal) {
        return;
      }

      const body = document.body;
      const openButtons = document.querySelectorAll('[data-open-intake]');
      const closeTriggers = modal.querySelectorAll('[data-close-intake]');
      const backdrop = modal.querySelector('.intake-modal__backdrop');
      const stepButtons = Array.from(modal.querySelectorAll('[data-intake-step]'));
      const panels = Array.from(modal.querySelectorAll('[data-intake-panel]'));

      const changeStep = (step) => {
        stepButtons.forEach((button) => {
          const isActive = button.getAttribute('data-intake-step') === step;
          button.classList.toggle('is-active', isActive);
          button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        panels.forEach((panel) => {
          const isActive = panel.getAttribute('data-intake-panel') === step;
          panel.classList.toggle('is-active', isActive);
          panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
        });
      };

      const focusFirstField = () => {
        const activePanel = modal.querySelector('.intake-modal__panel.is-active');
        if (!activePanel) {
          return;
        }
        const focusable = activePanel.querySelector('input, select, textarea, button');
        if (focusable) {
          focusable.focus({ preventScroll: true });
        }
      };

      const openModal = (step = 'intake') => {
        changeStep(step);
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        body.classList.add('has-open-modal');
        window.setTimeout(focusFirstField, 100);
      };

      const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        body.classList.remove('has-open-modal');
      };

      openButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
          event.preventDefault();
          const step = button.getAttribute('data-target-step') || 'intake';
          openModal(step);
        });
      });

      closeTriggers.forEach((trigger) => {
        trigger.addEventListener('click', (event) => {
          event.preventDefault();
          closeModal();
        });
      });

      if (backdrop) {
        backdrop.addEventListener('click', closeModal);
      }

      document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('is-open')) {
          closeModal();
        }
      });

      stepButtons.forEach((button) => {
        button.addEventListener('click', () => {
          const step = button.getAttribute('data-intake-step');
          changeStep(step);
          focusFirstField();
        });
      });

      const shouldOpenOnLoad = modal.dataset.openOnLoad === 'true';
      if (shouldOpenOnLoad) {
        openModal();
      }
    })();
  </script>
</body>
</html>
