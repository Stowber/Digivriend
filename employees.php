<?php

declare(strict_types=1);

use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Lang\Translator;
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
    'cases.assign' => __('employees.permissions.cases_assign'),
    'cases.approve' => __('employees.permissions.cases_approve'),
    'calendar.manage' => __('employees.permissions.calendar_manage'),
    'calendar.self' => __('employees.permissions.calendar_self'),
    'inventory.manage' => __('employees.permissions.inventory_manage'),
    'documents.publish' => __('employees.permissions.documents_publish'),
    'employees.manage' => __('employees.permissions.employees_manage'),
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
        $messages['error'][] = __('messages.session_expired');
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
                    $language = strtolower(trim((string) InputValidator::requireString($_POST, 'language', 8)));
                    if (!in_array($language, Translator::availableLocales(), true)) {
                        throw new \RuntimeException(__('employees.errors.unsupported_language'));
                    }
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
                        $language,
                        $permissions,
                        $hiredAt
                    );

                    $employeeRepository->logAudit((int) $created['id'], 'created', [
                        'actor' => Auth::username(),
                    ], Auth::username());

                    $messages['success'][] = __('employees.messages.employee_created');
                    $selectedEmployeeId = (int) ($created['id'] ?? 0) ?: $selectedEmployeeId;
                    break;

                case 'update-employee':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$employeeId) {
                        throw new \RuntimeException(__('employees.errors.invalid_employee_id'));
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
                    $language = strtolower(trim((string) InputValidator::requireString($_POST, 'language', 8)));
                    if (!in_array($language, Translator::availableLocales(), true)) {
                        throw new \RuntimeException(__('employees.errors.unsupported_language'));
                    }
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
                        $language,
                        $permissions,
                        $hiredAtRaw !== '' ? date_create_immutable($hiredAtRaw)?->format('Y-m-d') : null,
                        $terminatedAtRaw !== '' ? date_create_immutable($terminatedAtRaw)?->format('Y-m-d') : null
                    );

                    $employeeRepository->logAudit($employeeId, 'updated-profile', [
                        'fields' => array_keys($_POST),
                        'actor' => Auth::username(),
                    ], Auth::username());

                    $messages['success'][] = __('employees.messages.employee_updated');
                    $selectedEmployeeId = $employeeId;
                    break;

                case 'change-status':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    $status = InputValidator::requireString($_POST, 'status', 32);
                    if (!in_array($status, ['active', 'inactive'], true)) {
                        throw new \RuntimeException(__('employees.errors.unsupported_status'));
                    }

                    $employeeRepository->changeStatus((int) $employeeId, $status);
                    $employeeRepository->logAudit((int) $employeeId, 'status-change', [
                        'status' => $status,
                        'actor' => Auth::username(),
                    ], Auth::username());
                    $messages['success'][] = __('employees.messages.status_changed');
                    $selectedEmployeeId = (int) $employeeId;
                    break;

                case 'add-availability':
                    $employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT);
                    if (!$employeeId) {
                        throw new RuntimeException(__('employees.errors.select_employee'));
                    }
                    $type = InputValidator::requireString($_POST, 'availability_type', 32);
                    $startRaw = InputValidator::requireString($_POST, 'start_at', 32);
                    $endRaw = InputValidator::requireString($_POST, 'end_at', 32);
                    $reason = InputValidator::optionalString($_POST, 'reason', 191);

                    $startAt = date_create_immutable($startRaw);
                    $endAt = date_create_immutable($endRaw);
                    if (!$startAt || !$endAt) {
                        throw new \RuntimeException(__('employees.errors.invalid_date_range'));
                    }
                    if ($endAt <= $startAt) {
                        throw new \RuntimeException(__('employees.errors.end_before_start'));
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

                    $messages['success'][] = __('employees.messages.availability_added');
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
$totalEmployees = count($employees);
$availabilityCount = count($availabilityEntries);
$upcomingAppointmentsCount = count($upcomingAppointments);
$activeAssignmentsCount = count($activeAssignments);
$selectedEmployeeName = $selectedEmployee['full_name'] ?? null;
$selectedEmployeeStatus = $selectedEmployee['status'] ?? null;

?><!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('employees.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/management-ui.css">
</head>
<body class="page--employees">
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('employees'); ?>
      </nav>
    </div>
  </header>

  <section class="workspace">
    <div class="container">
      <div class="hero">
        <div class="hero__header">
          <div>
            <h1><?= htmlspecialchars(__('employees.hero.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="hero__description"><?= htmlspecialchars(__('employees.hero.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <div class="quick-actions" role="group" aria-label="<?= htmlspecialchars(__('employees.hero.quick_actions.group_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <button type="button" data-modal-target="modal-create-employee"><?= htmlspecialchars(__('employees.hero.quick_actions.add'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <?php if ($selectedEmployee): ?>
              <button type="button" data-modal-target="modal-manage-employee"><?= htmlspecialchars(__('employees.hero.quick_actions.manage'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            <?php endif; ?>
          </div>
        </div>
        <form method="get" class="filter-panel" aria-label="<?= htmlspecialchars(__('employees.filters.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <label>
            <span><?= htmlspecialchars(__('employees.filters.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <select name="status" onchange="this.form.submit()">
              <option value="active"<?= $statusFilter === 'active' ? ' selected' : '' ?>><?= htmlspecialchars(__('employees.filters.options.active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : '' ?>><?= htmlspecialchars(__('employees.filters.options.inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>><?= htmlspecialchars(__('employees.filters.options.all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            </select>
          </label>
          <?php if ($selectedEmployeeId): ?>
            <input type="hidden" name="employee" value="<?= (int) $selectedEmployeeId ?>">
          <?php endif; ?>
        </form>
      </div>

      <div class="stat-grid" aria-label="<?= htmlspecialchars(__('employees.stats.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <article class="stat-card">
          <span class="stat-card__label"><?= htmlspecialchars(__('employees.stats.total_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="stat-card__value"><?= $totalEmployees ?></span>
          <span class="stat-card__meta"><?= htmlspecialchars(__('employees.stats.view_meta', ['view' => $statusFilter]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label"><?= htmlspecialchars(__('employees.stats.absences_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="stat-card__value"><?= $availabilityCount ?></span>
          <span class="stat-card__meta"><?= $selectedEmployeeName
              ? htmlspecialchars(__('employees.stats.absences_meta_employee', ['name' => $selectedEmployeeName]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
              : htmlspecialchars(__('employees.stats.absences_meta_team'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
          ?></span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label"><?= htmlspecialchars(__('employees.stats.upcoming_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="stat-card__value"><?= $upcomingAppointmentsCount + $activeAssignmentsCount ?></span>
          <span class="stat-card__meta"><?= htmlspecialchars(__('employees.stats.upcoming_meta'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </article>
      </div>

      <div class="panel-grid">
        <section class="panel-card" aria-label="<?= htmlspecialchars(__('employees.panels.list.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div>
            <h2><?= htmlspecialchars(__('employees.panels.list.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="hero__description"><?= htmlspecialchars(__('employees.panels.list.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <?php if ($employees === []): ?>
            <p class="muted"><?= htmlspecialchars(__('employees.panels.list.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php else: ?>
            <div class="people-grid" role="list">
              <?php foreach ($employees as $employee): ?>
                <?php $employeeId = (int) ($employee['id'] ?? 0); ?>
                <?php
                  $query = ['status' => $statusFilter, 'employee' => $employeeId];
                  $href = 'employees.php?' . http_build_query($query);
                ?>
                <a role="listitem" href="<?= htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $employeeId === $selectedEmployeeId ? ' aria-current="true"' : '' ?>>
                  <strong><?= htmlspecialchars((string) ($employee['full_name'] ?? __('employees.panels.list.unknown_name')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <span class="muted"><?= htmlspecialchars((string) ($employee['position'] ?? $employee['role'] ?? __('employees.panels.list.default_role')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="panel-card__action">
            <button type="button" data-modal-target="modal-create-employee"><?= htmlspecialchars(__('employees.panels.list.add'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </section>

        <section class="panel-card" aria-label="<?= htmlspecialchars(__('employees.panels.profile.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div>
            <h2><?= htmlspecialchars(__('employees.panels.profile.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="hero__description"><?= htmlspecialchars(__('employees.panels.profile.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <?php if (!$selectedEmployee): ?>
            <p class="muted"><?= htmlspecialchars(__('employees.panels.profile.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php else: ?>
            <div class="profile-summary">
              <div>
                <span class="badge"><?= $selectedEmployeeStatus === 'active' ? htmlspecialchars(__('employees.panels.profile.status_active'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : htmlspecialchars(__('employees.panels.profile.status_inactive'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
              <span class="profile-summary__name"><?= htmlspecialchars((string) $selectedEmployeeName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <div class="profile-summary__meta">
                <span><?= htmlspecialchars(__('employees.panels.profile.role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) ($selectedEmployee['position'] ?? $selectedEmployee['role'] ?? __('employees.panels.list.default_role')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php if (!empty($selectedEmployee['department'])): ?>
                  <span><?= htmlspecialchars(__('employees.panels.profile.department'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $selectedEmployee['department'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedEmployee['email'])): ?>
                  <span><?= htmlspecialchars(__('employees.panels.profile.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $selectedEmployee['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
                <?php if (!empty($selectedEmployee['phone'])): ?>
                  <span><?= htmlspecialchars(__('employees.panels.profile.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) $selectedEmployee['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php endif; ?>
              </div>
            </div>
            <div class="panel-card__action">
              <button type="button" data-modal-target="modal-manage-employee"><?= htmlspecialchars(__('employees.panels.profile.details'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          <?php endif; ?>
        </section>

        <section class="panel-card" aria-label="<?= htmlspecialchars(__('employees.panels.tasks.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <div>
            <h2><?= htmlspecialchars(__('employees.panels.tasks.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p class="hero__description"><?= htmlspecialchars(__('employees.panels.tasks.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <?php if ($activeAssignments === [] && $upcomingAppointments === []): ?>
            <p class="muted"><?= htmlspecialchars(__('employees.panels.tasks.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <?php else: ?>
            <ul>
              <?php foreach (array_slice($activeAssignments, 0, 4) as $assignment): ?>
                <li>
                  <strong><?= htmlspecialchars(__('employees.panels.tasks.case_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= (int) $assignment['case_id'] ?></strong>
                  <span class="muted"><?= htmlspecialchars((string) ($assignment['type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string) ($assignment['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="muted"><?= htmlspecialchars(__('employees.panels.tasks.role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) ($assignment['assignment_type'] ?? 'primary'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </li>
              <?php endforeach; ?>
              <?php foreach (array_slice($upcomingAppointments, 0, 4) as $appointment): ?>
                <li>
                  <strong><?= htmlspecialchars((string) ($appointment['title'] ?? __('employees.panels.tasks.default_visit_title')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <span class="muted"><?= htmlspecialchars((string) ($appointment['start_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="muted"><?= htmlspecialchars(__('employees.panels.tasks.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?= htmlspecialchars((string) ($appointment['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($selectedEmployee): ?>
            <div class="panel-card__action">
              <button type="button" data-modal-target="modal-manage-employee"><?= htmlspecialchars(__('employees.panels.tasks.manage'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          <?php endif; ?>
        </section>
      </div>
    </div>
  </section>

  <?php if ($messages['success'] !== [] || $messages['error'] !== []): ?>
    <div class="toast-stack" role="status" aria-live="polite">
      <?php foreach ($messages['success'] as $message): ?>
        <div class="toast toast--success"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endforeach; ?>
      <?php foreach ($messages['error'] as $message): ?>
        <div class="toast toast--error"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <footer class="main-footer">
    <div class="container">
      <p><?= htmlspecialchars(__('employees.footer.copyright', ['year' => date('Y')]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
    </div>
  </footer>

  <div class="modal" id="modal-create-employee" role="dialog" aria-modal="true" aria-labelledby="modal-create-employee-title">
    <div class="modal__panel">
      <div class="modal__header">
        <h2 id="modal-create-employee-title"><?= htmlspecialchars(__('employees.modals.create.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
      </div>
      <div class="modal__body">
        <form method="post" class="form-card" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create-employee">
          <h3><?= htmlspecialchars(__('employees.modals.create.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
          <div class="form-grid">
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'full_name'); ?></span>
              <input type="text" name="full_name" maxlength="191" required>
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'email'); ?></span>
              <input type="email" name="email" maxlength="191">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'phone'); ?></span>
              <input type="text" name="phone" maxlength="32">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'role'); ?></span>
              <input type="text" name="role" maxlength="64" required>
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.department'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <input type="text" name="department" maxlength="120">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.position'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <input type="text" name="position" maxlength="120">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <input type="color" name="color" value="#2c7be5">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.timezone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <input type="text" name="timezone" placeholder="<?= htmlspecialchars(__('employees.forms.profile.timezone_placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="64">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.hired_at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <input type="date" name="hired_at">
            </label>
            <label class="form-field">
              <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <select name="language" required>
                <?php foreach (Translator::availableLocales() as $locale): ?>
                  <option value="<?= htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $locale === Translator::locale() ? ' selected' : '' ?>><?= htmlspecialchars(language_name($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
          </div>
          <fieldset class="form-field">
            <legend class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.permissions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'permissions'); ?></legend>
            <div class="permissions-grid">
              <?php foreach ($availablePermissions as $key => $label): ?>
                <label>
                  <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  <span><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <div class="form-actions">
            <button type="submit" class="btn--primary"><?= htmlspecialchars(__('employees.forms.profile.submit_create'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </div>
        </form>
      </div>
    </div>
  </div>

    <div class="modal" id="modal-manage-employee" role="dialog" aria-modal="true" aria-labelledby="modal-manage-employee-title">
    <div class="modal__panel">
      <div class="modal__header">
        <h2 id="modal-manage-employee-title"><?= htmlspecialchars(__('employees.modals.manage.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <button type="button" class="modal__close" data-modal-close aria-label="<?= htmlspecialchars(__('common.close'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">&times;</button>
      </div>
    <div class="modal__body">
        <?php if (!$selectedEmployee): ?>
          <p class="muted"><?= htmlspecialchars(__('employees.modals.manage.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <form method="post" class="form-card" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="update-employee">
            <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
            <h3><?= htmlspecialchars(__('employees.modals.manage.heading'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <div class="form-grid">
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.full_name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_profile', 'full_name'); ?></span>
                <input type="text" name="full_name" value="<?= htmlspecialchars((string) $selectedEmployee['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191" required>
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="email" name="email" value="<?= htmlspecialchars((string) ($selectedEmployee['email'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="191">
              </label>
              <label class="form-field">
                 <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="text" name="phone" value="<?= htmlspecialchars((string) ($selectedEmployee['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="32">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="text" name="role" value="<?= htmlspecialchars((string) ($selectedEmployee['role'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="64" required>
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.department'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                 <input type="text" name="department" value="<?= htmlspecialchars((string) ($selectedEmployee['department'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="120">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.position'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="text" name="position" value="<?= htmlspecialchars((string) ($selectedEmployee['position'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="120">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.color'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="color" name="color" value="<?= htmlspecialchars((string) ($selectedEmployee['color'] ?? '#2c7be5'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.timezone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="text" name="timezone" value="<?= htmlspecialchars((string) ($selectedEmployee['timezone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="64">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.hired_at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="date" name="hired_at" value="<?= htmlspecialchars((string) ($selectedEmployee['hired_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.terminated_at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <input type="date" name="terminated_at" value="<?= htmlspecialchars((string) ($selectedEmployee['terminated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              </label>
              <label class="form-field">
                <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.language'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <?php $currentLanguage = (string) ($selectedEmployee['language'] ?? Translator::locale()); ?>
                <select name="language" required>
                  <?php foreach (Translator::availableLocales() as $locale): ?>
                    <option value="<?= htmlspecialchars($locale, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $locale === $currentLanguage ? ' selected' : '' ?>><?= htmlspecialchars(language_name($locale), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <fieldset class="form-field">
              <legend class="form-field__label"><?= htmlspecialchars(__('employees.forms.profile.permissions'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
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
              <button type="submit" class="btn--primary"><?= htmlspecialchars(__('employees.forms.profile.submit_update'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </div>
          </form>

          <form method="post" class="inline-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="change-status">
            <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
            <input type="hidden" name="status" value="<?= $selectedEmployee['status'] === 'active' ? 'inactive' : 'active' ?>">
            <button type="submit" class="btn--ghost"><?= htmlspecialchars($selectedEmployee['status'] === 'active' ? __('employees.forms.status.deactivate') : __('employees.forms.status.activate'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
          </form>

          <section class="card">
            <h3><?= htmlspecialchars(__('employees.panels.availability.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <form method="post" class="availability-form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="add-availability">
              <input type="hidden" name="employee_id" value="<?= (int) $selectedEmployee['id'] ?>">
              <div class="form-grid">
                <label class="form-field">
                  <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.availability.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> <?php render_field_help('employee_availability', 'type'); ?></span>
                  <select name="availability_type" required>
                    <option value="leave"><?= htmlspecialchars(__('employees.forms.availability.type_options.leave'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="training"><?= htmlspecialchars(__('employees.forms.availability.type_options.training'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="remote"><?= htmlspecialchars(__('employees.forms.availability.type_options.remote'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                    <option value="unavailable"><?= htmlspecialchars(__('employees.forms.availability.type_options.unavailable'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                  </select>
                </label>
                <label class="form-field">
                  <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.availability.start'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <input type="datetime-local" name="start_at" required>
                </label>
                <label class="form-field">
                  <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.availability.end'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <input type="datetime-local" name="end_at" required>
                </label>
                 <label class="form-field form-field--full">
                  <span class="form-field__label"><?= htmlspecialchars(__('employees.forms.availability.reason'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <input type="text" name="reason" maxlength="191">
                </label>
              </div>
                <button type="submit" class="btn--primary"><?= htmlspecialchars(__('employees.forms.availability.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></button>
            </form>

            <?php if ($availabilityEntries === []): ?>
              <p class="muted"><?= htmlspecialchars(__('employees.panels.availability.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th><?= htmlspecialchars(__('employees.panels.availability.table.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.availability.table.range'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.availability.table.reason'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($availabilityEntries as $entry): ?>
                    <tr>
                      <td><?= htmlspecialchars((string) ucfirst(str_replace('_', ' ', (string) $entry['availability_type'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string) ($entry['start_at'] ?? '') . ' – ' . ($entry['end_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                      <td><?= htmlspecialchars((string) ($entry['reason'] ?? __('employees.panels.availability.table.none')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            <?php endif; ?>
          </section>

          <section class="card">
            <h3><?= htmlspecialchars(__('employees.panels.assignments.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <?php if ($activeAssignments === []): ?>
              <p class="muted"><?= htmlspecialchars(__('employees.panels.assignments.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th><?= htmlspecialchars(__('employees.panels.assignments.table.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.assignments.table.type'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.assignments.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.assignments.table.role'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.assignments.table.assigned_at'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($activeAssignments as $assignment): ?>
                    <tr>
                      <td><a class="btn-link" href="case.php?id=<?= (int) $assignment['case_id'] ?>"><?= htmlspecialchars(__('employees.panels.tasks.case_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= (int) $assignment['case_id'] ?></a></td>
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
            <h3><?= htmlspecialchars(__('employees.panels.calendar.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
            <?php if ($upcomingAppointments === []): ?>
              <p class="muted"><?= htmlspecialchars(__('employees.panels.calendar.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            <?php else: ?>
              <table class="table">
                <thead>
                  <tr>
                    <th><?= htmlspecialchars(__('employees.panels.calendar.table.time'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.calendar.table.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.calendar.table.status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
                    <th><?= htmlspecialchars(__('employees.panels.calendar.table.case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
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
                          <a class="btn-link" href="case.php?id=<?= (int) $appointment['case_id'] ?>"><?= htmlspecialchars(__('employees.panels.tasks.case_prefix'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?= (int) $appointment['case_id'] ?></a>
                        <?php else: ?>
                          <span class="muted"><?= htmlspecialchars(__('employees.panels.calendar.table.none'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
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
  </div>

  <script src="js/field-help.js"></script>
  <script src="js/modals.js"></script>
</body>
</html>
