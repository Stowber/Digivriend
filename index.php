<?php

declare(strict_types=1);

use App\Support\Lang\Translator;
use App\Support\Repositories\AppointmentRepository;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\CustomerRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

if (!function_exists('translate_dashboard_enum')) {
    function translate_dashboard_enum(string $prefix, string $key): string
    {
        $normalizedKey = strtolower(str_replace(' ', '_', $key));
        $translationKey = $prefix . $normalizedKey;
        $translated = __($translationKey);

        if ($translated === $translationKey) {
            return ucfirst(str_replace('_', ' ', $normalizedKey));
        }

        return $translated;
    }
}

$caseRepository = new CaseRepository($pdo);
$appointmentRepository = new AppointmentRepository($pdo);
$customerRepository = new CustomerRepository($pdo);

$now = new DateTimeImmutable('now');
$todayString = $now->format('Y-m-d');
$nowString = $now->format('Y-m-d H:i:s');

$upcomingAppointmentsRaw = $appointmentRepository->upcoming($nowString, null, null, null, 12);
$upcomingAppointments = array_values(array_filter($upcomingAppointmentsRaw, static function (array $appointment): bool {
    $status = strtolower((string) ($appointment['status'] ?? ''));

    return !in_array($status, ['cancelled', 'completed'], true);
}));
$upcomingAppointments = array_slice($upcomingAppointments, 0, 5);

$appointmentCustomerLookup = [];
if ($upcomingAppointments !== []) {
    $customerIds = array_values(array_filter(array_unique(array_map(
        static function (array $appointment): int {
            $customerId = $appointment['customer_id'] ?? null;

            return is_numeric($customerId) ? (int) $customerId : 0;
        },
        $upcomingAppointments
    ))));

    if ($customerIds !== []) {
        $placeholders = implode(',', array_fill(0, count($customerIds), '?'));
        $statement = $pdo->prepare('SELECT id, full_name, email, phone FROM customers WHERE id IN (' . $placeholders . ')');

        foreach ($customerIds as $index => $customerId) {
            $statement->bindValue($index + 1, $customerId, PDO::PARAM_INT);
        }
        $statement->execute();
        foreach ($statement->fetchAll() ?: [] as $customerRow) {
            $customerId = (int) ($customerRow['id'] ?? 0);
            if ($customerId > 0) {
                $appointmentCustomerLookup[$customerId] = $customerRow;
            }
        }
    }
}

