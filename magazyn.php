<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

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
        $target['general'] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
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
                    $errors['create']['quantity'] = 'Podaj prawidłową ilość (0 lub więcej).';
                } else {
                    $createValues['quantity'] = $quantity;
                }

                if ($createValues['name'] === '') {
                    $errors['create']['name'] = 'Nazwa pozycji jest wymagana.';
                }

                if (!$warehouseRepository->isValidStatus($createValues['status'])) {
                    $errors['create']['status'] = 'Wybierz prawidłowy status.';
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
                        $_SESSION['warehouse_success'] = sprintf(
                            'Dodano pozycję „%s” z kodem referencyjnym %s.',
                            $item['name'] ?? 'Nowa pozycja',
                            $item['reference_code'] ?? ''
                        );
                        $redirectTarget = 'magazyn.php?highlight=' . (int) ($item['id'] ?? 0);
                        if ($caseId !== null) {
                            $redirectTarget .= '&case=' . $caseId;
                        }
                        Response::redirect($redirectTarget);
                    } catch (\Throwable $exception) {
                        $errors['create']['general'] = 'Wystąpił błąd podczas zapisu pozycji. Spróbuj ponownie.';
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
                    $errors['status']['item_id'] = 'Wybierz pozycję do aktualizacji.';
                }

                if (!$warehouseRepository->isValidStatus($statusValues['status'])) {
                    $errors['status']['status'] = 'Wybierz prawidłowy status.';
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
                            $_SESSION['warehouse_success'] = 'Status magazynowy został zaktualizowany.';
                            $redirectTarget = 'magazyn.php?highlight=' . (int) $statusItemId;
                            if ($statusCaseId !== null) {
                                $redirectTarget .= '&case=' . $statusCaseId;
                            }
                            Response::redirect($redirectTarget);
                        } else {
                            $errors['status']['general'] = 'Nie udało się zmienić statusu.';
                        }
                    } catch (\Throwable $exception) {
                        $errors['status']['general'] = 'Aktualizacja statusu zakończyła się błędem. Spróbuj ponownie.';
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
                    $errors['movement']['item_id'] = 'Wybierz pozycję magazynową.';
                }

                if (!$warehouseRepository->isValidMovementType($movementValues['movement_type'])) {
                    $errors['movement']['movement_type'] = 'Wybierz prawidłowy typ ruchu.';
                }

                $movementQuantity = null;
                if ($movementValues['movement_type'] === 'adjustment') {
                    $movementQuantity = filter_var($movementValues['quantity'], FILTER_VALIDATE_INT);
                    if ($movementQuantity === false || $movementQuantity === 0) {
                        $errors['movement']['quantity'] = 'Podaj dodatnią lub ujemną korektę.';
                    }
                } else {
                    $quantityPositive = filter_var($movementValues['quantity'], FILTER_VALIDATE_INT);
                    if ($quantityPositive === false || $quantityPositive <= 0) {
                        $errors['movement']['quantity'] = 'Podaj dodatnią wartość.';
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
                            $_SESSION['warehouse_success'] = 'Ruch magazynowy został zapisany.';
                            $redirectTarget = 'magazyn.php?highlight=' . (int) $movementItemId . '#historia';
                            if ($movementCaseId !== null) {
                                $redirectTarget .= '&case=' . $movementCaseId;
                            }
                            Response::redirect($redirectTarget);
                        } else {
                            $errors['movement']['general'] = 'Nie udało się zapisać ruchu magazynowego.';
                        }
                    } catch (\Throwable $exception) {
                        $errors['movement']['general'] = 'Wystąpił błąd podczas zapisu ruchu. Spróbuj ponownie.';
                    }
                }
                break;

            default:
                $errors['create']['general'] = 'Nieznana akcja formularza.';
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
$availableQuantity = max(0, $totalQuantity - $totalReserved);

$itemOptions = $warehouseRepository->itemOptions();
$caseOptions = $warehouseRepository->caseOptions();
$recentMovements = $warehouseRepository->recentMovements(12);

