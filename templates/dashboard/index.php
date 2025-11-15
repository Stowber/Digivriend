<?php

declare(strict_types=1);

/** @var array<int, array<string, mixed>> $upcomingAppointments */
/** @var array<int, array<string, mixed>> $recentCases */
/** @var array<int, array<string, mixed>> $recentCustomers */
/** @var array<int, mixed> $caseTypes */
/** @var array<int, array<string, mixed>> $appointmentCustomerLookup */
/** @var callable $formatDate */
/** @var callable $formatTime */
/** @var callable $translateDashboardEnum */
/** @var string|null $caseType */
/** @var string $caseTypeLabel */
/** @var string $typeFilter */
/** @var int $openCases */
/** @var int $totalCustomers */
/** @var int $upcomingAppointmentsCount */
/** @var int $appointmentsToday */

?>
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
                  ? $translateDashboardEnum('dashboard.minimal.status.', $status)
                  : __('dashboard.minimal.status.unknown');
            ?>
            <li class="panel-list__item">
              <div class="panel-list__meta">
                <span class="panel-list__date"><?= htmlspecialchars((string) $startDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="panel-list__time"><?= htmlspecialchars((string) $startTime, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
              <div class="panel-list__content">
                <h3>
                  <a href="calendar.php?id=<?= $appointmentId ?>" class="panel-list__link">
                    <?= htmlspecialchars((string) ($appointment['title'] ?? __('dashboard.minimal.appointments.untitled')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </a>
                </h3>
                <?php if (is_array($customer)): ?>
                  <p class="panel-list__hint">
                    <?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </p>
                <?php endif; ?>
              </div>
              <span class="panel-list__status">
                <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>

    <article class="panel">
      <header class="panel__header">
        <div>
          <h2><?= htmlspecialchars(__('dashboard.minimal.cases.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(__('dashboard.minimal.cases.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <form class="panel__filters" method="get" action="index.php">
          <label for="case-type-filter" class="sr-only"><?= htmlspecialchars(__('dashboard.minimal.cases.filter_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></label>
          <select id="case-type-filter" name="type" onchange="this.form.submit()">
            <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>>
              <?= htmlspecialchars(__('dashboard.minimal.cases.filter_all'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </option>
            <?php foreach ($caseTypes as $type): ?>
              <?php $value = (string) $type; ?>
              <option value="<?= htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $caseType === $value ? 'selected' : '' ?>>
                <?= htmlspecialchars($translateDashboardEnum('dashboard.case_types.', $value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
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
              $caseNumber = (string) ($case['case_number'] ?? '');
              $caseStatus = (string) ($case['status'] ?? '');
              $caseStatusLabel = $caseStatus !== ''
                  ? $translateDashboardEnum('dashboard.minimal.status.', $caseStatus)
                  : __('dashboard.minimal.status.unknown');
              $caseUpdatedAt = $formatDate($case['updated_at'] ?? null);
              $customerName = (string) ($case['customer_name'] ?? '');
              $deviceName = (string) ($case['device_name'] ?? '');
            ?>
            <li class="panel-list__item">
              <div class="panel-list__meta">
                <span class="panel-list__date"><?= htmlspecialchars((string) $caseUpdatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </div>
              <div class="panel-list__content">
                <h3>
                  <a href="case.php?id=<?= $caseId ?>" class="panel-list__link">
                    <?= htmlspecialchars($caseNumber, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </a>
                </h3>
                <p class="panel-list__hint">
                  <?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> –
                  <?= htmlspecialchars($deviceName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </p>
              </div>
              <span class="panel-list__status">
                <?= htmlspecialchars($caseStatusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
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
        <a class="panel__action" href="customers.php">
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
              $customerName = (string) ($customer['full_name'] ?? '');
              $customerEmail = (string) ($customer['email'] ?? '');
              $customerType = (string) ($customer['type'] ?? '');
              $customerTypeLabel = $customerType !== ''
                  ? $translateDashboardEnum('dashboard.minimal.customer_types.', $customerType)
                  : __('dashboard.minimal.customer_types.unknown');
            ?>
            <li class="panel-list__item">
              <div class="panel-list__content">
                <h3>
                  <a href="customer.php?id=<?= $customerId ?>" class="panel-list__link">
                    <?= htmlspecialchars($customerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </a>
                </h3>
                <p class="panel-list__hint">
                  <?= htmlspecialchars($customerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </p>
              </div>
              <span class="panel-list__status">
                <?= htmlspecialchars($customerTypeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </article>
  </section>
</main>