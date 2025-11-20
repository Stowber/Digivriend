<?php

declare(strict_types=1);

use App\Support\Repositories\PcBuildRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';

$pcBuildRepository = new PcBuildRepository($pdo);
$buildStatusLabels = $pcBuildRepository->statusLabels();

$pageParam = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$currentPage = $pageParam !== false && $pageParam !== null && $pageParam > 0
    ? (int) $pageParam
    : 1;
$perPage = 20;

$paginated = $pcBuildRepository->paginatedBuilds($currentPage, $perPage);
$builds = $paginated['items'];
$pagination = $paginated['pagination'];
$totalBuilds = (int) ($pagination['total'] ?? count($builds));
$currentPage = (int) ($pagination['current_page'] ?? $currentPage);
$totalPages = max(1, (int) ($pagination['total_pages'] ?? 1));
$prevPage = $currentPage > 1 ? $currentPage - 1 : null;
$nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Wszystkie buildy PC - Digivriend</title>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/pc-builder.css">
  <script src="js/pc-builder.js" defer></script>
</head>
<body<?= platform_body_attributes(); ?>>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle"><?= htmlspecialchars(platform_subtitle(), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('pc_builder'); ?>
      </nav>
    </div>
  </header>

  <main class="container pc-builds">
    <div class="pc-builds__header">
      <div>
        <h1>Wszystkie buildy PC</h1>
        <p>Przeglądaj historię konfiguracji, filtruj statusy i otwieraj szczegóły buildu w jednym widoku.</p>
      </div>
      <div class="pc-builds__actions">
        <a class="btn btn--ghost" href="pc-builder.php">Powrót do kreatora</a>
      </div>
    </div>

    <section class="pc-builds__list" aria-label="Lista buildów">
      <header class="pc-builds__list-header">
        <h2>Łącznie buildów: <?= $totalBuilds ?></h2>
        <p>Strona <?= $currentPage ?> z <?= $totalPages ?></p>
      </header>
      <?php if ($builds === []): ?>
        <p>Brak zapisanych buildów w systemie.</p>
      <?php else: ?>
        <ul class="pc-builds__rows">
          <?php foreach ($builds as $build): ?>
            <?php
                $buildId = (int) ($build['id'] ?? 0);
                if ($buildId <= 0) {
                    continue;
                }
                $reference = (string) ($build['reference_code'] ?? '');
                $status = (string) ($build['status'] ?? '');
                $customer = (string) ($build['customer_name'] ?? '');
                $caseReference = (string) ($build['case_reference_code'] ?? '');
                $summary = trim((string) ($build['summary'] ?? ''));
                $caseSummary = trim((string) ($build['case_summary'] ?? ''));
                $updatedAt = (string) ($build['updated_at'] ?? '');
                $createdAt = (string) ($build['created_at'] ?? '');
                $profileManufacturer = (string) ($build['profile_manufacturer'] ?? '');
                $profileModel = (string) ($build['profile_model'] ?? '');
                $profileLabel = trim($profileManufacturer . ' ' . $profileModel);
                $statusLabel = $buildStatusLabels[$status] ?? $status;
            ?>
            <li>
              <button
                type="button"
                class="pc-builds__row"
                data-build-modal-trigger="<?= $buildId ?>"
                data-status="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              >
                <div class="pc-builds__row-top">
                  <span class="pc-builds__row-reference">
                    <?= htmlspecialchars($reference !== '' ? $reference : 'Brak kodu', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </span>
                  <span class="pc-builds__row-status">
                    <span class="pc-builder__status-badge" data-status="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </span>
                  </span>
                </div>
                <div class="pc-builds__row-meta">
                  <?php if ($customer !== ''): ?>
                    <span>Klient: <?= htmlspecialchars($customer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if ($caseReference !== ''): ?>
                    <span>Case: <?= htmlspecialchars($caseReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if ($profileLabel !== ''): ?>
                    <span>Profil: <?= htmlspecialchars($profileLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
                <?php if ($summary !== '' || $caseSummary !== ''): ?>
                  <p class="pc-builds__row-summary">
                    <?= htmlspecialchars($summary !== '' ? $summary : $caseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </p>
                <?php endif; ?>
                <div class="pc-builds__row-dates">
                  <?php if ($updatedAt !== ''): ?>
                    <time datetime="<?= htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      Aktualizacja: <?= htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </time>
                  <?php endif; ?>
                  <?php if ($createdAt !== '' && $createdAt !== $updatedAt): ?>
                    <time datetime="<?= htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                      Utworzono: <?= htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </time>
                  <?php endif; ?>
                </div>
                <?php if ($status === 'completed'): ?>
                  <p class="pc-builds__row-note">Build zatwierdzony — edycja jest zablokowana.</p>
                <?php endif; ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <nav class="pc-builds__pagination" aria-label="Nawigacja po stronach buildów">
      <span>Strona <?= $currentPage ?> z <?= $totalPages ?></span>
      <div class="pc-builds__pagination-actions">
        <?php if ($prevPage !== null): ?>
          <a class="btn btn--ghost" href="?page=<?= $prevPage ?>">&larr; Poprzednia</a>
        <?php else: ?>
          <span class="btn btn--ghost btn--disabled" aria-disabled="true">&larr; Poprzednia</span>
        <?php endif; ?>
        <?php if ($nextPage !== null): ?>
          <a class="btn" href="?page=<?= $nextPage ?>">Następna &rarr;</a>
        <?php else: ?>
          <span class="btn btn--ghost btn--disabled" aria-disabled="true">Następna &rarr;</span>
        <?php endif; ?>
      </div>
    </nav>

    <?php require __DIR__ . '/templates/partials/pc-build-detail-modal.php'; ?>
  </main>
</body>
</html>