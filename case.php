<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Audit\AuditLogger;
use App\Support\Checklist\ChecklistRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\WarehouseRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

$caseId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if ($caseId === null || $caseId === false) {
    Response::error('Ongeldig of ontbrekend case-ID.', 400);
}

$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);
$checklistRepository = new ChecklistRepository($pdo);
$auditLogger = new AuditLogger($pdo);
$warehouseRepository = new WarehouseRepository($pdo);

$case = $caseRepository->findById((int) $caseId);
if ($case === null) {
    Response::error('Case niet gevonden.', 404);
}

$statement = $pdo->prepare(
    'SELECT c.*, cust.full_name, cust.email, cust.phone, cust.address, cust.postal_code, cust.city,
            dev.brand AS device_brand, dev.model AS device_model, dev.serial_number AS device_serial
     FROM cases c
     INNER JOIN customers cust ON cust.id = c.customer_id
     LEFT JOIN devices dev ON dev.id = c.device_id
     WHERE c.id = :id'
);
$statement->execute(['id' => $caseId]);
$caseRecord = $statement->fetch();
if (!$caseRecord) {
    Response::error('Casegegevens konden niet worden geladen.', 500);
}

$warehouseStatusLabels = $warehouseRepository->statusLabels();
$warehouseItems = $warehouseRepository->findByCaseId((int) $caseId);
$warehouseSummary = [
    'total' => count($warehouseItems),
    'ready' => 0,
    'reserved' => 0,
    'in_service' => 0,
];
$warehouseTotals = [
    'quantity' => 0,
    'reserved' => 0,
];

foreach ($warehouseItems as $warehouseItem) {
    $statusKey = (string) ($warehouseItem['status'] ?? '');
    if ($statusKey === 'ready') {
        $warehouseSummary['ready']++;
    }
    if ($statusKey === 'reserved') {
        $warehouseSummary['reserved']++;
    }
    if ($statusKey === 'in_service') {
        $warehouseSummary['in_service']++;
    }

    $warehouseTotals['quantity'] += (int) ($warehouseItem['quantity'] ?? 0);
    $warehouseTotals['reserved'] += (int) ($warehouseItem['reserved_quantity'] ?? 0);
}

$caseDetails = [];
if (!empty($caseRecord['details'])) {
    $decoded = json_decode((string) $caseRecord['details'], true);
    if (is_array($decoded)) {
        $caseDetails = $decoded;
    }
}

