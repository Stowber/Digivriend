<?php

declare(strict_types=1);

use App\Support\Lang\Translator;
use App\Support\Repositories\CustomerRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$customerRepository = new CustomerRepository($pdo);

$searchTerm = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';
$limit = 200;
$customers = $customerRepository->listCustomers($searchTerm !== '' ? $searchTerm : null, $limit);
$createdMessage = '';
if (isset($_GET['created']) && $_GET['created'] === '1') {
    $createdMessage = __('customers.messages.created');
}

?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars(Translator::locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
<head>
  <meta charset="UTF-8">
  <title><?= htmlspecialchars(__('customers.meta.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/customers.css">
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
        <?php render_main_nav('customers'); ?>
      </nav>
    </div>
  </header>
  <main class="container customers-page">
    <div class="page-header">
      <div>
        <p class="page-eyebrow"><?= htmlspecialchars(__('customers.hero.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <h1><?= htmlspecialchars(__('customers.hero.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro"><?= htmlspecialchars(__('customers.hero.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </div>
      <div class="page-actions">
        <a href="customer-new.php" class="btn btn--primary">
          <?= htmlspecialchars(__('customers.hero.create'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </a>
      </div>
    </div>

    <?php if ($createdMessage !== ''): ?>
      <div class="alert alert--success">
        <?= htmlspecialchars($createdMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <section class="card">
      <form action="customers.php" method="get" class="customers-search" role="search" aria-label="<?= htmlspecialchars(__('customers.search.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <label class="customers-search__label">
          <span><?= htmlspecialchars(__('customers.search.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <input type="search" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('customers.search.placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        </label>
        <button type="submit" class="btn btn--secondary">
          <?= htmlspecialchars(__('customers.search.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
        <?php if ($searchTerm !== ''): ?>
          <a href="customers.php" class="btn btn--ghost customers-search__reset">
            <?= htmlspecialchars(__('customers.search.reset'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </a>
        <?php endif; ?>
      </form>
    </section>

    <section class="card">
      <header class="card__header">
        <h2><?= htmlspecialchars(__('customers.list.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(__('customers.list.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
      </header>
      <div class="table-wrapper">
        <table class="table">
          <thead>
            <tr>
              <th><?= htmlspecialchars(__('customers.list.table.code'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.list.table.name'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.list.table.contact'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.list.table.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
              <th><?= htmlspecialchars(__('customers.list.table.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php if ($customers === []): ?>
              <tr>
                <td colspan="5">
                  <?= htmlspecialchars(__('customers.list.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($customers as $customer): ?>
                <?php
                  $customerCode = isset($customer['customer_code']) && trim((string) $customer['customer_code']) !== ''
                    ? (string) $customer['customer_code']
                    : __('customers.list.table.no_code');
                ?>
                <tr class="customers-row" data-href="customer.php?id=<?= (int) $customer['id'] ?>">
                  <td>
                    <span class="code-badge">
                      <?= htmlspecialchars($customerCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  </td>
                  <td>
                    <strong><?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                  </td>
                  <td>
                    <div><?= htmlspecialchars((string) ($customer['email'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div><?= htmlspecialchars((string) ($customer['phone'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </td>
                  <td>
                    <div><?= htmlspecialchars((string) ($customer['city'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                    <div><?= htmlspecialchars((string) ($customer['address'] ?? '—'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
                  </td>
                  <td>
                    <?= htmlspecialchars(isset($customer['updated_at']) && $customer['updated_at'] !== null ? date('d-m-Y H:i', strtotime((string) $customer['updated_at'])) : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
  <script>
    document.querySelectorAll('.customers-row').forEach(function (row) {
      row.addEventListener('click', function () {
        const href = row.getAttribute('data-href');
        if (href) {
          window.location.href = href;
        }
      });
    });
  </script>
</body>
</html>