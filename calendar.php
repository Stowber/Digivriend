<?php

declare(strict_types=1);

use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\EmployeeRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$appointmentRepository = new AppointmentRepository($pdo);
$employeeRepository = new EmployeeRepository($pdo);
$caseRepository = new CaseRepository($pdo);

$messages = [
    'success' => [],
    'error' => [],
];

$employeeFilter = filter_input(INPUT_GET, 'employee', FILTER_VALIDATE_INT) ?: null;
$statusFilter = filter_input(INPUT_GET, 'status', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$caseFilter = filter_input(INPUT_GET, 'case', FILTER_VALIDATE_INT) ?: null;

$employees = $employeeRepository->all('active');
$cases = $caseRepository->recentCases(25);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $messages['error'][] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            switch ($action) {
                case 'create-appointment':
                    $title = InputValidator::requireString($_POST, 'title', 191);
                    $type = InputValidator::requireString($_POST, 'appointment_type', 64);
                    $status = InputValidator::optionalString($_POST, 'status', 32);
                    $status = $status !== '' ? $status : 'tentative';
                    $startRaw = InputValidator::requireString($_POST, 'start_at', 32);
                    $endRaw = InputValidator::requireString($_POST, 'end_at', 32);
                    $location = InputValidator::optionalString($_POST, 'location', 191);
                    $notes = InputValidator::optionalString($_POST, 'notes', 500);
                    $color = InputValidator::optionalString($_POST, 'color', 16);
                    $confirmationMethod = InputValidator::optionalString($_POST, 'confirmation_method', 64);
                    $confirmationStatus = InputValidator::optionalString($_POST, 'confirmation_status', 32);
                    $caseIdInput = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT);

                    $startAt = date_create_immutable($startRaw);
                    $endAt = date_create_immutable($endRaw);
                    if (!$startAt || !$endAt) {
                        throw new \RuntimeException('Podaj prawidłowy zakres dat.');
                    }
                    if ($endAt <= $startAt) {
                        throw new \RuntimeException('Data zakończenia musi być późniejsza od rozpoczęcia.');
                    }

                    $employeeIds = array_map('intval', (array) ($_POST['employees'] ?? []));
                    $employeeIds = array_values(array_filter($employeeIds, static fn (int $value): bool => $value > 0));
                    if ($employeeIds === []) {
                        throw new \RuntimeException('Wybierz co najmniej jednego pracownika.');
                    }

                    $caseId = $caseIdInput ?: null;
                    $customerId = null;
                    if ($caseId) {
                        $caseRecord = $caseRepository->findById((int) $caseId);
                        $customerId = $caseRecord['customer_id'] ?? null;
                    }

                    $resourceLines = preg_split('/\r?\n/', (string) ($_POST['resources'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
                    $resources = [];
                    foreach ($resourceLines as $line) {
                        $parts = array_map('trim', explode('|', $line));
                        $resources[] = [
                            'type' => $parts[0] ?? 'resource',
                            'label' => $parts[1] ?? $line,
                            'details' => $parts[2] ?? null,
                        ];
                    }

                    $conflicts = $appointmentRepository->conflicts(
                        $startAt->format('Y-m-d H:i:s'),
                        $endAt->format('Y-m-d H:i:s'),
                        $employeeIds
                    );

                    if ($conflicts !== []) {
                        $messages['error'][] = 'Wybrani pracownicy mają już wizytę w tym czasie.';
                        break;
                    }

                    $appointmentRepository->create(
                        $title,
                        $type,
                        $status,
                        $startAt->format('Y-m-d H:i:s'),
                        $endAt->format('Y-m-d H:i:s'),
                        $caseId,
                        $customerId ? (int) $customerId : null,
                        $location !== '' ? $location : null,
                        $notes !== '' ? $notes : null,
                        $color !== '' ? $color : null,
                        $confirmationMethod !== '' ? $confirmationMethod : null,
                        $confirmationStatus !== '' ? $confirmationStatus : null,
                        null,
                        Auth::username(),
                        Auth::username(),
                        $employeeIds,
                        $resources
                    );

                    $messages['success'][] = 'Dodano wizytę do kalendarza.';
                    break;
            }
        } catch (\Throwable $exception) {
            $messages['error'][] = $exception->getMessage();
        }
    }

    $_SESSION['calendar_messages'] = $messages;
    $redirect = 'calendar.php';
    $query = [];
    if ($employeeFilter) {
        $query['employee'] = (int) $employeeFilter;
    }
    if ($statusFilter !== 'all') {
        $query['status'] = $statusFilter;
    }
    if ($caseFilter) {
        $query['case'] = (int) $caseFilter;
    }
    if ($query !== []) {
        $redirect .= '?' . http_build_query($query);
    }
    header('Location: ' . $redirect);
    exit;
}

if (isset($_SESSION['calendar_messages'])) {
    $messages = $_SESSION['calendar_messages'];
    unset($_SESSION['calendar_messages']);
}

$now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
$upcomingAppointments = $appointmentRepository->upcoming($now, null, $employeeFilter, $statusFilter, 25);
$csrfToken = Csrf::token();

