<?php

declare(strict_types=1);

use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\EmployeeRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$employeeRepository = new EmployeeRepository($pdo);
$appointmentRepository = new AppointmentRepository($pdo);

$availablePermissions = [
    'cases.assign' => 'Przydzielanie zleceń',
    'cases.approve' => 'Akceptacja kontroli jakości',
    'calendar.manage' => 'Pełne zarządzanie kalendarzem',
    'calendar.self' => 'Edycja własnych wizyt',
    'inventory.manage' => 'Zarządzanie magazynem',
    'documents.publish' => 'Publikacja i wersjonowanie dokumentów',
    'employees.manage' => 'Zarządzanie pracownikami',
];

$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'active';
$selectedEmployeeParam = filter_input(INPUT_GET, 'employee', FILTER_VALIDATE_INT);
$selectedEmployeeId = $selectedEmployeeParam ?: null;

$messages = [
    'success' => [],
    'error' => [],
];

$employees = $employeeRepository->all($statusFilter === 'all' ? null : $statusFilter);

if ($selectedEmployeeId === null && $employees !== []) {
    $selectedEmployeeId = (int) ($employees[0]['id'] ?? 0) ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $messages['error'][] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            switch ($action) {
                case 'create-employee':
                    $fullName = InputValidator::requireString($_POST, 'full_name', 191);
                    $email = InputValidator::optionalEmail($_POST, 'email', 191);
                    $phone = InputValidator::optionalPhone($_POST, 'phone', 32);
                    $role = InputValidator::requireString($_POST, 'role', 64);
                    $department = InputValidator::optionalString($_POST, 'department', 120);
                    $position = InputValidator::optionalString($_POST, 'position', 120);
                    $color = InputValidator::optionalString($_POST, 'color', 16);
                    $timezone = InputValidator::optionalString($_POST, 'timezone', 64);
                    $hiredAtRaw = InputValidator::optionalString($_POST, 'hired_at', 32);
                    $hiredAt = $hiredAtRaw !== '' ? date_create_immutable($hiredAtRaw)?->format('Y-m-d') : null;
                    $permissions = array_values(array_intersect(array_keys($availablePermissions), (array) ($_POST['permissions'] ?? [])));

                    $created = $employeeRepository->create(
                        $fullName,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $role,
                        $department !== '' ? $department : null,
                        $position !== '' ? $position : null,
                        $color !== '' ? $color : null,
                        $timezone !== '' ? $timezone : null,
                        $permissions,
                        $hiredAt
                    );

                    $employeeRepository->logAudit((int) $created['id'], 'created', [
                        'actor' => Auth::username(),
                    ], Auth::username());

                    $messages['success'][] = 'Dodano nowego pracownika.';
                    $selectedEmployeeId = (int) ($created['id'] ?? 0) ?: $selectedEmployeeId;
                    break;

                case 'update-employee':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$employeeId) {
                        throw new \RuntimeException('Nieprawidłowy identyfikator pracownika.');
                    }

                    $fullName = InputValidator::requireString($_POST, 'full_name', 191);
                    $email = InputValidator::optionalEmail($_POST, 'email', 191);
                    $phone = InputValidator::optionalPhone($_POST, 'phone', 32);
                    $role = InputValidator::requireString($_POST, 'role', 64);
                    $department = InputValidator::optionalString($_POST, 'department', 120);
                    $position = InputValidator::optionalString($_POST, 'position', 120);
                    $color = InputValidator::optionalString($_POST, 'color', 16);
                    $timezone = InputValidator::optionalString($_POST, 'timezone', 64);
                    $hiredAtRaw = InputValidator::optionalString($_POST, 'hired_at', 32);
                    $terminatedAtRaw = InputValidator::optionalString($_POST, 'terminated_at', 32);
                    $permissions = array_values(array_intersect(array_keys($availablePermissions), (array) ($_POST['permissions'] ?? [])));

                    $employeeRepository->update(
                        $employeeId,
                        $fullName,
                        $email !== '' ? $email : null,
                        $phone !== '' ? $phone : null,
                        $role,
                        $department !== '' ? $department : null,
                        $position !== '' ? $position : null,
                        $color !== '' ? $color : null,
                        $timezone !== '' ? $timezone : null,
                        $permissions,
                        $hiredAtRaw !== '' ? date_create_immutable($hiredAtRaw)?->format('Y-m-d') : null,
                        $terminatedAtRaw !== '' ? date_create_immutable($terminatedAtRaw)?->format('Y-m-d') : null
                    );

                    $employeeRepository->logAudit($employeeId, 'updated-profile', [
                        'fields' => array_keys($_POST),
                        'actor' => Auth::username(),
                    ], Auth::username());

                    $messages['success'][] = 'Zaktualizowano dane pracownika.';
                    $selectedEmployeeId = $employeeId;
                    break;

                case 'change-status':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    $status = InputValidator::requireString($_POST, 'status', 32);
                    if (!in_array($status, ['active', 'inactive'], true)) {
                        throw new \RuntimeException('Nieobsługiwany status pracownika.');
                    }

                    $employeeRepository->changeStatus((int) $employeeId, $status);
                    $employeeRepository->logAudit((int) $employeeId, 'status-change', [
                        'status' => $status,
                        'actor' => Auth::username(),
                    ], Auth::username());
                    $messages['success'][] = 'Zmieniono status pracownika.';
                    $selectedEmployeeId = (int) $employeeId;
                    break;

                case 'add-availability':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$employeeId) {
                        throw new RuntimeException('Wybierz pracownika.');
                    }
                    $type = InputValidator::requireString($_POST, 'availability_type', 32);
                    $startRaw = InputValidator::requireString($_POST, 'start_at', 32);
                    $endRaw = InputValidator::requireString($_POST, 'end_at', 32);
                    $reason = InputValidator::optionalString($_POST, 'reason', 191);

                    $startAt = date_create_immutable($startRaw);
                    $endAt = date_create_immutable($endRaw);
                    if (!$startAt || !$endAt) {
                        throw new \RuntimeException('Podaj prawidłowy zakres dat.');
                    }
                    if ($endAt <= $startAt) {
                        throw new \RuntimeException('Data zakończenia musi być późniejsza od rozpoczęcia.');
                    }

                    $employeeRepository->addAvailability(
                        (int) $employeeId,
                        $type,
                        $startAt->format('Y-m-d H:i:s'),
                        $endAt->format('Y-m-d H:i:s'),
                        $reason !== '' ? $reason : null
                    );

                    $employeeRepository->logAudit((int) $employeeId, 'availability-added', [
                        'type' => $type,
                        'start_at' => $startAt->format(\DateTimeInterface::ATOM),
                        'end_at' => $endAt->format(\DateTimeInterface::ATOM),
                        'actor' => Auth::username(),
                    ], Auth::username());

                    $messages['success'][] = 'Dodano wpis dostępności.';
                    $selectedEmployeeId = (int) $employeeId;
                    break;
            }
        } catch (\Throwable $exception) {
            $messages['error'][] = $exception->getMessage();
        }
    }

    $redirectTarget = 'employees.php?status=' . urlencode($statusFilter);
    if ($selectedEmployeeId) {
        $redirectTarget .= '&employee=' . (int) $selectedEmployeeId;
    }
    $_SESSION['employees_messages'] = $messages;
    header('Location: ' . $redirectTarget);
    exit;
}