$typeFilter = filter_input(INPUT_GET, 'type', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'all';
$caseType = $typeFilter === 'all' ? null : $typeFilter;
$recentCases = $caseRepository->recentCaseOverview(6, $caseType);
$recentCustomers = $customerRepository->listCustomers(null, 6);

$openCases = (int) ($pdo->query("SELECT COUNT(*) FROM cases WHERE status NOT IN ('opgehaald', 'gesloten')")?->fetchColumn() ?: 0);
$totalCustomers = (int) ($pdo->query('SELECT COUNT(*) FROM customers')?->fetchColumn() ?: 0);
$upcomingAppointmentsCount = count($upcomingAppointments);
$appointmentsToday = count(array_filter($upcomingAppointments, static function (array $appointment) use ($todayString): bool {
    $startAt = $appointment['start_at'] ?? null;
    if (!is_string($startAt) || trim($startAt) === '') {
        return false;
    }

    $startDate = date_create_immutable($startAt);

    return $startDate instanceof DateTimeImmutable && $startDate->format('Y-m-d') === $todayString;
}));

$caseTypes = $pdo->query('SELECT DISTINCT type FROM cases ORDER BY type')?->fetchAll(PDO::FETCH_COLUMN) ?: [];

$caseTypeLabel = $caseType === null
    ? __('dashboard.minimal.cases.filter_all')
    : translate_dashboard_enum('dashboard.case_types.', (string) $caseType);

$formatDate = static function (?string $value, string $format = 'd-m-Y H:i'): ?string {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $date = date_create_immutable($value);

    return $date instanceof DateTimeImmutable ? $date->format($format) : null;
};

$formatTime = static function (?string $value, string $format = 'H:i'): ?string {
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    $date = date_create_immutable($value);

    return $date instanceof DateTimeImmutable ? $date->format($format) : null;
};

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('dashboard.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="<?= htmlspecialchars(__('dashboard.header.logo_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title"><?= htmlspecialchars(__('app.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="logo__subtitle"><?= htmlspecialchars(__('dashboard.header.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="<?= htmlspecialchars(__('nav.aria.main'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php render_main_nav('dashboard'); ?>
      </nav>
    </div>
  </header>

  <main class="container dashboard">
    <section class="dashboard__intro">
      <div class="dashboard__heading">
        <span class="dashboard__badge"><?= htmlspecialchars(__('dashboard.minimal.badge'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
        <h1><?= htmlspecialchars(__('dashboard.minimal.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p><?= htmlspecialchars(__('dashboard.minimal.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="dashboard__summary">
        <article class="summary-card">
          <header>
            <span class="summary-card__label"><?= htmlspecialchars(__('dashboard.minimal.summary.open_cases.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="summary-card__hint"><?= htmlspecialchars(__('dashboard.minimal.summary.open_cases.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </header>
          <strong class="summary-card__value"><?= number_format($openCases, 0, ',', '.') ?></strong>
        </article>
        <article class="summary-card">
          <header>
            <span class="summary-card__label"><?= htmlspecialchars(__('dashboard.minimal.summary.upcoming.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="summary-card__hint"><?= htmlspecialchars(__('dashboard.minimal.summary.upcoming.hint', ['count' => number_format($appointmentsToday, 0, ',', '.')]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </header>
          <strong class="summary-card__value"><?= number_format($upcomingAppointmentsCount, 0, ',', '.') ?></strong>
        </article>
        <article class="summary-card">
          <header>
            <span class="summary-card__label"><?= htmlspecialchars(__('dashboard.minimal.summary.customers.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <span class="summary-card__hint"><?= htmlspecialchars(__('dashboard.minimal.summary.customers.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </header>
          <strong class="summary-card__value"><?= number_format($totalCustomers, 0, ',', '.') ?></strong>
        </article>
      </div>
    </section>

    <section class="dashboard__grid" aria-label="<?= htmlspecialchars(__('dashboard.minimal.grid_aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
      <article class="panel">
        <header class="panel__header">
          <div>
            <h2><?= htmlspecialchars(__('dashboard.minimal.appointments.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.minimal.appointments.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <a class="panel__action" href="calendar.php">
            <?= htmlspecialchars(__('dashboard.minimal.appointments.action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        </header>
        <?php if ($upcomingAppointments === []): ?>
          <p class="panel__empty"><?= htmlspecialchars(__('dashboard.minimal.appointments.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="panel-list">
            <?php foreach ($upcomingAppointments as $appointment): ?>
              <?php
                $appointmentId = (int) ($appointment['id'] ?? 0);
                $customerId = (int) ($appointment['customer_id'] ?? 0);
                $customer = $appointmentCustomerLookup[$customerId] ?? null;
                $startTime = $formatTime($appointment['start_at'] ?? null);
                $startDate = $formatDate($appointment['start_at'] ?? null, 'd-m');
                $status = (string) ($appointment['status'] ?? '');
                $statusLabel = $status !== ''
                    ? translate_dashboard_enum('dashboard.minimal.status.', $status)
                    : null;
                $title = (string) ($appointment['title'] ?? '');
                $caseId = $appointment['case_id'] ?? null;
              ?>
              <li class="panel-list__item">
                <div class="panel-list__item-header">
                  <span class="panel-list__time" aria-hidden="true">
                    <?php if ($startDate !== null && $startTime !== null): ?>
                      <span><?= htmlspecialchars($startDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <strong><?= htmlspecialchars($startTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <?php elseif ($startTime !== null): ?>
                      <strong><?= htmlspecialchars($startTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                    <?php else: ?>
                      &mdash;
                    <?php endif; ?>
                  </span>
                  <?php if ($statusLabel !== null): ?>
                    <span class="chip chip--status"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
                <div class="panel-list__item-body">
                  <strong class="panel-list__title">
                    <?= htmlspecialchars($title !== '' ? $title : __('dashboard.minimal.appointments.default_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </strong>
                  <?php if ($customer !== null): ?>
                    <span class="panel-list__subtitle"><?= htmlspecialchars((string) ($customer['full_name'] ?? __('dashboard.common.unknown')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <div class="panel-list__meta">
                    <?php if ($caseId !== null): ?>
                      <a class="panel-list__link" href="case.php?id=<?= (int) $caseId ?>">
                        <?= htmlspecialchars(__('dashboard.minimal.appointments.case_link', ['id' => (int) $caseId]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </a>
                    <?php endif; ?>
                    <?php if ($customer !== null): ?>
                      <details class="panel-list__details">
                        <summary><?= htmlspecialchars(__('dashboard.minimal.appointments.contact_toggle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></summary>
                        <div class="panel-list__details-grid">
                          <?php if (!empty($customer['phone'])): ?>
                            <a class="panel-list__link" href="tel:<?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                              <?= htmlspecialchars(__('dashboard.minimal.appointments.phone'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                              <span><?= htmlspecialchars((string) $customer['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </a>
                          <?php endif; ?>
                          <?php if (!empty($customer['email'])): ?>
                            <a class="panel-list__link" href="mailto:<?= htmlspecialchars((string) $customer['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                              <?= htmlspecialchars(__('dashboard.minimal.appointments.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                              <span><?= htmlspecialchars((string) $customer['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                            </a>
                          <?php endif; ?>
                        </div>
                      </details>
                    <?php endif; ?>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="panel">
        <header class="panel__header">
          <div>
            <h2><?= htmlspecialchars(__('dashboard.minimal.customers.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.minimal.customers.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <a class="panel__action" href="intake.php">
            <?= htmlspecialchars(__('dashboard.minimal.customers.action'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        </header>
        <?php if ($recentCustomers === []): ?>
          <p class="panel__empty"><?= htmlspecialchars(__('dashboard.minimal.customers.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="panel-list">
            <?php foreach ($recentCustomers as $customer): ?>
              <?php
                $customerId = (int) ($customer['id'] ?? 0);
                $name = (string) ($customer['full_name'] ?? __('dashboard.common.unknown'));
                $email = $customer['email'] ?? null;
                $phone = $customer['phone'] ?? null;
                $lastInteraction = $formatDate($customer['last_interaction_at'] ?? $customer['updated_at'] ?? null, 'd-m-Y');
              ?>
              <li class="panel-list__item">
                <div class="panel-list__item-body">
                  <strong class="panel-list__title"><?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <div class="panel-list__meta">
                    <?php if ($lastInteraction !== null): ?>
                      <span class="panel-list__meta-item">
                        <?= htmlspecialchars(__('dashboard.minimal.customers.last_seen', ['date' => $lastInteraction]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                    <?php endif; ?>
                  </div>
                  <div class="panel-list__actions">
                    <?php if (!empty($phone)): ?>
                      <a class="panel-list__action" href="tel:<?= htmlspecialchars((string) $phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= htmlspecialchars(__('dashboard.minimal.customers.call'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </a>
                    <?php endif; ?>
                    <?php if (!empty($email)): ?>
                      <a class="panel-list__action" href="mailto:<?= htmlspecialchars((string) $email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                        <?= htmlspecialchars(__('dashboard.minimal.customers.email'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </a>
                    <?php endif; ?>
                    <a class="panel-list__action" href="case.php?customer_id=<?= $customerId ?>">
                      <?= htmlspecialchars(__('dashboard.minimal.customers.new_case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>

      <article class="panel">
        <header class="panel__header">
          <div>
            <h2><?= htmlspecialchars(__('dashboard.minimal.cases.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
            <p><?= htmlspecialchars(__('dashboard.minimal.cases.subtitle', ['filter' => $caseTypeLabel]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          </div>
          <form class="panel__filter" method="get">
            <label for="caseTypeFilter"><?= htmlspecialchars(__('dashboard.minimal.cases.filter_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
            <select id="caseTypeFilter" name="type" onchange="this.form.submit()">
              <option value="all" <?= $caseType === null ? 'selected' : '' ?>><?= htmlspecialchars(__('dashboard.minimal.cases.filter_all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php foreach ($caseTypes as $caseTypeOption): ?>
                <?php if (!is_string($caseTypeOption) || trim($caseTypeOption) === '') { continue; } ?>
                <option value="<?= htmlspecialchars($caseTypeOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $caseTypeOption === $caseType ? 'selected' : '' ?>>
                  <?= htmlspecialchars(translate_dashboard_enum('dashboard.case_types.', $caseTypeOption), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        </header>
        <?php if ($recentCases === []): ?>
          <p class="panel__empty"><?= htmlspecialchars(__('dashboard.minimal.cases.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <ul class="panel-list">
            <?php foreach ($recentCases as $case): ?>
              <?php
                $caseId = (int) ($case['id'] ?? 0);
                $type = (string) ($case['type'] ?? '');
                $status = (string) ($case['status'] ?? '');
                $summary = (string) ($case['summary'] ?? '');
                $updatedAt = $formatDate($case['updated_at'] ?? null, 'd-m-Y H:i');
                $customerName = (string) ($case['full_name'] ?? __('dashboard.common.unknown'));
              ?>
              <li class="panel-list__item">
                <div class="panel-list__item-header">
                  <?php if ($type !== ''): ?>
                    <span class="chip"><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_types.', $type), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if ($status !== ''): ?>
                    <span class="chip chip--status"><?= htmlspecialchars(translate_dashboard_enum('dashboard.case_statuses.', $status), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
                <div class="panel-list__item-body">
                  <strong class="panel-list__title">#<?= $caseId ?> · <?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  <?php if ($summary !== ''): ?>
                    <p class="panel-list__description"><?= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php endif; ?>
                  <div class="panel-list__meta">
                    <?php if ($updatedAt !== null): ?>
                      <span class="panel-list__meta-item"><?= htmlspecialchars(__('dashboard.minimal.cases.updated', ['time' => $updatedAt]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="panel-list__actions">
                    <a class="panel-list__action" href="case.php?id=<?= $caseId ?>">
                      <?= htmlspecialchars(__('dashboard.minimal.cases.view_case'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                    <a class="panel-list__action" href="devices.php?case_id=<?= $caseId ?>">
                      <?= htmlspecialchars(__('dashboard.minimal.cases.related_devices'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </a>
                  </div>
                </div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </article>
    </section>
  </main>
</body>
</html>