$errors = [];
$checklistErrors = [];
$noteEditErrors = [];
$noteEditValues = [];
$currentAction = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        $action = $_POST['action'] ?? 'add-note';
        $currentAction = $action;

        switch ($action) {
            case 'add-note':
                $body = InputValidator::requireString($_POST, 'body', 2000);
                $noteRepository->add((int) $caseId, (int) $caseRecord['customer_id'], Auth::username(), $body);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_added', ['body' => $body]);
                break;
              case 'update-note':
                $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$noteId) {
                    throw new ValidationException(['general' => 'Ongeldige notitie geselecteerd.']);
                }
                $body = InputValidator::requireString($_POST, 'body', 2000);
                if (!$noteRepository->update((int) $caseId, (int) $noteId, $body)) {
                    throw new ValidationException(['general' => 'Notitie niet gevonden.']);
                }
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_updated', ['note_id' => (int) $noteId, 'body' => $body]);
                break;
            case 'delete-note':
                $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$noteId) {
                    throw new ValidationException(['general' => 'Ongeldige notitie geselecteerd.']);
                }
                if (!$noteRepository->delete((int) $caseId, (int) $noteId)) {
                    throw new ValidationException(['general' => 'Notitie niet gevonden.']);
                }
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'note_deleted', ['note_id' => (int) $noteId]);
                break;
            case 'add-checklist':
                $title = InputValidator::requireString($_POST, 'title', 160);
                $assignedTo = InputValidator::optionalString($_POST, 'assigned_to', 120);
                $dueDateRaw = trim((string) ($_POST['due_date'] ?? ''));
                $dueDate = null;
                if ($dueDateRaw !== '') {
                    $dueDateInstance = date_create_immutable($dueDateRaw);
                    if (!$dueDateInstance instanceof \DateTimeImmutable) {
                        throw new ValidationException(['due_date' => 'Ongeldige datum opgegeven.']);
                    }
                    $dueDate = $dueDateInstance->format('Y-m-d');
                }
                $newChecklistId = $checklistRepository->createChecklist((int) $caseId, $title, $assignedTo !== '' ? $assignedTo : null, $dueDate);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_created', ['checklist_id' => $newChecklistId, 'title' => $title]);
                break;
            case 'add-checklist-item':
                $checklistId = filter_var($_POST['checklist_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$checklistId) {
                    throw new ValidationException(['checklist_id' => 'Ongeldige checklist.']);
                }
                $description = InputValidator::requireString($_POST, 'description', 255);
                $checklistRepository->addItem((int) $checklistId, $description);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_item_added', ['checklist_id' => (int) $checklistId]);
                break;
            case 'toggle-checklist-item':
                $itemId = filter_var($_POST['item_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$itemId) {
                    throw new ValidationException(['item_id' => 'Ongeldig item.']);
                }
                $completed = isset($_POST['completed']) && $_POST['completed'] === '1';
                $checklistRepository->toggleItem((int) $itemId, $completed, Auth::username());
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_item_toggled', ['item_id' => (int) $itemId, 'completed' => $completed]);
                break;
            case 'remove-checklist':
                $checklistId = filter_var($_POST['checklist_id'] ?? null, FILTER_VALIDATE_INT);
                if (!$checklistId) {
                    throw new ValidationException(['checklist_id' => 'Ongeldige checklist.']);
                }
                $checklistRepository->removeChecklist((int) $checklistId);
                $auditLogger->log((int) $caseId, Auth::id(), Auth::username(), 'checklist_removed', ['checklist_id' => (int) $checklistId]);
                break;
            default:
                throw new ValidationException(['general' => 'Onbekende actie.']);
        }
        Response::redirect('case.php?id=' . (int) $caseId);
    } catch (ValidationException $exception) {
        $validationErrors = $exception->errors();
        if ($currentAction === 'add-checklist' || $currentAction === 'add-checklist-item' || $currentAction === 'toggle-checklist-item' || $currentAction === 'remove-checklist') {
            $checklistErrors = $validationErrors;
        } elseif ($currentAction === 'update-note') {
            $noteId = filter_var($_POST['note_id'] ?? null, FILTER_VALIDATE_INT);
            if ($noteId) {
                $noteEditErrors[(int) $noteId] = $validationErrors;
                $noteEditValues[(int) $noteId] = (string) ($_POST['body'] ?? '');
            } else {
                $errors = $validationErrors;
            }
        } elseif ($currentAction === 'delete-note') {
            $errors = array_merge($errors, $validationErrors);
        } elseif ($currentAction === 'add-note') {
            $errors = $validationErrors;
        } else {
            $errors = array_merge($errors, $validationErrors);
        }
    }
}