?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Kalendarz - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/documents.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('calendar'); ?>
      </nav>
    </div>
  </header>

  <main class="container calendar">
    <div class="page-header">
      <div>
        <h1>Kalendarz serwisu</h1>
        <p class="page-intro">Planuj wizyty diagnostyczne, odbiory i wyjazdy oraz monitoruj obłożenie zespołu.</p>
      </div>
      <form method="get" class="filters" aria-label="Filtry kalendarza">
        <label>
          <span>Pracownik</span>
          <select name="employee" onchange="this.form.submit()">
            <option value="">Wszyscy</option>
            <?php foreach ($employees as $employee): ?>
              <?php $id = (int) ($employee['id'] ?? 0); ?>
              <option value="<?= $id ?>"<?= $employeeFilter === $id ? ' selected' : '' ?>><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Nieznany'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span>Status</span>
          <select name="status" onchange="this.form.submit()">
            <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>Wszystkie</option>
            <option value="tentative"<?= $statusFilter === 'tentative' ? ' selected' : '' ?>>Oczekujące</option>
            <option value="confirmed"<?= $statusFilter === 'confirmed' ? ' selected' : '' ?>>Potwierdzone</option>
            <option value="completed"<?= $statusFilter === 'completed' ? ' selected' : '' ?>>Zrealizowane</option>
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

    <div class="calendar__layout">
      <section class="calendar__form card">
        <h2>Nowa wizyta</h2>
        <form method="post" autocomplete="off">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create-appointment">
          <div class="form-grid">
            <label class="form-field">
              <span class="form-field__label">Tytuł wizyty</span>
              <input type="text" name="title" maxlength="191" required>
            </label>
            <label class="form-field">
              <span class="form-field__label">Typ wizyty</span>
              <select name="appointment_type" required>
                <option value="diagnostyka">Diagnostyka</option>
                <option value="naprawa">Naprawa</option>
                <option value="odbior">Odbiór sprzętu</option>
                <option value="wyjazd">Wyjazd do klienta</option>
                <option value="konsultacja">Konsultacja online</option>
              </select>
            </label>
            <label class="form-field">
              <span class="form-field__label">Data rozpoczęcia <?php render_field_help('appointment', 'start_time'); ?></span>
              <input type="datetime-local" name="start_at" required>
            </label>
            <label class="form-field">
              <span class="form-field__label">Data zakończenia <?php render_field_help('appointment', 'end_time'); ?></span>
              <input type="datetime-local" name="end_at" required>
            </label>
            <label class="form-field">
              <span class="form-field__label">Powiązane zlecenie</span>
              <select name="case_id">
                <option value="">—</option>
                <?php foreach ($cases as $case): ?>
                  <?php $caseOptionId = (int) ($case['id'] ?? 0); ?>
                  <option value="<?= $caseOptionId ?>"<?= $caseFilter === $caseOptionId ? ' selected' : '' ?>>Case #<?= $caseOptionId ?> · <?= htmlspecialchars((string) ($case['summary'] ?? $case['type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label class="form-field">
              <span class="form-field__label">Lokalizacja <?php render_field_help('appointment', 'location'); ?></span>
              <input type="text" name="location" maxlength="191" placeholder="Serwis / adres klienta">
            </label>
            <label class="form-field">
              <span class="form-field__label">Kolor kalendarza</span>
              <input type="color" name="color" value="#2c7be5">
            </label>
            <label class="form-field">
              <span class="form-field__label">Status</span>
              <select name="status">
                <option value="tentative">Oczekuje na potwierdzenie</option>
                <option value="confirmed">Potwierdzona</option>
                <option value="completed">Zakończona</option>
              </select>
            </label>
          </div>

          <fieldset class="form-field">
            <legend class="form-field__label">Pracownicy</legend>
            <div class="permissions-grid">
              <?php foreach ($employees as $employee): ?>
                <label>
                  <input type="checkbox" name="employees[]" value="<?= (int) $employee['id'] ?>">
                  <span><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Pracownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </fieldset>

          <label class="form-field">
            <span class="form-field__label">Zasoby (każdy w linii: typ|nazwa|szczegóły) <?php render_field_help('appointment', 'resources'); ?></span>
            <textarea name="resources" rows="3" placeholder="stanowisko|Serwis 2&#10;samochod|Bus 1"></textarea>
          </label>

          <label class="form-field">
            <span class="form-field__label">Notatki</span>
            <textarea name="notes" rows="3" placeholder="Przygotować stanowisko ESD."></textarea>
          </label>

          <div class="form-grid">
            <label class="form-field">
              <span class="form-field__label">Potwierdzenie <?php render_field_help('appointment', 'customer_confirmation'); ?></span>
              <select name="confirmation_method">
                <option value="">—</option>
                <option value="phone">Telefon</option>
                <option value="email">E-mail</option>
                <option value="sms">SMS</option>
              </select>
            </label>
            <label class="form-field">
              <span class="form-field__label">Status potwierdzenia</span>
              <select name="confirmation_status">
                <option value="">—</option>
                <option value="awaiting">Oczekuje</option>
                <option value="confirmed">Potwierdzone</option>
              </select>
            </label>
          </div>

          <button type="submit" class="btn btn--primary">Zapisz wizytę</button>
        </form>
      </section>

      <section class="calendar__list card">
        <h2>Nadchodzące wizyty</h2>
        <?php if ($upcomingAppointments === []): ?>
          <p class="muted">Brak zaplanowanych wizyt w wybranym filtrze.</p>
        <?php else: ?>
          <table class="table">
            <thead>
              <tr>
                <th>Termin</th>
                <th>Tytuł</th>
                <th>Pracownicy</th>
                <th>Status</th>
                <th>Zlecenie</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($upcomingAppointments as $appointment): ?>
                <tr>
                  <td><?= htmlspecialchars((string) ($appointment['start_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td><?= htmlspecialchars((string) ($appointment['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                  <td>
                    <?php if (empty($appointment['attendees'])): ?>
                      <span class="muted">—</span>
                    <?php else: ?>
                      <ul class="attendee-list">
                        <?php foreach ($appointment['attendees'] as $attendee): ?>
                          <li><?= htmlspecialchars((string) ($attendee['full_name'] ?? 'Pracownik'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </td>
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