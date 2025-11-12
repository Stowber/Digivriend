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
$privateCustomers = $customerRepository->listCustomers($searchTerm !== '' ? $searchTerm : null, $limit, 'private');
$businessCustomers = $customerRepository->listCustomers($searchTerm !== '' ? $searchTerm : null, $limit, 'business');
$privateCount = count($privateCustomers);
$businessCount = count($businessCustomers);
$totalCustomers = $privateCount + $businessCount;
$locale = Translator::locale();
$decimalSeparator = '.';
$thousandsSeparator = ',';

if ($locale === 'nl') {
    $decimalSeparator = ',';
    $thousandsSeparator = '.';
} elseif ($locale === 'pl') {
    $decimalSeparator = ',';
    $thousandsSeparator = ' ';
}

$formatNumber = static function (int $value) use ($decimalSeparator, $thousandsSeparator): string {
    return number_format($value, 0, $decimalSeparator, $thousandsSeparator);
};
$createdMessage = '';
if (isset($_GET['created']) && $_GET['created'] === '1') {
    $createdMessage = __('customers.messages.created');
}

$customerGroups = [
    [
        'type' => 'private',
        'title' => __('customers.list.private_title'),
        'description' => __('customers.list.private_description'),
        'customers' => $privateCustomers,
        'count' => $privateCount,
        'empty' => __('customers.list.empty_private'),
    ],
    [
        'type' => 'business',
        'title' => __('customers.list.business_title'),
        'description' => __('customers.list.business_description'),
        'customers' => $businessCustomers,
        'count' => $businessCount,
        'empty' => __('customers.list.empty_business'),
    ],
];