$csrfToken = Csrf::token();
$highlightIdRaw = filter_input(INPUT_GET, 'highlight', FILTER_VALIDATE_INT);
$highlightId = $highlightIdRaw !== false && $highlightIdRaw !== null ? (int) $highlightIdRaw : null;

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Magazyn - Digivriend</title>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/warehouse.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle">Serviceplatform</span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <ul>
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="devices.php">Klanten &amp; apparaten</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="magazyn.php" aria-current="page">Magazyn</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php">Documenten</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container warehouse">
    <div class="warehouse__header">
      <div>
        <h1>Magazyn</h1>
        <p>Zarządzaj przyjęciami, rezerwacjami i wydaniami sprzętu powiązanego z naprawami. Wszystkie działania są powiązane z kartami serwisowymi i widoczne w całym systemie.</p>
      </div>
      <div class="warehouse-actions">
        <a class="btn" href="#new-entry">Nowe przyjęcie</a>
        <a class="btn btn--ghost" href="index.php">Powrót do panelu</a>
      </div>
    </div>

    <?php if ($successMessage !== null): ?>
      <div class="alert alert--success"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="warehouse__stats">
      <article class="warehouse__stat">
        <h3>Pozycje w systemie</h3>
        <strong><?= number_format($totalItems, 0, ',', ' ') ?></strong>
        <span>Łączna liczba rekordów magazynowych</span>
      </article>
      <article class="warehouse__stat">
        <h3>Dostępny stan</h3>
        <strong><?= number_format($availableQuantity, 0, ',', ' ') ?></strong>
        <span>Zapas dostępny do wydania</span>
      </article>
      <article class="warehouse__stat">
        <h3>Zarezerwowane</h3>
        <strong><?= number_format($totalReserved, 0, ',', ' ') ?></strong>
        <span>Aktualnie przypisane do napraw</span>
      </article>
      <article class="warehouse__stat">
        <h3>Gotowe do wydania</h3>
        <strong><?= number_format($readyCount, 0, ',', ' ') ?></strong>
        <span>Pozycje oznaczone jako gotowe</span>
      </article>
    </section>

    <section class="warehouse__layout">
      <div class="warehouse__main">
        <section class="card warehouse-card">
          <div class="warehouse__filters">
            <form method="get" aria-label="Filtry magazynowe">
              <div>
                <label for="status">Status</label>
                <select id="status" name="status">
                  <option value="">Wszystkie statusy</option>
                  <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                    <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label for="q">Szukaj</label>
                <input id="q" type="text" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Kod, nazwa, referencja">
              </div>
              <?php if ($prefillCaseId !== null): ?>
                <input type="hidden" name="case" value="<?= (int) $prefillCaseId ?>">
              <?php endif; ?>
              <button type="submit" class="btn">Filtruj</button>
            </form>
          </div>

          <div class="table-wrapper">
            <table class="warehouse-table">
              <thead>
                <tr>
                  <th>Pozycja</th>
                  <th>Status</th>
                  <th>Ilość</th>
                  <th>Lokalizacja</th>
                  <th>Powiązanie</th>
                  <th>Ostatnia aktualizacja</th>
                  <th>Akcje</th>
                </tr>
              </thead>
              <tbody>
                <?php if ($items === []): ?>
                  <tr>
                    <td colspan="7">Nie znaleziono pozycji spełniających kryteria.</td>
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
                          <small>Ref: <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if ($barcode !== ''): ?>
                          <small>Kod: <?= htmlspecialchars($barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if (!empty($item['category'])): ?>
                          <small>Kategoria: <?= htmlspecialchars((string) $item['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                      </td>
                      <td>
                        <span class="status-badge status-badge--<?= htmlspecialchars(str_replace('-', '_', $statusKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </td>
                      <td>
                        <?= number_format($quantity, 0, ',', ' ') ?>
                        <small>Zarezerwowane: <?= number_format($reserved, 0, ',', ' ') ?></small>
                      </td>
                      <td><?= $location !== '' ? htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                      <td>
                        <?php if ($caseId !== null && $caseId > 0): ?>
                          <a href="case.php?id=<?= $caseId ?>">Case #<?= $caseId ?></a>
                          <?php if (!empty($item['customer_name'])): ?>
                            <small><?= htmlspecialchars((string) $item['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                          <?php endif; ?>
                        <?php else: ?>
                          <span class="muted">Brak powiązania</span>
                        <?php endif; ?>
                      </td>
                      <td><?= htmlspecialchars($timestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td>
                        <div class="warehouse-actions">
                          <a class="btn btn--ghost" href="warehouse-label.php?id=<?= (int) ($item['id'] ?? 0) ?>" target="_blank" rel="noopener">Etykieta</a>
                          <?php if ($caseId !== null && $caseId > 0): ?>
                            <a class="btn btn--ghost" href="case.php?id=<?= $caseId ?>">Szczegóły case</a>
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
          <h2>Historia ruchów</h2>
          <?php if (!empty($errors['movement']['general']) && empty($successMessage)): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($errors['movement']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <div class="warehouse-history">
            <?php if ($recentMovements === []): ?>
              <p class="muted">Brak zarejestrowanych ruchów w ostatnim czasie.</p>
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
                    <div>Pozycja: <?= htmlspecialchars((string) $movement['item_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($movement['item_reference'])): ?> · Ref: <?= htmlspecialchars((string) $movement['item_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></div>
                  <?php endif; ?>
                  <?php if ($movementCaseId !== null && $movementCaseId > 0): ?>
                    <div>Powiązanie: <a href="case.php?id=<?= $movementCaseId ?>">Case #<?= $movementCaseId ?></a><?php if (!empty($movement['customer_name'])): ?> · <?= htmlspecialchars((string) $movement['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></div>
                  <?php endif; ?>
                  <?php if (!empty($movement['notes'])): ?>
                    <div>Uwagi: <?= nl2br(htmlspecialchars((string) $movement['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                  <?php endif; ?>
                  <time datetime="<?= htmlspecialchars((string) $movement['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($movementTimestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></time>
                  <?php if (!empty($movement['performed_by'])): ?>
                    <small>Operacja: <?= htmlspecialchars((string) $movement['performed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                  <?php endif; ?>
                </article>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </section>
      </div>

      <aside class="warehouse__sidebar">
        <section class="card warehouse-card" id="new-entry">
          <h2>Nowe przyjęcie / rejestracja</h2>
          <?php if (!empty($errors['create']['general'])): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($errors['create']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create-item">
            <label>
              Nazwa pozycji
              <input type="text" name="name" value="<?= htmlspecialchars($createValues['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
              <?php if (!empty($errors['create']['name'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <div class="form-grid form-grid--two">
              <label>
                Ilość początkowa
                <input type="number" min="0" name="quantity" value="<?= (int) $createValues['quantity'] ?>">
                <?php if (!empty($errors['create']['quantity'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
              </label>
              <label>
                Status początkowy
                <select name="status">
                  <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                    <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $createValues['status'] === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!empty($errors['create']['status'])): ?><span class="form-error"><?= htmlspecialchars($errors['create']['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
              </label>
            </div>
            <label>
              Kategoria
              <input type="text" name="category" value="<?= htmlspecialchars($createValues['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>
            <label>
              Lokalizacja magazynowa
              <input type="text" name="location" value="<?= htmlspecialchars($createValues['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="np. Regał A3, półka 2">
            </label>
            <label>
              Powiązana sprawa serwisowa
              <select name="case_id">
                <option value="">Brak powiązania</option>
                <?php foreach ($caseOptions as $caseOption): ?>
                  <?php $caseOptionId = (int) ($caseOption['id'] ?? 0); ?>
                  <option value="<?= $caseOptionId ?>"<?= $createValues['case_id'] === $caseOptionId ? ' selected' : '' ?>>Case #<?= $caseOptionId ?> · <?= htmlspecialchars((string) ($caseOption['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Kod referencyjny (opcjonalnie)
              <input type="text" name="reference_code" value="<?= htmlspecialchars($createValues['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="np. WH2404-001">
            </label>
            <label>
              Uwagi
              <textarea name="notes" rows="3" placeholder="Uwagi logistyczne, numer zamówienia itp."><?= htmlspecialchars($createValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </label>
            <button type="submit" class="btn">Zapisz pozycję</button>
          </form>
        </section>

        <section class="card warehouse-card">
          <h2>Aktualizacja statusu</h2>
          <?php if (!empty($errors['status']['general'])): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($errors['status']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update-status">
            <label>
              Pozycja
              <select name="item_id" required>
                <option value="">Wybierz pozycję</option>
                <?php foreach ($itemOptions as $option): ?>
                  <?php $optionId = (int) ($option['id'] ?? 0); ?>
                  <option value="<?= $optionId ?>"<?= (string) $statusValues['item_id'] === (string) $optionId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($option['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($option['barcode'])): ?> · <?= htmlspecialchars((string) $option['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['status']['item_id'])): ?><span class="form-error"><?= htmlspecialchars($errors['status']['item_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Nowy status
              <select name="status" required>
                <?php foreach ($statusLabels as $statusKey => $statusLabel): ?>
                  <option value="<?= htmlspecialchars((string) $statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $statusValues['status'] === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['status']['status'])): ?><span class="form-error"><?= htmlspecialchars($errors['status']['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Powiązana sprawa
              <select name="case_id">
                <option value="">Brak</option>
                <?php foreach ($caseOptions as $caseOption): ?>
                  <?php $caseOptionId = (int) ($caseOption['id'] ?? 0); ?>
                  <option value="<?= $caseOptionId ?>"<?= $statusValues['case_id'] === $caseOptionId ? ' selected' : '' ?>>Case #<?= $caseOptionId ?> · <?= htmlspecialchars((string) ($caseOption['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Lokalizacja
              <input type="text" name="location" value="<?= htmlspecialchars($statusValues['location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            </label>
            <label>
              Uwagi
              <textarea name="notes" rows="3"><?= htmlspecialchars($statusValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </label>
            <button type="submit" class="btn">Zapisz zmiany</button>
          </form>
        </section>

        <section class="card warehouse-card">
          <h2>Rejestr ruchu</h2>
          <?php if (!empty($errors['movement']['general'])): ?>
            <div class="alert alert--danger"><?= htmlspecialchars($errors['movement']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="post" class="form-grid">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="record-movement">
            <label>
              Pozycja
              <select name="item_id" required>
                <option value="">Wybierz pozycję</option>
                <?php foreach ($itemOptions as $option): ?>
                  <?php $optionId = (int) ($option['id'] ?? 0); ?>
                  <option value="<?= $optionId ?>"<?= (string) $movementValues['item_id'] === (string) $optionId ? ' selected' : '' ?>><?= htmlspecialchars((string) ($option['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php if (!empty($option['barcode'])): ?> · <?= htmlspecialchars((string) $option['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['movement']['item_id'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['item_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Typ ruchu
              <select name="movement_type" required>
                <?php foreach ($movementLabels as $movementKey => $movementLabel): ?>
                  <option value="<?= htmlspecialchars((string) $movementKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $movementValues['movement_type'] === $movementKey ? ' selected' : '' ?>><?= htmlspecialchars($movementLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
              <?php if (!empty($errors['movement']['movement_type'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['movement_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Ilość
              <input type="number" name="quantity" value="<?= htmlspecialchars((string) $movementValues['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($errors['movement']['quantity'])): ?><span class="form-error"><?= htmlspecialchars($errors['movement']['quantity'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Powiązana sprawa (opcjonalnie)
              <select name="case_id">
                <option value="">Brak</option>
                <?php foreach ($caseOptions as $caseOption): ?>
                  <?php $caseOptionId = (int) ($caseOption['id'] ?? 0); ?>
                  <option value="<?= $caseOptionId ?>"<?= $movementValues['case_id'] === $caseOptionId ? ' selected' : '' ?>>Case #<?= $caseOptionId ?> · <?= htmlspecialchars((string) ($caseOption['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              Uwagi
              <textarea name="notes" rows="3" placeholder="Opis ruchu, osoba odpowiedzialna itp."><?= htmlspecialchars($movementValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </label>
            <button type="submit" class="btn">Zapisz ruch</button>
          </form>
        </section>
      </aside>
    </section>
  </main>
</body>
</html>