if (isset($_SESSION['employees_messages'])) {
    $messages = $_SESSION['employees_messages'];
    unset($_SESSION['employees_messages']);
}

$selectedEmployee = null;
$availabilityEntries = [];
$activeAssignments = [];
$upcomingAppointments = [];
$todayStart = (new \DateTimeImmutable('today'))->setTime(0, 0)->format('Y-m-d H:i:s');
$nowDateTime = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');

if ($selectedEmployeeId !== null) {
    $selectedEmployee = $employeeRepository->find((int) $selectedEmployeeId);
    if ($selectedEmployee) {
        $availabilityEntries = $employeeRepository->availabilityForEmployee((int) $selectedEmployeeId, $todayStart);
        $activeAssignments = $employeeRepository->assignmentsForEmployee((int) $selectedEmployeeId, 8);
        $upcomingAppointments = $appointmentRepository->upcoming($nowDateTime, null, (int) $selectedEmployeeId, 'all', 8);
    }
}

$csrfToken = Csrf::token();

?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Pracownicy - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/documents.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('employees'); ?>
      </nav>
    </div>
  </header>

  <main class="container employees">
    <div class="page-header">
      <div>
        <h1>Zespół Digivriend</h1>
        <p class="page-intro">Zarządzaj pracownikami, ich dostępnością, przydziałami do zleceń i uprawnieniami w systemie.</p>
      </div>
      <form method="get" class="filters" aria-label="Filtr statusu">
        <label>
          <span>Status</span>
          <select name="status" onchange="this.form.submit()">
            <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>>Aktywni</option>
            <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>>Nieaktywni</option>
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>Wszyscy</option>
          </select>
        </label>
      </form>
    </div>

    <?php if ($messages['success'] !== [] || $messages['error'] !== []): ?>
      <div class="alerts">
        <?php foreach ($messages['success'] as $message): ?>
          <div class="alert alert--success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endforeach; ?>
        <?php foreach ($messages['error'] as $message): ?>
          <div class="alert alert--danger"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="employees__layout">
      <section class="employees__list" aria-label="Lista pracowników">
        <h2>Pracownicy</h2>
        <ul>
          <?php foreach ($employees as $employee): ?>
            <?php $employeeId = (int) ($employee['id'] ?? 0); ?>
            <li class="<?= $employeeId === $selectedEmployeeId ? 'is-active' : '' ?>">
              <a href="employees.php?status=<?= urlencode($statusFilter) ?>&amp;employee=<?= $employeeId ?>">
                <span class="employee-name"><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Nieznany'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="employee-role"><?= htmlspecialchars((string) ($employee['position'] ?? $employee['role'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <section class="employees__details" aria-label="Szczegóły">
        <div class="tabs">
          <input type="radio" id="tab-create" name="employee-tab"<?= $selectedEmployee ? '' : ' checked' ?>>
          <label for="tab-create">Dodaj pracownika</label>
          <div class="tab-panel">
            <form method="post" class="form-card" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create-employee">
            <h3>Nowy pracownik</h3>
            <div class="form-grid">
              <label class="form-field">
                <span class="form-field__label">Imię i nazwisko <?php render_field_help('employee_profile', 'full_name'); ?></span>
                <input type="text" name="full_name" maxlength="191" required>
              </label>
              <label class="form-field">
                <span class="form-field__label">Adres e-mail <?php render_field_help('employee_profile', 'email'); ?></span>
                <input type="email" name="email" maxlength="191">
              </label>
              <label class="form-field">
                <span class="form-field__label">Telefon <?php render_field_help('employee_profile', 'phone'); ?></span>
                <input type="text" name="phone" maxlength="32">
              </label>
              <label class="form-field">
                <span class="form-field__label">Rola systemowa <?php render_field_help('employee_profile', 'role'); ?></span>
                <input type="text" name="role" maxlength="64" required>
              </label>
              <label class="form-field">
                <span class="form-field__label">Dział</span>
                <input type="text" name="department" maxlength="120">
              </label>
              <label class="form-field">
                <span class="form-field__label">Stanowisko</span>
                <input type="text" name="position" maxlength="120">
              </label>
              <label class="form-field">
                <span class="form-field__label">Kolor kalendarza</span>
                <input type="color" name="color" value="#2c7be5">
              </label>
              <label class="form-field">
                <span class="form-field__label">Strefa czasowa</span>
                <input type="text" name="timezone" placeholder="Europe/Warsaw" maxlength="64">
              </label>
              <label class="form-field">
                <span class="form-field__label">Data zatrudnienia</span>
                <input type="date" name="hired_at">
              </label>
            </div>

            <fieldset class="form-field">
              <legend class="form-field__label">Uprawnienia <?php render_field_help('employee_profile', 'permissions'); ?></legend>
              <div class="permissions-grid">
                <?php foreach ($availablePermissions as $key => $label): ?>
                  <label>
                    <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </fieldset>
            <button type="submit" class="btn btn--primary">Dodaj pracownika</button>
          </form>
          </div>

          <input type="radio" id="tab-profile" name="employee-tab"<?= $selectedEmployee ? ' checked' : '' ?>>
          <label for="tab-profile">Profil i dostępność</label>
          <div class="tab-panel">
            <?php if (!$selectedEmployee): ?>
              <p class="muted">Wybierz pracownika, aby zobaczyć szczegóły.</p>
            <?php else: ?>
              <form method="post" class="form-card" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="update-employee">
              <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
              <h3>Profil pracownika</h3>
              <div class="form-grid">
                <label class="form-field">
                  <span class="form-field__label">Imię i nazwisko <?php render_field_help('employee_profile', 'full_name'); ?></span>
                  <input type="text" name="full_name" value="<?= htmlspecialchars((string) $selectedEmployee['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191" required>
                </label>
                <label class="form-field">
                  <span class="form-field__label">E-mail</span>
                  <input type="email" name="email" value="<?= htmlspecialchars((string) ($selectedEmployee['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Telefon</span>
                  <input type="text" name="phone" value="<?= htmlspecialchars((string) ($selectedEmployee['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="32">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Rola</span>
                  <input type="text" name="role" value="<?= htmlspecialchars((string) ($selectedEmployee['role'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="64" required>
                </label>
                <label class="form-field">
                  <span class="form-field__label">Dział</span>
                  <input type="text" name="department" value="<?= htmlspecialchars((string) ($selectedEmployee['department'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="120">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Stanowisko</span>
                  <input type="text" name="position" value="<?= htmlspecialchars((string) ($selectedEmployee['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="120">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Kolor kalendarza</span>
                  <input type="color" name="color" value="<?= htmlspecialchars((string) ($selectedEmployee['color'] ?? '#2c7be5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Strefa czasowa</span>
                  <input type="text" name="timezone" value="<?= htmlspecialchars((string) ($selectedEmployee['timezone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="64">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Zatrudniony od</span>
                  <input type="date" name="hired_at" value="<?= htmlspecialchars((string) ($selectedEmployee['hired_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
                <label class="form-field">
                  <span class="form-field__label">Zakończenie współpracy</span>
                  <input type="date" name="terminated_at" value="<?= htmlspecialchars((string) ($selectedEmployee['terminated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                </label>
              </div>

              <fieldset class="form-field">
                <legend class="form-field__label">Uprawnienia</legend>
                <div class="permissions-grid">
                  <?php
                    $rawPermissions = (string) ($selectedEmployee['permissions'] ?? '[]');
                    $currentPermissions = [];
                    try {
                        $decodedPermissions = json_decode($rawPermissions, true, 512, JSON_THROW_ON_ERROR);
                        if (is_array($decodedPermissions)) {
                            $currentPermissions = array_map(static fn ($value): string => (string) $value, $decodedPermissions);
                        }
                    } catch (\Throwable) {
                        $currentPermissions = [];
                    }
                  ?>
                  <?php foreach ($availablePermissions as $key => $label): ?>
                    <label>
                      <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= in_array($key, $currentPermissions, true) ? ' checked' : '' ?>>
                      <span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </fieldset>

              <div class="form-actions">
                <button type="submit" class="btn btn--primary">Zapisz zmiany</button>
              </div>
            </form>
            <form method="post" class="inline-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="change-status">
              <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
              <input type="hidden" name="status" value="<?= $selectedEmployee['status'] === 'active' ? 'inactive' : 'active' ?>">
              <button type="submit" class="btn btn--ghost"><?= $selectedEmployee['status'] === 'active' ? 'Zawieś pracownika' : 'Aktywuj pracownika' ?></button>
            </form>

            <section class="card">
              <h3>Dostępność</h3>
              <form method="post" class="availability-form">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add-availability">
                <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
                <div class="form-grid">
                  <label class="form-field">
                    <span class="form-field__label">Typ wpisu <?php render_field_help('employee_availability', 'type'); ?></span>
                    <select name="availability_type" required>
                      <option value="leave">Urlop</option>
                      <option value="training">Szkolenie</option>
                      <option value="remote">Praca zdalna</option>
                      <option value="unavailable">Niedostępny</option>
                    </select>
                  </label>
                  <label class="form-field">
                    <span class="form-field__label">Początek</span>
                    <input type="datetime-local" name="start_at" required>
                  </label>
                  <label class="form-field">
                    <span class="form-field__label">Koniec</span>
                    <input type="datetime-local" name="end_at" required>
                  </label>
                  <label class="form-field form-field--full">
                    <span class="form-field__label">Powód</span>
                    <input type="text" name="reason" maxlength="191">
                  </label>
                </div>
                <button type="submit" class="btn">Dodaj wpis</button>
              </form>

              <?php if ($availabilityEntries === []): ?>
                <p class="muted">Brak zaplanowanych nieobecności.</p>
              <?php else: ?>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Typ</th>
                      <th>Zakres</th>
                      <th>Powód</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($availabilityEntries as $entry): ?>
                      <tr>
                        <td><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $entry['availability_type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($entry['start_at'] ?? '') . ' – ' . ($entry['end_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($entry['reason'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>

            <section class="card">
              <h3>Przydzielone zlecenia</h3>
              <?php if ($activeAssignments === []): ?>
                <p class="muted">Brak aktywnych zleceń.</p>
              <?php else: ?>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Case</th>
                      <th>Typ</th>
                      <th>Status</th>
                      <th>Rola</th>
                      <th>Przydzielono</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($activeAssignments as $assignment): ?>
                      <tr>
                        <td><a class="btn-link" href="case.php?id=<?= (int) $assignment['case_id'] ?>">Case #<?= (int) $assignment['case_id'] ?></a></td>
                        <td><?= htmlspecialchars((string) $assignment['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $assignment['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($assignment['assignment_type'] ?? 'primary'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($assignment['assigned_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>

            <section class="card">
              <h3>Wizyty w kalendarzu</h3>
              <?php if ($upcomingAppointments === []): ?>
                <p class="muted">Brak zaplanowanych wizyt.</p>
              <?php else: ?>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Termin</th>
                      <th>Tytuł</th>
                      <th>Status</th>
                      <th>Zlecenie</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($upcomingAppointments as $appointment): ?>
                      <tr>
                        <td><?= htmlspecialchars((string) ($appointment['start_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($appointment['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($appointment['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        <td>
                          <?php if (!empty($appointment['case_id'])): ?>
                            <a class="btn-link" href="case.php?id=<?= (int) $appointment['case_id'] ?>">Case #<?= (int) $appointment['case_id'] ?></a>
                          <?php else: ?>
                            <span class="muted">—</span>
                          <?php endif; ?>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              <?php endif; ?>
            </section>
            <?php endif; ?>
          </div>
        </div>
      </section>
    </div>
  </main>

  <footer class="main-footer">
    <div class="container">
      <p>&copy; <?= date('Y') ?> Digivriend. Wszystkie prawa zastrzeżone.</p>
    </div>
  </footer>
  <script src="js/field-help.js"></script>
</body>
</html>