<?php

declare(strict_types=1);

use App\Support\Customers\SuspiciousFlagRegistry;

/** @var string $searchTerm */
/** @var string $createdMessage */
/** @var array<int, array<string, mixed>> $customerGroups */
/** @var string $defaultGroup */
/** @var callable $formatNumber */
/** @var int $totalCustomers */
/** @var int $privateCount */
/** @var int $businessCount */
/** @var int $limit */
/** @var array<string, array<string, string>> $suspiciousFlagDefinitions */
/** @var array<string, array<string, string>> $suspiciousBlockDefinitions */

?>
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
          <?= htmlspecialchars($searchTerm !== '' ? __('customers.insights.search.value', ['term' => $searchTerm]) : __('customers.insights.search.empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
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
          </div>
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
                  $suspiciousFlags = $isSuspicious
                    ? SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null)
                    : [];
                  $suspiciousFlagLabels = [];
                  foreach ($suspiciousFlags as $flagKey) {
                      if (!isset($suspiciousFlagDefinitions[$flagKey])) {
                          continue;
                      }
                      $suspiciousFlagLabels[] = __($suspiciousFlagDefinitions[$flagKey]['label']);
                  }
                  $suspiciousBlocks = $isSuspicious
                    ? SuspiciousFlagRegistry::blocksForFlags($suspiciousFlags)
                    : [];
                  $suspiciousBlockLabels = [];
                  foreach ($suspiciousBlocks as $blockKey) {
                      if (!isset($suspiciousBlockDefinitions[$blockKey])) {
                          continue;
                      }
                      $suspiciousBlockLabels[] = __($suspiciousBlockDefinitions[$blockKey]['label']);
                  }
                ?>
                <li class="customers-list__item<?= $isSuspicious ? ' customers-list__item--suspicious' : '' ?>" data-href="customer.php?id=<?= (int) $customer['id'] ?>" role="link" tabindex="0">
                  <div class="customers-list__identity">
                    <div class="customers-list__avatar" aria-hidden="true">
                      <span><?= htmlspecialchars(mb_strtoupper(mb_substr((string) ($customer['full_name'] ?? ''), 0, 2, 'UTF-8'), 'UTF-8'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </div>
                    <div class="customers-list__info">
                      <strong><?= htmlspecialchars((string) ($customer['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
                      <div class="customers-list__meta">
                        <span><?= htmlspecialchars($customerCode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <?php if ($isSuspicious): ?>
                          <span class="customers-list__badge customers-list__badge--warning">
                            <?= htmlspecialchars(__('customers.list.suspicious_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                  <div class="customers-list__details">
                    <?php if ($isSuspicious): ?>
                      <div class="customers-list__group customers-list__group--suspicious">
                        <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.suspicious_reason_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        <span>
                          <?= htmlspecialchars($suspiciousReason !== '' ? $suspiciousReason : __('customers.list.suspicious_reason_empty'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </span>
                      </div>
                      <?php if ($suspiciousFlagLabels !== []): ?>
                        <div class="customers-list__group customers-list__group--suspicious">
                          <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.suspicious_flags_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <span><?= htmlspecialchars(implode(', ', $suspiciousFlagLabels), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                      <?php endif; ?>
                      <?php if ($suspiciousBlockLabels !== []): ?>
                        <div class="customers-list__group customers-list__group--suspicious">
                          <span class="customers-list__label"><?= htmlspecialchars(__('customers.list.suspicious_blocks_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                          <span><?= htmlspecialchars(implode(', ', $suspiciousBlockLabels), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                        </div>
                        <p class="customers-list__note customers-list__note--suspicious">
                          <?= htmlspecialchars(__('customers.list.suspicious_contact'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        </p>
                      <?php endif; ?>
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