$notes = $noteRepository->forCase((int) $caseId);
$csrfToken = Csrf::token();
$checklists = $checklistRepository->forCase((int) $caseId);
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Case #<?= (int) $caseId ?> - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/case.css">
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
          <li><a href="klant-melding.php">Klant Melding</a></li>
           <li><a href="documents.php">Documenten</a></li>
           <li><a href="magazyn.php">Magazyn</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>

  <main class="container case-view">
    <section class="case-overview">
      <div>
        <h1>Case #<?= (int) $caseId ?> · <?= htmlspecialchars((string) $caseRecord['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="muted">Status: <span class="status-pill <?= ($caseRecord['status'] === 'opgehaald' || $caseRecord['status'] === 'gesloten') ? 'status-pill--picked' : 'status-pill--ready' ?>"><?= htmlspecialchars(ucfirst((string) $caseRecord['status']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></p>
      </div>
      <div class="case-meta">
        <p>Laatst bijgewerkt: <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $caseRecord['updated_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <p>Referentie: <?= htmlspecialchars((string) $caseRecord['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
    </section>

    <section class="case-grid">
      <article class="info-card">
        <h2>Klantgegevens</h2>
        <ul>
          <li><strong>Naam:</strong> <?= htmlspecialchars((string) $caseRecord['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>E-mail:</strong> <?= htmlspecialchars((string) $caseRecord['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Telefoon:</strong> <?= htmlspecialchars((string) $caseRecord['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <li><strong>Adres:</strong> <?= htmlspecialchars((string) ($caseRecord['address'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php if (!empty($caseRecord['postal_code'])): ?>
            <li><strong>Postcode:</strong> <?= htmlspecialchars((string) $caseRecord['postal_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
          <?php if (!empty($caseRecord['city'])): ?>
            <li><strong>Plaats:</strong> <?= htmlspecialchars((string) $caseRecord['city'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
          <?php endif; ?>
        </ul>
      </article>

      <article class="info-card">
        <h2>Apparaatgegevens</h2>
        <?php if ($caseRecord['device_brand'] || $caseRecord['device_model']): ?>
          <ul>
            <?php if (!empty($caseRecord['device_brand'])): ?><li><strong>Merk:</strong> <?= htmlspecialchars((string) $caseRecord['device_brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_model'])): ?><li><strong>Model:</strong> <?= htmlspecialchars((string) $caseRecord['device_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
            <?php if (!empty($caseRecord['device_serial'])): ?><li><strong>Serienummer:</strong> <?= htmlspecialchars((string) $caseRecord['device_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li><?php endif; ?>
          </ul>
        <?php else: ?>
          <p class="muted">Geen apparaatgegevens beschikbaar.</p>
        <?php endif; ?>
      </article>

      <article class="info-card info-card--wide">
        <h2>Details</h2>
        <?php if (empty($caseDetails)): ?>
          <p class="muted">Geen aanvullende details opgeslagen.</p>
        <?php else: ?>
          <dl class="details-list">
            <?php foreach ($caseDetails as $key => $value): ?>
              <div>
                <dt><?= htmlspecialchars(str_replace('_', ' ', ucfirst((string) $key)), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                <dd><?= is_array($value) ? htmlspecialchars(json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : nl2br(htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
              </div>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      </article>
    </section>

    <section class="case-warehouse">
      <article class="warehouse-card">
        <div class="warehouse-card__header">
          <div>
            <h2>Powiązane zasoby magazynowe</h2>
            <p class="muted">Monitoruj komponenty i zestawy przypisane do tej sprawy serwisowej.</p>
          </div>
          <a class="btn btn--ghost" href="magazyn.php?case=<?= (int) $caseId ?>">Otwórz Magazyn</a>
        </div>
        <?php if ($warehouseItems === []): ?>
          <p class="muted">Brak powiązanych pozycji magazynowych. Dodaj sprzęt do sprawy bezpośrednio w zakładce Magazyn.</p>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="case-warehouse__table">
              <thead>
                <tr>
                  <th>Pozycja</th>
                  <th>Status</th>
                  <th>Ilość</th>
                  <th>Lokalizacja</th>
                  <th>Ostatni ruch</th>
                  <th>Etykieta</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($warehouseItems as $warehouseItem): ?>
                  <?php
                    $statusKey = (string) ($warehouseItem['status'] ?? '');
                    $statusLabel = $warehouseStatusLabels[$statusKey] ?? ucfirst($statusKey);
                    $quantity = (int) ($warehouseItem['quantity'] ?? 0);
                    $reserved = (int) ($warehouseItem['reserved_quantity'] ?? 0);
                    $location = trim((string) ($warehouseItem['location'] ?? ''));
                    $lastMovement = (string) ($warehouseItem['last_movement_at'] ?? $warehouseItem['updated_at'] ?? '');
                    $movementTimestamp = $lastMovement !== '' ? date('d-m-Y H:i', strtotime($lastMovement)) : '—';
                    $referenceCode = trim((string) ($warehouseItem['reference_code'] ?? ''));
                  ?>
                  <tr>
                    <td>
                      <strong><?= htmlspecialchars((string) ($warehouseItem['name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                      <?php if ($referenceCode !== ''): ?><small>Ref: <?= htmlspecialchars($referenceCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                      <?php if (!empty($warehouseItem['barcode'])): ?><small>Kod: <?= htmlspecialchars((string) $warehouseItem['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                    </td>
                    <td><span class="status-badge status-badge--<?= htmlspecialchars(str_replace('-', '_', $statusKey), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></td>
                    <td><?= number_format($quantity, 0, ',', ' ') ?><small>Zarezerwowane: <?= number_format($reserved, 0, ',', ' ') ?></small></td>
                    <td><?= $location !== '' ? htmlspecialchars($location, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '—' ?></td>
                    <td><?= htmlspecialchars($movementTimestamp, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    <td><a class="btn btn--ghost btn--small" href="warehouse-label.php?id=<?= (int) ($warehouseItem['id'] ?? 0) ?>" target="_blank" rel="noopener">Etykieta</a></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </article>

      <aside class="warehouse-card warehouse-card--summary">
        <h3>Stan magazynowy dla case #<?= (int) $caseId ?></h3>
        <ul class="warehouse-card__list">
          <li><span>Powiązane pozycje</span><strong><?= (int) $warehouseSummary['total'] ?></strong></li>
          <li><span>Ilość łączna</span><strong><?= number_format($warehouseTotals['quantity'], 0, ',', ' ') ?></strong></li>
          <li><span>Zarezerwowane</span><strong><?= number_format($warehouseTotals['reserved'], 0, ',', ' ') ?></strong></li>
          <li><span>Gotowe do wydania</span><strong><?= (int) $warehouseSummary['ready'] ?></strong></li>
          <li><span>W naprawie</span><strong><?= (int) $warehouseSummary['in_service'] ?></strong></li>
        </ul>
        <p class="muted">Aktualizacje statusów oraz etykiety magazynowe dostępne są w zakładce Magazyn.</p>
        <a class="btn btn--ghost" href="magazyn.php?case=<?= (int) $caseId ?>#new-entry">Dodaj komponent</a>
      </aside>
    </section>

    <section class="checklists-section">
      <header class="checklists-header">
        <h2>Checklist workflow</h2>
      </header>
      <div class="checklists-grid">
        <article class="checklist-card checklist-card--new">
          <h3>Nieuwe checklist</h3>
          <?php if (!empty($checklistErrors['general'])): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $checklistErrors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="POST" class="checklist-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="add-checklist">
            <label>
              <span>Titel</span>
              <input type="text" name="title" maxlength="160" required>
              <?php if (!empty($checklistErrors['title'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label>
              <span>Toegewezen aan</span>
              <input type="text" name="assigned_to" maxlength="120" placeholder="Bijv. Technicus Jan">
              <?php if (!empty($checklistErrors['assigned_to'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <label>
              <span>Deadline</span>
              <input type="date" name="due_date">
              <?php if (!empty($checklistErrors['due_date'])): ?><small class="form-error"><?= htmlspecialchars((string) $checklistErrors['due_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            </label>
            <button type="submit" class="btn">Checklist toevoegen</button>
          </form>
        </article>

        <?php if (empty($checklists)): ?>
          <article class="checklist-card checklist-card--empty">
            <p class="muted">Nog geen checklist aangemaakt voor deze case.</p>
          </article>
        <?php else: ?>
          <?php foreach ($checklists as $checklist): ?>
            <article class="checklist-card">
              <header class="checklist-card__header">
                <div>
                  <h3><?= htmlspecialchars((string) $checklist['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <div class="muted">
                    <?php if (!empty($checklist['assigned_to'])): ?>Toegewezen aan <?= htmlspecialchars((string) $checklist['assigned_to'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                    <?php if (!empty($checklist['due_at'])): ?> · Deadline <?= htmlspecialchars(date('d-m-Y', strtotime((string) $checklist['due_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                  </div>
                </div>
                <form method="POST">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <input type="hidden" name="action" value="remove-checklist">
                  <input type="hidden" name="checklist_id" value="<?= (int) $checklist['id'] ?>">
                  <button type="submit" class="btn btn--ghost" onclick="return confirm('Checklist verwijderen?')">Verwijder</button>
                </form>
              </header>

              <ul class="checklist-items">
                <?php if (empty($checklist['items'])): ?>
                  <li class="muted">Nog geen stappen toegevoegd.</li>
                <?php else: ?>
                  <?php foreach ($checklist['items'] as $item): ?>
                    <li class="checklist-item <?= (int) $item['is_completed'] === 1 ? 'checklist-item--done' : '' ?>">
                      <div>
                        <strong><?= htmlspecialchars((string) $item['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <?php if ((int) $item['is_completed'] === 1): ?>
                          <div class="muted">Voltooid door <?= htmlspecialchars((string) ($item['completed_by'] ?? 'Onbekend'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> op <?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $item['completed_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                        <?php endif; ?>
                      </div>
                      <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="toggle-checklist-item">
                        <input type="hidden" name="item_id" value="<?= (int) $item['id'] ?>">
                        <input type="hidden" name="completed" value="<?= (int) $item['is_completed'] === 1 ? '0' : '1' ?>">
                        <button type="submit" class="btn btn--ghost btn--small"><?= (int) $item['is_completed'] === 1 ? 'Markeer open' : 'Markeer voltooid' ?></button>
                      </form>
                    </li>
                  <?php endforeach; ?>
                <?php endif; ?>
              </ul>

              <form method="POST" class="checklist-item-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add-checklist-item">
                <input type="hidden" name="checklist_id" value="<?= (int) $checklist['id'] ?>">
                <label class="sr-only" for="item-<?= (int) $checklist['id'] ?>">Nieuwe stap</label>
                <input id="item-<?= (int) $checklist['id'] ?>" type="text" name="description" maxlength="255" placeholder="Voeg een stap toe" required>
                <button type="submit" class="btn btn--ghost">Stap toevoegen</button>
              </form>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </section>

    <section class="notes-section">
      <div class="notes-header">
        <h2>Notities</h2>
      </div>
      <div class="notes-grid">
        <div class="notes-list">
          <?php if (empty($notes)): ?>
            <p class="muted">Er zijn nog geen notities voor deze case.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($notes as $note): ?>
                <li>
                  <div class="note-header">
                    <strong><?= htmlspecialchars((string) $note['author'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <span class="muted"><?= htmlspecialchars(date('d-m-Y H:i', strtotime((string) $note['created_at'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </div>
                  <div class="note-body"><?= nl2br(htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></div>
                  <div class="note-actions">
                    <details class="note-edit"<?= isset($noteEditErrors[(int) $note['id']]) ? ' open' : '' ?>>
                      <summary>Bewerk notitie</summary>
                      <?php if (!empty($noteEditErrors[(int) $note['id']]['general'])): ?>
                        <div class="alert alert--error"><?= htmlspecialchars((string) $noteEditErrors[(int) $note['id']]['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                      <?php endif; ?>
                      <form method="POST" class="note-edit__form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <input type="hidden" name="action" value="update-note">
                        <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                        <label class="sr-only" for="note-body-<?= (int) $note['id'] ?>">Notitie</label>
                        <textarea id="note-body-<?= (int) $note['id'] ?>" name="body" rows="4" required><?= htmlspecialchars($noteEditValues[(int) $note['id']] ?? (string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                        <?php if (!empty($noteEditErrors[(int) $note['id']]['body'])): ?><small class="form-error"><?= htmlspecialchars((string) $noteEditErrors[(int) $note['id']]['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
                        <div class="note-edit__actions">
                          <button type="submit" class="btn btn--ghost btn--small">Opslaan</button>
                        </div>
                      </form>
                    </details>
                    <form method="POST" class="note-delete-form" onsubmit="return confirm('Notitie verwijderen?');">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <input type="hidden" name="action" value="delete-note">
                      <input type="hidden" name="note_id" value="<?= (int) $note['id'] ?>">
                      <button type="submit" class="btn btn--ghost btn--small">Verwijderen</button>
                    </form>
                  </div>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
        <div class="note-form">
          <h3>Nieuwe notitie</h3>
          <?php if (!empty($errors['general'])): ?>
            <div class="alert alert--error"><?= htmlspecialchars((string) $errors['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
          <?php endif; ?>
          <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
             <input type="hidden" name="action" value="add-note">
            <label for="body">Notitie</label>
            <textarea name="body" id="body" rows="5" required></textarea>
            <?php if (!empty($errors['body'])): ?><small class="form-error"><?= htmlspecialchars((string) $errors['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small><?php endif; ?>
            <button type="submit" class="btn">Opslaan</button>
          </form>
        </div>
      </div>
    </section>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Alle rechten voorbehouden.</p>
    </div>
  </footer>
</body>
</html>