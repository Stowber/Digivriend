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
$totalAttendees = array_reduce(
    $upcomingAppointments,
    static fn (int $carry, array $appointment): int => $carry + count($appointment['attendees'] ?? []),
    0
);
$closestAppointment = null;
if ($upcomingAppointments !== []) {
    $sortedAppointments = $upcomingAppointments;
    usort(
        $sortedAppointments,
        static function (array $first, array $second): int {
            return strcmp((string) ($first['start_at'] ?? ''), (string) ($second['start_at'] ?? ''));
        }
    );
    $closestAppointment = $sortedAppointments[0];
}
$formatDateTime = static function ($value): string {
    if ($value === null || $value === '') {
        return '';
    }

    try {
        return (new \DateTimeImmutable((string) $value))->format('d-m-Y H:i');
    } catch (\Throwable $exception) {
        return (string) $value;
    }
};
$closestAppointmentStartDisplay = $closestAppointment ? $formatDateTime($closestAppointment['start_at'] ?? null) : null;
$csrfToken = Csrf::token();

?><!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <title>Kalendarz - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/management-ui.css">
</head>
<body class="page--calendar">
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo">Digivriend</a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('calendar'); ?>
      </nav>
    </div>
  </header>

  <section class="workspace">
    <div class="container">
      <div class="hero">
        <div class="hero__header">
          <div>
            <h1>Panel kalendarza</h1>
            <p class="hero__description">Planowanie wizyt, delegacji i odbiorów nigdy nie było prostsze. Zarządzaj harmonogramem zespołu z jednego, przejrzystego miejsca.</p>
          </div>
          <div class="quick-actions" role="group" aria-label="Szybkie akcje kalendarza">
            <button type="button" data-modal-target="modal-create-appointment">Nowa wizyta</button>
            <button type="button" data-modal-target="modal-upcoming-appointments">Przeglądaj wizyty</button>
          </div>
        </div>
        <form method="get" class="filter-panel" aria-label="Filtry kalendarza">
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
          <?php if ($caseFilter): ?>
            <input type="hidden" name="case" value="<?= (int) $caseFilter ?>">
          <?php endif; ?>
        </form>
      </div>
      <div class="stat-grid" aria-label="Podsumowanie kalendarza">
        <article class="stat-card">
          <span class="stat-card__label">Nadchodzące wizyty</span>
          <span class="stat-card__value"><?= count($upcomingAppointments) ?></span>
          <span class="stat-card__meta">w wybranych filtrach</span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label">Zespół w harmonogramie</span>
          <span class="stat-card__value"><?= $totalAttendees ?></span>
          <span class="stat-card__meta">łączna liczba przydzielonych pracowników</span>
        </article>
        <article class="stat-card">
          <span class="stat-card__label">Najbliższa wizyta</span>
          <?php if ($closestAppointment): ?>
            <span class="stat-card__value"><?= htmlspecialchars((string) ($closestAppointmentStartDisplay ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="stat-card__meta"><?= htmlspecialchars((string) ($closestAppointment['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <?php else: ?>
            <span class="stat-card__value">—</span>
            <span class="stat-card__meta">Brak wizyt w kalendarzu</span>
          <?php endif; ?>
        </article>
      </div>

    <div class="panel-grid">
        <section class="panel-card" aria-label="Lista zespołu">
          <div>
            <h2>Zespół serwisu</h2>
            <p class="hero__description">Kliknij, aby szybko przełączyć kalendarz na wybranego specjalistę.</p>
          </div>
          <?php if ($employees === []): ?>
            <p class="muted">Brak aktywnych pracowników.</p>
          <?php else: ?>
            <div class="people-grid" role="list">
              <?php foreach ($employees as $employee): ?>
                <?php $id = (int) ($employee['id'] ?? 0); ?>
                <?php
                  $linkQuery = ['employee' => $id];
                  if ($statusFilter !== null) {
                      $linkQuery['status'] = $statusFilter;
                  }
                  if ($caseFilter) {
                      $linkQuery['case'] = (int) $caseFilter;
                  }
                  $linkHref = 'calendar.php?' . http_build_query($linkQuery);
                ?>
                <a role="listitem" href="<?= htmlspecialchars($linkHref, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"<?= $employeeFilter === $id ? ' aria-current="true"' : '' ?>>
                  <strong><?= htmlspecialchars((string) ($employee['full_name'] ?? 'Nieznany'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <span class="muted"><?= htmlspecialchars((string) ($employee['position'] ?? $employee['role'] ?? 'Specjalista'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
          <div class="panel-card__action">
            <button type="button" data-modal-target="modal-create-appointment">Zaplanuj spotkanie</button>
          </div>
        </section>

        <section class="panel-card" aria-label="Szybki podgląd wizyt">
          <div>
            <h2>Następne wizyty</h2>
            <p class="hero__description">Zobacz, co czeka zespół w najbliższych dniach.</p>
          </div>
          <?php if ($upcomingAppointments === []): ?>
            <p class="muted">Brak wizyt do wyświetlenia.</p>
          <?php else: ?>
            <ul>
              <?php foreach (array_slice($upcomingAppointments, 0, 4) as $appointment): ?>
                <?php $startAtDisplay = $formatDateTime($appointment['start_at'] ?? null); ?>
                <li>
                  <strong><?= htmlspecialchars((string) ($appointment['title'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <span class="muted"><?= htmlspecialchars($startAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php if (!empty($appointment['attendees'])): ?>
                    <span class="muted">Ekipa: <?= htmlspecialchars(implode(', ', array_map(static fn (array $attendee): string => (string) ($attendee['full_name'] ?? 'Pracownik'), $appointment['attendees'] ?? [])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <div class="panel-card__action">
            <button type="button" data-modal-target="modal-upcoming-appointments">Pełna lista</button>
          </div>
        </section>

        <section class="panel-card" aria-label="Powiązane zlecenia">
          <div>
            <h2>Powiązane zlecenia</h2>
            <p class="hero__description">Szybki dostęp do ostatnich spraw klientów.</p>
          </div>
          <?php if ($cases === []): ?>
            <p class="muted">Brak zleceń do wyświetlenia.</p>
          <?php else: ?>
            <ul>
              <?php foreach (array_slice($cases, 0, 5) as $case): ?>
                <?php $caseId = (int) ($case['id'] ?? 0); ?>
                <li>
                  <strong>Case #<?= $caseId ?></strong>
                  <span class="muted"><?= htmlspecialchars((string) ($case['summary'] ?? $case['type'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php $firstCaseId = (int) ($cases[0]['id'] ?? 0); ?>
            <?php if ($firstCaseId): ?>
              <div class="panel-card__action">
                <a href="case.php?id=<?= $firstCaseId ?>">Otwórz najnowszą sprawę</a>
              </div>
            <?php endif; ?>
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
      <p>&copy; <?= date('Y') ?> Digivriend. Wszystkie prawa zastrzeżone.</p>
    </div>
  </footer>

  <div class="modal" id="modal-create-appointment" role="dialog" aria-modal="true" aria-labelledby="modal-create-appointment-title">
    <div class="modal__panel">
      <div class="modal__header">
        <h2 id="modal-create-appointment-title">Nowa wizyta</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="Zamknij">&times;</button>
      </div>
      <div class="modal__body">
        <form method="post" class="form-card" autocomplete="off">
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
             <span class="form-field__label">Zasoby (typ|nazwa|szczegóły) <?php render_field_help('appointment', 'resources'); ?></span>
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

          <div class="form-actions">
            <button type="submit" class="btn--primary">Zapisz wizytę</button>
          </div>
        </form>
       </div>
    </div>
  </div>

      <div class="modal" id="modal-upcoming-appointments" role="dialog" aria-modal="true" aria-labelledby="modal-upcoming-appointments-title">
    <div class="modal__panel">
      <div class="modal__header">
        <h2 id="modal-upcoming-appointments-title">Nadchodzące wizyty</h2>
        <button type="button" class="modal__close" data-modal-close aria-label="Zamknij">&times;</button>
      </div>
      <div class="modal__body">
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
                <?php $startAtDisplay = $formatDateTime($appointment['start_at'] ?? null); ?>
                <tr>
                   <td><?= htmlspecialchars($startAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
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
      </div>
    </div>
  </div>

  <script src="js/modals.js"></script>
  <script src="js/field-help.js"></script>
</body>
</html>