$defaultGroup = $privateCount > 0 ? 'private' : ($businessCount > 0 ? 'business' : 'private');

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

    <section class="card customers-insights" aria-labelledby="customers-insights-title">
      <header class="customers-insights__header">
        <div>
          <p class="customers-eyebrow"><?= htmlspecialchars(__('customers.insights.eyebrow'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
          <h2 id="customers-insights-title"><?= htmlspecialchars(__('customers.insights.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p class="customers-insights__intro"><?= htmlspecialchars(__('customers.insights.description'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
      </header>
      <div class="customers-insights__grid" role="list">
        <article class="customers-insights__item" role="listitem">
          <span class="customers-insights__label"><?= htmlspecialchars(__('customers.insights.total.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customers-insights__value"><?= htmlspecialchars($formatNumber($totalCustomers), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <p class="customers-insights__hint"><?= htmlspecialchars(__('customers.insights.total.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
        <article class="customers-insights__item" role="listitem">
          <span class="customers-insights__label"><?= htmlspecialchars(__('customers.insights.private.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customers-insights__value"><?= htmlspecialchars($formatNumber($privateCount), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <p class="customers-insights__hint"><?= htmlspecialchars(__('customers.insights.private.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
        <article class="customers-insights__item" role="listitem">
          <span class="customers-insights__label"><?= htmlspecialchars(__('customers.insights.business.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customers-insights__value"><?= htmlspecialchars($formatNumber($businessCount), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <p class="customers-insights__hint"><?= htmlspecialchars(__('customers.insights.business.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
        <article class="customers-insights__item" role="listitem">
          <span class="customers-insights__label"><?= htmlspecialchars(__('customers.insights.search.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customers-insights__value customers-insights__value--small">
            <?= htmlspecialchars($searchTerm !== ''
              ? __('customers.insights.search.value', ['term' => $searchTerm])
              : __('customers.insights.search.empty'),
              ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </span>
          <p class="customers-insights__hint"><?= htmlspecialchars(__('customers.insights.search.hint'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
        <article class="customers-insights__item" role="listitem">
          <span class="customers-insights__label"><?= htmlspecialchars(__('customers.insights.limit.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <span class="customers-insights__value"><?= htmlspecialchars($formatNumber($limit), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <p class="customers-insights__hint"><?= htmlspecialchars(__('customers.insights.limit.hint', ['limit' => $formatNumber($limit)]), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </article>
      </div>
    </section>

    <section class="card customers-search-card">
      <form action="customers.php" method="get" class="customers-search" role="search" aria-label="<?= htmlspecialchars(__('customers.search.aria'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <div class="customers-search__field">
          <label class="customers-search__label" for="customer-search-input">
            <span><?= htmlspecialchars(__('customers.search.label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          </label>
          <div class="customers-search__control">
            <input id="customer-search-input" type="search" name="q" value="<?= htmlspecialchars($searchTerm, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="<?= htmlspecialchars(__('customers.search.placeholder'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if ($searchTerm !== ''): ?>
              <a href="customers.php" class="customers-search__reset" aria-label="<?= htmlspecialchars(__('customers.search.reset'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars(__('customers.search.reset'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </a>
            <?php endif; ?>
          </div>
        </div>
        <button type="submit" class="btn btn--secondary customers-search__submit">
          <?= htmlspecialchars(__('customers.search.submit'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
        </button>
      </form>
    </section>

    <section class="card customers-list-card">
      <header class="customers-list-header">
        <div>
          <h2><?= htmlspecialchars(__('customers.list.title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
          <p><?= htmlspecialchars(__('customers.list.subtitle'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>
        <div
          class="customer-toggle"
          role="radiogroup"
          aria-label="<?= htmlspecialchars(__('customers.list.filter_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-active="<?= htmlspecialchars($defaultGroup, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
        >
          <span class="visually-hidden"><?= htmlspecialchars(__('customers.list.filter_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
          <input
            type="radio"
            id="customer-toggle-private"
            name="customer-type"
            value="private"
            <?= $defaultGroup === 'private' ? 'checked' : '' ?>
          >
          <label for="customer-toggle-private">
            <?= htmlspecialchars(__('customers.list.filter_private'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </label>
          <input
            type="radio"
            id="customer-toggle-business"
            name="customer-type"
            value="business"
            <?= $defaultGroup === 'business' ? 'checked' : '' ?>
          >
          <label for="customer-toggle-business">
            <?= htmlspecialchars(__('customers.list.filter_business'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
          </label>
          <span class="customer-toggle__indicator" aria-hidden="true"></span>
        </div>
      </header>
      <div class="customers-list-sections">
        <?php foreach ($customerGroups as $group): ?>
          <section
            class="customers-list-section"
            data-customer-group="<?= htmlspecialchars((string) $group['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
            <?= $group['type'] === $defaultGroup ? '' : 'hidden' ?>
          >
            <h3 class="visually-hidden">
              <?= htmlspecialchars((string) $group['title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </h3>
            <div class="customers-list-section__summary">
              <p class="customers-list-section__description">
                <?= htmlspecialchars((string) $group['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
              <span class="customers-list-section__count">
                <?= htmlspecialchars($formatNumber((int) $group['count']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
            <?php if ($group['customers'] === []): ?>
              <p class="customers-list__empty">
                <?= htmlspecialchars((string) $group['empty'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </p>
            <?php else: ?>
              <ul class="customers-list">
                <?php foreach ($group['customers'] as $customer): ?>
                  <?php
                    $customerCode = isset($customer['customer_code']) && trim((string) $customer['customer_code']) !== ''
                      ? (string) $customer['customer_code']
                      : __('customers.list.table.no_code');
                    $email = isset($customer['email']) && $customer['email'] !== null && $customer['email'] !== ''
                      ? (string) $customer['email']
                      : '—';
                    $phone = isset($customer['phone']) && $customer['phone'] !== null && $customer['phone'] !== ''
                      ? (string) $customer['phone']
                      : '—';
                    $city = isset($customer['city']) && $customer['city'] !== null && $customer['city'] !== ''
                      ? (string) $customer['city']
                      : '—';
                    $address = isset($customer['address']) && $customer['address'] !== null && $customer['address'] !== ''
                      ? (string) $customer['address']
                      : '—';
                    $updatedAt = isset($customer['updated_at']) && $customer['updated_at'] !== null
                      ? date('d-m-Y H:i', strtotime((string) $customer['updated_at']))
                      : '—';
                    $isSuspicious = isset($customer['is_suspicious']) && (int) $customer['is_suspicious'] === 1;
                    $suspiciousReason = $isSuspicious ? (string) ($customer['suspicious_reason'] ?? '') : '';
                  ?>
                  <li class="customers-list__item<?= $isSuspicious ? ' customers-list__item--suspicious' : '' ?>" data-href="customer.php?id=<?= (int) $customer['id'] ?>" role="link" tabindex="0">
                    <div class="customers-list__identity">
                      <span class="code-badge">
                        <?= htmlspecialchars($customerCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </span>
                      <div>
                        <strong><?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                        <?php if ($isSuspicious): ?>
                          <span class="customers-list__badge customers-list__badge--suspicious">
                            <?= htmlspecialchars(__('customers.list.suspicious_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                    <div class="customers-list__details">
                      <?php if ($isSuspicious): ?>
                        <div class="customers-list__group customers-list__group--suspicious">
                          <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.suspicious_reason_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <span>
                            <?= htmlspecialchars(
                                $suspiciousReason !== ''
                                    ? $suspiciousReason
                                    : __('customers.list.suspicious_reason_empty'),
                                ENT_QUOTES | ENT_SUBSTITUTE,
                                'UTF-8'
                            ) ?>
                          </span>
                        </div>
                      <?php endif; ?>
                      <div class="customers-list__group">
                        <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.table.contact'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($email, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($phone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </div>
                      <div class="customers-list__group">
                        <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.table.location'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($city, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($address, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </div>
                      <div class="customers-list__group customers-list__group--updated">
                        <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.table.updated'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span><?= htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      </div>
                    </div>
                  </li>
                <?php endforeach; ?>
              </ul>
            <?php endif; ?>
            </div>
          </section>
        <?php endforeach; ?>
      </div>
    </section>
  </main>
  <script>
    (function () {
      const items = document.querySelectorAll('.customers-list__item');
      items.forEach(function (item) {
        item.addEventListener('click', function () {
          const href = item.getAttribute('data-href');
          if (href) {
            window.location.href = href;
          }
        });

        item.addEventListener('keydown', function (event) {
          if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            const href = item.getAttribute('data-href');
            if (href) {
              window.location.href = href;
            }
          }
        });
      });
    const toggle = document.querySelector('.customer-toggle');
      if (!toggle) {
        return;
      }

      const sections = document.querySelectorAll('.customers-list-section');
      const inputs = toggle.querySelectorAll('input[name="customer-type"]');

      const setActiveGroup = function (type) {
        toggle.dataset.active = type;
        sections.forEach(function (section) {
          if (section.getAttribute('data-customer-group') === type) {
            section.removeAttribute('hidden');
          } else {
            section.setAttribute('hidden', 'hidden');
          }
        });
      };

      inputs.forEach(function (input) {
        input.addEventListener('change', function () {
          if (input.checked) {
            setActiveGroup(input.value);
          }
        });
      });

      const defaultInput = toggle.querySelector('input[name="customer-type"]:checked') || inputs[0];
      if (defaultInput) {
        setActiveGroup(defaultInput.value);
      }
    })();
  </script>
</body>
</html>