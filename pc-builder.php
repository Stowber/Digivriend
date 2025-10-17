<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\HardwareProfileRepository;
use App\Support\Repositories\PcBuildRepository;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/templates/partials/field-help.php';

$warehouseRepository = new WarehouseRepository($pdo);
$profileRepository = new HardwareProfileRepository($pdo);
$pcBuildRepository = new PcBuildRepository($pdo);

$caseOptions = $warehouseRepository->caseOptions();
$itemOptions = $warehouseRepository->itemOptions(200);
$profileOptions = $profileRepository->listProfiles(null, 200);
$caseProfileOptions = array_filter(
    $profileOptions,
    static fn (array $profile): bool => ($profile['type'] ?? '') === 'case'
);

$buildStatusLabels = $pcBuildRepository->statusLabels();
$metrics = $pcBuildRepository->metrics();
$buildStatusCounts = is_array($metrics['status_counts'] ?? null)
    ? $metrics['status_counts']
    : array_fill_keys(array_keys($buildStatusLabels), 0);
$otherStatusCount = (int) ($metrics['other_statuses'] ?? 0);
$totalBuilds = (int) ($metrics['total_builds'] ?? 0);
$totalComponents = (int) ($metrics['total_components'] ?? 0);
$totalLeftovers = (int) ($metrics['total_leftovers'] ?? 0);
$activeCases = (int) ($metrics['active_cases'] ?? 0);
$latestBuild = is_array($metrics['latest_build'] ?? null) ? $metrics['latest_build'] : null;

$errors = [
    'profile' => [],
    'build' => [],
    'component' => [],
    'leftover' => [],
];

$successMessage = null;
if (isset($_SESSION['pc_builder_success'])) {
    $successMessage = (string) $_SESSION['pc_builder_success'];
    unset($_SESSION['pc_builder_success']);
}

$createProfileValues = [
    'type' => 'case',
    'manufacturer' => '',
    'model' => '',
    'description' => '',
];

$createBuildValues = [
    'case_id' => '',
    'case_profile_id' => '',
    'status' => 'planning',
    'summary' => '',
];

$componentValues = [
    'build_id' => '',
    'item_id' => '',
    'quantity' => 1,
    'notes' => '',
];

$leftoverValues = [
    'build_id' => '',
    'name' => '',
    'quantity' => 1,
    'category' => 'leftover',
    'location' => 'Magazyn PC',
    'profile_id' => '',
    'notes' => '',
    'reference_code' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $target = &$errors['build'];
        switch ($action) {
            case 'create-profile':
                $target = &$errors['profile'];
                break;
            case 'add-component':
                $target = &$errors['component'];
                break;
            case 'add-leftover':
                $target = &$errors['leftover'];
                break;
        }
        $target['general'] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        switch ($action) {
            case 'create-profile':
                $createProfileValues['type'] = trim((string) ($_POST['type'] ?? 'case'));
                $createProfileValues['manufacturer'] = trim((string) ($_POST['manufacturer'] ?? ''));
                $createProfileValues['model'] = trim((string) ($_POST['model'] ?? ''));
                $createProfileValues['description'] = trim((string) ($_POST['description'] ?? ''));

                if ($createProfileValues['manufacturer'] === '') {
                    $errors['profile']['manufacturer'] = 'Podaj producenta.';
                }
                if ($createProfileValues['model'] === '') {
                    $errors['profile']['model'] = 'Podaj model.';
                }
                if (!$profileRepository->isValidType($createProfileValues['type'])) {
                    $errors['profile']['type'] = 'Wybierz prawidłowy typ.';
                }

                if ($errors['profile'] === []) {
                    try {
                        $profile = $profileRepository->createProfile(
                            $createProfileValues['type'],
                            $createProfileValues['manufacturer'],
                            $createProfileValues['model'],
                            $createProfileValues['description'] !== '' ? $createProfileValues['description'] : null
                        );
                        $_SESSION['pc_builder_success'] = sprintf(
                            'Dodano profil sprzętowy %s %s (%s).',
                            $profile['manufacturer'] ?? '',
                            $profile['model'] ?? '',
                            $profile['type'] ?? ''
                        );
                        Response::redirect('pc-builder.php?highlight_profile=' . (int) ($profile['id'] ?? 0));
                    } catch (Throwable $exception) {
                        $errors['profile']['general'] = 'Nie udało się zapisać profilu sprzętowego.';
                    }
                }
                break;

            case 'create-build':
                $createBuildValues['case_id'] = trim((string) ($_POST['case_id'] ?? ''));
                $createBuildValues['case_profile_id'] = trim((string) ($_POST['case_profile_id'] ?? ''));
                $createBuildValues['status'] = trim((string) ($_POST['status'] ?? 'planning'));
                $createBuildValues['summary'] = trim((string) ($_POST['summary'] ?? ''));

                $caseId = filter_var($createBuildValues['case_id'], FILTER_VALIDATE_INT);
                if ($caseId === false || $caseId <= 0) {
                    $errors['build']['case_id'] = 'Wybierz kartę serwisową.';
                }

                $caseProfileIdValue = $createBuildValues['case_profile_id'] !== ''
                    ? filter_var($createBuildValues['case_profile_id'], FILTER_VALIDATE_INT)
                    : null;
                if ($caseProfileIdValue === false) {
                    $caseProfileIdValue = null;
                }

                if (!$pcBuildRepository->isValidStatus($createBuildValues['status'])) {
                    $errors['build']['status'] = 'Wybierz prawidłowy status.';
                }

                if ($errors['build'] === [] && $caseId !== false && $caseId > 0) {
                    try {
                        $build = $pcBuildRepository->createBuild(
                            (int) $caseId,
                            $caseProfileIdValue !== null ? (int) $caseProfileIdValue : null,
                            $createBuildValues['status'],
                            $createBuildValues['summary'] !== '' ? $createBuildValues['summary'] : null,
                            Auth::username()
                        );
                        $_SESSION['pc_builder_success'] = sprintf(
                            'Utworzono budowę PC %s.',
                            $build['reference_code'] ?? ''
                        );
                        Response::redirect('pc-builder.php?highlight_build=' . (int) ($build['id'] ?? 0));
                    } catch (Throwable $exception) {
                        $errors['build']['general'] = 'Nie udało się zapisać budowy PC.';
                    }
                }
                break;

            case 'add-component':
                $componentValues['build_id'] = trim((string) ($_POST['build_id'] ?? ''));
                $componentValues['item_id'] = trim((string) ($_POST['item_id'] ?? ''));
                $componentValues['quantity'] = (int) ($_POST['quantity'] ?? 1);
                $componentValues['notes'] = trim((string) ($_POST['notes'] ?? ''));

                $componentBuildId = filter_var($componentValues['build_id'], FILTER_VALIDATE_INT);
                if ($componentBuildId === false || $componentBuildId <= 0) {
                    $errors['component']['build_id'] = 'Wybierz budowę PC.';
                }

                $componentItemId = filter_var($componentValues['item_id'], FILTER_VALIDATE_INT);
                if ($componentItemId === false || $componentItemId <= 0) {
                    $errors['component']['item_id'] = 'Wybierz pozycję magazynową.';
                }

                if ($componentValues['quantity'] <= 0) {
                    $errors['component']['quantity'] = 'Podaj dodatnią ilość.';
                }

                if ($errors['component'] === [] && $componentBuildId !== false && $componentItemId !== false) {
                    try {
                        $pcBuildRepository->addComponent(
                            (int) $componentBuildId,
                            (int) $componentItemId,
                            max(1, (int) $componentValues['quantity']),
                            $componentValues['notes'] !== '' ? $componentValues['notes'] : null
                        );
                        $_SESSION['pc_builder_success'] = 'Dodano komponent do budowy.';
                        Response::redirect('pc-builder.php?highlight_build=' . (int) $componentBuildId);
                    } catch (Throwable $exception) {
                        $errors['component']['general'] = 'Nie udało się zapisać komponentu.';
                    }
                }
                break;

            case 'add-leftover':
                $leftoverValues['build_id'] = trim((string) ($_POST['build_id'] ?? ''));
                $leftoverValues['name'] = trim((string) ($_POST['name'] ?? ''));
                $leftoverValues['quantity'] = (int) ($_POST['quantity'] ?? 1);
                $leftoverValues['category'] = trim((string) ($_POST['category'] ?? 'leftover'));
                $leftoverValues['location'] = trim((string) ($_POST['location'] ?? 'Magazyn PC'));
                $leftoverValues['profile_id'] = trim((string) ($_POST['profile_id'] ?? ''));
                $leftoverValues['notes'] = trim((string) ($_POST['notes'] ?? ''));
                $leftoverValues['reference_code'] = trim((string) ($_POST['reference_code'] ?? ''));

                $leftoverBuildId = filter_var($leftoverValues['build_id'], FILTER_VALIDATE_INT);
                if ($leftoverBuildId === false || $leftoverBuildId <= 0) {
                    $errors['leftover']['build_id'] = 'Wybierz budowę PC.';
                }

                if ($leftoverValues['name'] === '') {
                    $errors['leftover']['name'] = 'Podaj nazwę pozostałości.';
                }

                if ($leftoverValues['quantity'] < 0) {
                    $errors['leftover']['quantity'] = 'Ilość nie może być ujemna.';
                }

                $leftoverProfileId = null;
                if ($leftoverValues['profile_id'] !== '') {
                    $profileIdValue = filter_var($leftoverValues['profile_id'], FILTER_VALIDATE_INT);
                    if ($profileIdValue === false) {
                        $errors['leftover']['profile_id'] = 'Wybierz prawidłowy profil kompatybilności.';
                    } else {
                        $leftoverProfileId = (int) $profileIdValue;
                    }
                }

                if ($errors['leftover'] === [] && $leftoverBuildId !== false) {
                    try {
                        $build = $pcBuildRepository->findBuild((int) $leftoverBuildId);
                        if ($build === null) {
                            throw new RuntimeException('Nie znaleziono budowy PC.');
                        }

                        $caseId = isset($build['case_id']) ? (int) $build['case_id'] : null;
                        $item = $warehouseRepository->createItem(
                            $leftoverValues['name'],
                            max(0, (int) $leftoverValues['quantity']),
                            $leftoverValues['category'] !== '' ? $leftoverValues['category'] : null,
                            $leftoverValues['location'] !== '' ? $leftoverValues['location'] : null,
                            $caseId,
                            $leftoverValues['notes'] !== '' ? $leftoverValues['notes'] : null,
                            'received',
                            $leftoverValues['reference_code'] !== '' ? $leftoverValues['reference_code'] : null,
                            Auth::username()
                        );

                        if ($leftoverProfileId !== null) {
                            $profileRepository->attachToItem((int) ($item['id'] ?? 0), $leftoverProfileId, 'leftover');
                        }

                        $pcBuildRepository->addLeftover(
                            (int) $leftoverBuildId,
                            (int) ($item['id'] ?? 0),
                            $leftoverProfileId,
                            max(0, (int) $leftoverValues['quantity']),
                            $leftoverValues['notes'] !== '' ? $leftoverValues['notes'] : null
                        );

                        $_SESSION['pc_builder_success'] = 'Dodano pozostałość do magazynu i powiązano z budową.';
                        Response::redirect('pc-builder.php?highlight_build=' . (int) $leftoverBuildId);
                    } catch (Throwable $exception) {
                        $errors['leftover']['general'] = 'Nie udało się zapisać pozostałości.';
                    }
                }
                break;

            default:
                $errors['build']['general'] = 'Nieznana akcja formularza.';
        }
    }
}

$highlightBuildRaw = filter_input(INPUT_GET, 'highlight_build', FILTER_VALIDATE_INT);
$highlightBuildId = $highlightBuildRaw !== false && $highlightBuildRaw !== null ? (int) $highlightBuildRaw : null;
$highlightProfileRaw = filter_input(INPUT_GET, 'highlight_profile', FILTER_VALIDATE_INT);
$highlightProfileId = $highlightProfileRaw !== false && $highlightProfileRaw !== null ? (int) $highlightProfileRaw : null;

$profileFilterRaw = filter_input(INPUT_GET, 'profile', FILTER_VALIDATE_INT);
$profileFilterId = $profileFilterRaw !== false && $profileFilterRaw !== null ? (int) $profileFilterRaw : null;
$filteredProfile = $profileFilterId !== null ? $profileRepository->findProfile($profileFilterId) : null;
$filteredLeftovers = $filteredProfile !== null
    ? $profileRepository->leftoverItemsForProfile((int) $filteredProfile['id'])
    : [];

$builds = $pcBuildRepository->listBuilds(null, 60);
$buildDetails = [];
foreach ($builds as $build) {
    $buildId = (int) ($build['id'] ?? 0);
    if ($buildId <= 0) {
        continue;
    }
    try {
        $buildDetails[$buildId] = $pcBuildRepository->buildDetails($buildId);
    } catch (Throwable $exception) {
        $buildDetails[$buildId] = null;
    }
}
$buildCount = count($builds);

$csrfToken = Csrf::token();
$typeLabels = $profileRepository->typeLabels();

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Budowa PC - Digivriend</title>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/pc-builder.css">
  <script src="js/pc-builder.js" defer></script>
</head>
<body>
  <header class="main-header">
    <div class="container">
      <a href="index.php" class="logo" aria-label="Digivriend dashboard">
        <span class="logo__mark" aria-hidden="true">DV</span>
        <span class="logo__text">
          <span class="logo__title">Digivriend</span>
          <span class="logo__subtitle">Serviceplatform</span>
        </span>
      </a>
      <nav class="main-nav" aria-label="Hoofd navigatie">
        <?php render_main_nav('pc_builder'); ?>
      </nav>
    </div>
  </header>

  <main class="container pc-builder">
    <div class="pc-builder__header">
      <div>
        <h1>System budowy PC</h1>
        <p>Zarządzaj zleceniami budowy komputerów, komponentami i pozostałościami kompatybilnymi z konkretnymi modelami.</p>
      </div>
      <div class="pc-builder__actions">
        <a class="btn btn--ghost" href="magazyn.php">Powrót do magazynu</a>
      </div>
    </div>

    <?php if ($successMessage !== null): ?>
      <div class="alert alert--success" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endif; ?>

    <section class="pc-builder__metrics" aria-label="Panel automatyzacji budów">
      <article class="pc-builder__metric-card">
        <header>
          <p class="pc-builder__metric-label">Łączna liczba budów</p>
          <p class="pc-builder__metric-value" data-build-total><?= $totalBuilds ?></p>
        </header>
        <p class="pc-builder__metric-subtitle">
          Dane odświeżane automatycznie na podstawie wpisów w systemie serwisowym.
        </p>
        <p class="pc-builder__metric-footnote">Aktywnych spraw: <strong><?= $activeCases ?></strong></p>
      </article>

      <article class="pc-builder__metric-card">
        <header>
          <p class="pc-builder__metric-label">Statusy budów</p>
        </header>
        <ul class="pc-builder__metric-list">
          <?php foreach ($buildStatusLabels as $statusKey => $statusLabel): ?>
            <li>
              <span><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <strong data-status-count="<?= htmlspecialchars($statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= (int) ($buildStatusCounts[$statusKey] ?? 0) ?>
              </strong>
            </li>
          <?php endforeach; ?>
          <?php if ($otherStatusCount > 0): ?>
            <li>
              <span>Inne</span>
              <strong><?= $otherStatusCount ?></strong>
            </li>
          <?php endif; ?>
        </ul>
      </article>

      <article class="pc-builder__metric-card">
        <header>
          <p class="pc-builder__metric-label">Komponenty przypisane</p>
          <p class="pc-builder__metric-value"><?= $totalComponents ?></p>
        </header>
        <p class="pc-builder__metric-subtitle">Łączna liczba sztuk użyta we wszystkich trwających budowach.</p>
        <p class="pc-builder__metric-footnote">Pozostałości w magazynie: <strong><?= $totalLeftovers ?></strong></p>
      </article>

      <article class="pc-builder__metric-card">
        <header>
          <p class="pc-builder__metric-label">Ostatnia aktywność</p>
          <?php if ($latestBuild !== null && ($latestBuild['reference_code'] ?? '') !== ''): ?>
            <p class="pc-builder__metric-value">
              <?= htmlspecialchars((string) $latestBuild['reference_code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
            </p>
          <?php else: ?>
            <p class="pc-builder__metric-value">—</p>
          <?php endif; ?>
        </header>
        <?php if ($latestBuild !== null): ?>
          <p class="pc-builder__metric-subtitle">
            Status: <strong><?= htmlspecialchars((string) ($buildStatusLabels[$latestBuild['status']] ?? $latestBuild['status'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong>
          </p>
          <p class="pc-builder__metric-footnote">Zaktualizowano: <?= htmlspecialchars((string) ($latestBuild['updated_at'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        <?php else: ?>
          <p class="pc-builder__metric-subtitle">Brak danych o ostatniej budowie.</p>
        <?php endif; ?>
      </article>
    </section>

    <section class="pc-builder__forms" aria-label="Zarządzanie profilami i budowami">
      <div class="pc-builder__form-card">
        <h2>Nowy profil sprzętowy</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create-profile">

          <label>
            Typ
            <select name="type" required>
              <?php foreach ($typeLabels as $typeKey => $typeLabel): ?>
                <option value="<?= htmlspecialchars($typeKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $createProfileValues['type'] === $typeKey ? 'selected' : '' ?>>
                  <?= htmlspecialchars($typeLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (isset($errors['profile']['type'])): ?><p class="form-error"><?= htmlspecialchars($errors['profile']['type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <label>
            Producent
            <input type="text" name="manufacturer" value="<?= htmlspecialchars($createProfileValues['manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </label>
          <?php if (isset($errors['profile']['manufacturer'])): ?><p class="form-error"><?= htmlspecialchars($errors['profile']['manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <label>
            Model
            <input type="text" name="model" value="<?= htmlspecialchars($createProfileValues['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
          </label>
          <?php if (isset($errors['profile']['model'])): ?><p class="form-error"><?= htmlspecialchars($errors['profile']['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <label>
            Opis (opcjonalnie)
            <textarea name="description" rows="3"><?= htmlspecialchars($createProfileValues['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          </label>

          <?php if (isset($errors['profile']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['profile']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <button type="submit" class="btn">Dodaj profil</button>
        </form>
      </div>

      <div class="pc-builder__form-card">
        <h2>Nowa budowa PC</h2>
        <form method="post" class="form-grid">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
          <input type="hidden" name="action" value="create-build">

          <label>
            Karta serwisowa
            <select name="case_id" required data-case-select>
              <option value="">-- wybierz --</option>
              <?php foreach ($caseOptions as $case): ?>
                <?php $selected = (string) $case['id'] === $createBuildValues['case_id'] ? 'selected' : ''; ?>
                <?php
                    $caseReference = (string) ($case['reference_code'] ?? '');
                    $caseCustomer = (string) ($case['full_name'] ?? '');
                    $caseSummary = trim((string) ($case['summary'] ?? ''));
                    $autoSummary = trim(
                        ($caseCustomer !== '' ? 'Budowa PC dla ' . $caseCustomer : 'Budowa PC')
                        . ($caseReference !== '' ? ' (' . $caseReference . ')' : '')
                        . ($caseSummary !== '' ? ' – ' . $caseSummary : '')
                    );
                ?>
                <option
                  value="<?= (int) $case['id'] ?>"
                  <?= $selected ?>
                  data-case-reference="<?= htmlspecialchars($caseReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-case-customer="<?= htmlspecialchars($caseCustomer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-case-description="<?= htmlspecialchars($caseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                  data-auto-summary="<?= htmlspecialchars($autoSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                >
                  <?= htmlspecialchars(($caseReference !== '' ? $caseReference . ' — ' : '') . $caseCustomer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (isset($errors['build']['case_id'])): ?><p class="form-error"><?= htmlspecialchars($errors['build']['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <label>
            Profil obudowy (opcjonalnie)
            <select name="case_profile_id">
              <option value="">-- brak --</option>
              <?php foreach ($caseProfileOptions as $profile): ?>
                <?php $selected = (string) $profile['id'] === $createBuildValues['case_profile_id'] ? 'selected' : ''; ?>
                <option value="<?= (int) $profile['id'] ?>" <?= $selected ?>>
                  <?= htmlspecialchars(($profile['manufacturer'] ?? '') . ' ' . ($profile['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>

          <label>
            Status
            <select name="status" required>
              <?php foreach ($buildStatusLabels as $statusKey => $statusLabel): ?>
                <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $createBuildValues['status'] === $statusKey ? 'selected' : '' ?>>
                  <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php if (isset($errors['build']['status'])): ?><p class="form-error"><?= htmlspecialchars($errors['build']['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <label>
            Podsumowanie
            <textarea name="summary" rows="3" data-build-summary data-autofill="case"><?= htmlspecialchars($createBuildValues['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            <small class="form-hint">Wybór karty automatycznie uzupełni podsumowanie i pomoże w generowaniu kodów.</small>
          </label>

          <?php if (isset($errors['build']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['build']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

          <button type="submit" class="btn">Utwórz budowę</button>
        </form>
      </div>
    </section>

    <section class="pc-builder__lookup" aria-label="Pozostałości według profilu">
      <h2>Dostępne pozostałości dla profilu</h2>
      <form method="get" class="pc-builder__profile-filter">
        <label>
          Wybierz profil sprzętowy
          <select name="profile">
            <option value="">-- wybierz --</option>
            <?php foreach ($profileOptions as $profile): ?>
              <?php $selected = $profileFilterId !== null && (int) $profile['id'] === $profileFilterId ? 'selected' : ''; ?>
              <option value="<?= (int) $profile['id'] ?>" <?= $selected ?>>
                <?= htmlspecialchars(($profile['manufacturer'] ?? '') . ' ' . ($profile['model'] ?? '') . ' (' . ($profile['type'] ?? '') . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <button type="submit" class="btn">Pokaż pozostałości</button>
      </form>

      <?php if ($filteredProfile !== null): ?>
        <div class="pc-builder__profile-summary">
          <h3><?= htmlspecialchars(($filteredProfile['manufacturer'] ?? '') . ' ' . ($filteredProfile['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
          <p>Typ: <?= htmlspecialchars($typeLabels[$filteredProfile['type']] ?? $filteredProfile['type'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
        </div>

        <?php if ($filteredLeftovers === []): ?>
          <p>Brak zarejestrowanych pozostałości powiązanych z tym profilem.</p>
        <?php else: ?>
          <div class="pc-builder__leftover-list">
            <?php foreach ($filteredLeftovers as $item): ?>
              <article class="pc-builder__leftover">
                <header>
                  <h4><?= htmlspecialchars($item['name'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h4>
                  <p>Kod: <?= htmlspecialchars($item['reference_code'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php if (($item['barcode'] ?? '') !== ''): ?>
                    <p>EAN: <?= htmlspecialchars((string) $item['barcode'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                  <?php endif; ?>
                </header>
                <dl>
                  <div>
                    <dt>Ilość</dt>
                    <dd><?= (int) ($item['quantity'] ?? 0) ?></dd>
                  </div>
                  <div>
                    <dt>Status</dt>
                    <dd><?= htmlspecialchars($item['status'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                  <div>
                    <dt>Magazyn</dt>
                    <dd><?= htmlspecialchars($item['location'] ?? 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                </dl>
                <?php if (($item['notes'] ?? '') !== ''): ?>
                  <p><?= nl2br(htmlspecialchars((string) $item['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </section>

    <section class="pc-builder__builds" aria-label="Lista budów">
      <h2>Ostatnie budowy</h2>
      <div class="pc-builder__build-tools">
        <div class="pc-builder__search">
          <label>
            <span>Wyszukaj budowę</span>
            <input type="search" name="build_search" placeholder="Kod, klient, status..." data-build-search>
          </label>
        </div>
        <div class="pc-builder__status-filter" role="group" aria-label="Filtr statusu">
          <?php foreach ($buildStatusLabels as $statusKey => $statusLabel): ?>
            <button
              type="button"
              class="pc-builder__status-button"
              data-filter-status="<?= htmlspecialchars($statusKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
              data-active="false"
            >
              <span><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              <small><?= (int) ($buildStatusCounts[$statusKey] ?? 0) ?></small>
            </button>
          <?php endforeach; ?>
        </div>
      </div>
      <p class="pc-builder__build-counter">Widoczne budowy: <strong data-build-count><?= $buildCount ?></strong> / <?= $buildCount ?></p>
      <?php if ($builds === []): ?>
        <p>Brak zleceń budowy PC. Dodaj nowe, aby rozpocząć śledzenie komponentów.</p>
      <?php endif; ?>

      <?php foreach ($builds as $build): ?>
        <?php
            $buildId = (int) ($build['id'] ?? 0);
            $details = $buildDetails[$buildId] ?? null;
            $buildReference = (string) ($build['reference_code'] ?? '');
            $buildStatus = (string) ($build['status'] ?? '');
            $buildCustomer = (string) ($build['customer_name'] ?? '');
            $buildCaseReference = (string) ($build['case_reference_code'] ?? '');
            $buildSummaryText = trim((string) ($build['summary'] ?? ''));
            $buildCaseSummary = trim((string) ($build['case_summary'] ?? ''));
            $searchTokens = strtolower(trim(implode(' ', array_filter([
                $buildReference,
                $buildCustomer,
                $buildCaseReference,
                $buildSummaryText,
                $buildCaseSummary,
            ]))));
            $highlightAttribute = $highlightBuildId === $buildId ? ' data-highlight="true"' : '';
        ?>
        <article
          class="pc-builder__build"
          id="build-<?= $buildId ?>"
          data-build-item="true"
          data-status="<?= htmlspecialchars($buildStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-search="<?= htmlspecialchars($searchTokens, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-build-reference="<?= htmlspecialchars($buildReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          data-build-customer="<?= htmlspecialchars($buildCustomer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
          <?= $highlightAttribute ?>
        >
          <header class="pc-builder__build-header">
            <div class="pc-builder__build-main">
              <h3><?= htmlspecialchars($buildReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
              <p>Klient: <?= htmlspecialchars($buildCustomer !== '' ? $buildCustomer : 'brak danych', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <p>Case: <?= htmlspecialchars($buildCaseReference !== '' ? $buildCaseReference : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php if ($buildSummaryText !== ''): ?>
                <p>Opis: <?= htmlspecialchars($buildSummaryText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php elseif ($buildCaseSummary !== ''): ?>
                <p>Opis sprawy: <?= htmlspecialchars($buildCaseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>
            </div>
            <div class="pc-builder__build-meta">
              <p>Profil obudowy: <?= htmlspecialchars((($build['profile_manufacturer'] ?? '') . ' ' . ($build['profile_model'] ?? '')) ?: 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <p>Zaktualizowano: <?= htmlspecialchars($build['updated_at'] ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
            </div>
            <div class="pc-builder__build-actions">
              <span class="pc-builder__status-badge" data-status="<?= htmlspecialchars($buildStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars($buildStatusLabels[$buildStatus] ?? $buildStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
              <?php if ($buildReference !== ''): ?>
                <button type="button" class="btn btn--ghost btn--small" data-copy-reference="<?= htmlspecialchars($buildReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                  Kopiuj kod
                </button>
              <?php endif; ?>
            </div>
          </header>

          <div class="pc-builder__build-body">
            <section>
              <h4>Dodaj komponent</h4>
              <form method="post" class="form-inline" data-component-form>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add-component">
                <input type="hidden" name="build_id" value="<?= $buildId ?>">

                <label>
                  Pozycja magazynowa
                  <select name="item_id" required>
                    <option value="">-- wybierz --</option>
                    <?php foreach ($itemOptions as $item): ?>
                      <?php
                          $itemName = (string) ($item['name'] ?? '');
                          $itemBarcode = (string) ($item['barcode'] ?? '');
                          $itemStatus = (string) ($item['status'] ?? '');
                          $optionLabel = $itemName;
                          if ($itemBarcode !== '') {
                              $optionLabel .= ' [' . $itemBarcode . ']';
                          }
                          if ($itemStatus !== '') {
                              $optionLabel .= ' — ' . $itemStatus;
                          }
                      ?>
                      <option
                        value="<?= (int) $item['id'] ?>"
                        data-item-name="<?= htmlspecialchars($itemName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-item-barcode="<?= htmlspecialchars($itemBarcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                        data-item-status="<?= htmlspecialchars($itemStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"
                      >
                        <?= htmlspecialchars($optionLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label>
                  Ilość
                  <input type="number" name="quantity" value="1" min="1" step="1" required>
                </label>

                <label>
                  Notatki
                  <input type="text" name="notes" placeholder="np. montaż">
                </label>

                <button type="submit" class="btn">Dodaj</button>
              </form>
              <?php if ($highlightBuildId === $buildId && isset($errors['component']['general'])): ?>
                <p class="form-error"><?= htmlspecialchars($errors['component']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>

              <div class="pc-builder__component-list">
                <h5>Wykorzystane komponenty</h5>
                <?php if ($details !== null && ($details['components'] ?? []) !== []): ?>
                  <ul>
                    <?php foreach ($details['components'] as $component): ?>
                        <?php
                          $componentReference = (string) ($component['item_reference'] ?? '');
                          $componentBarcode = (string) ($component['item_barcode'] ?? '');
                      ?>
                      <li>
                        <?= htmlspecialchars(($component['item_name'] ?? '') . ' × ' . (string) ($component['quantity'] ?? 1), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php if ($componentReference !== '' || $componentBarcode !== ''): ?>
                          <small class="pc-builder__code">
                            <?php if ($componentReference !== ''): ?>Kod: <?= htmlspecialchars($componentReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                            <?php if ($componentReference !== '' && $componentBarcode !== ''): ?> • <?php endif; ?>
                            <?php if ($componentBarcode !== ''): ?>EAN: <?= htmlspecialchars($componentBarcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                          </small>
                        <?php endif; ?>
                        <?php if (($component['notes'] ?? '') !== ''): ?>
                          <small><?= htmlspecialchars($component['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p>Brak zarejestrowanych komponentów.</p>
                <?php endif; ?>
              </div>
            </section>

            <section>
              <h4>Dodaj pozostałość</h4>
              <form method="post" class="form-grid" data-leftover-form data-build-reference="<?= htmlspecialchars($buildReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <input type="hidden" name="action" value="add-leftover">
                <input type="hidden" name="build_id" value="<?= $buildId ?>">

                <label>
                  Nazwa pozostałości
                  <input type="text" name="name" required>
                </label>

                <label>
                  Ilość
                  <input type="number" name="quantity" value="1" min="0" step="1" required>
                </label>

                <label>
                  Kategoria
                  <input type="text" name="category" value="leftover">
                </label>

                <label>
                  Lokalizacja
                  <input type="text" name="location" value="Magazyn PC" data-location-autofill>
                </label>

                <label>
                  Kod referencyjny (opcjonalnie)
                  <div class="pc-builder__field-with-action">
                    <input type="text" name="reference_code" placeholder="np. WH-ŚRUBKI">
                    <button type="button" class="btn btn--ghost btn--small" data-generate-reference>Generuj</button>
                  </div>
                </label>

                <label>
                  Profil kompatybilności
                  <select name="profile_id">
                    <option value="">-- brak --</option>
                    <?php foreach ($profileOptions as $profile): ?>
                      <?php $selected = $build['case_profile_id'] && (int) $profile['id'] === (int) $build['case_profile_id'] ? 'selected' : ''; ?>
                      <option value="<?= (int) $profile['id'] ?>" <?= $selected ?>>
                        <?= htmlspecialchars(($profile['manufacturer'] ?? '') . ' ' . ($profile['model'] ?? '') . ' (' . ($profile['type'] ?? '') . ')', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </label>

                <label>
                  Notatki
                  <textarea name="notes" rows="3"></textarea>
                </label>

                <button type="submit" class="btn">Zapisz pozostałość</button>
              </form>
              <?php if ($highlightBuildId === $buildId && isset($errors['leftover']['general'])): ?>
                <p class="form-error"><?= htmlspecialchars($errors['leftover']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
              <?php endif; ?>

              <div class="pc-builder__leftover-history">
                <h5>Zarejestrowane pozostałości</h5>
                <?php if ($details !== null && ($details['leftovers'] ?? []) !== []): ?>
                  <ul>
                    <?php foreach ($details['leftovers'] as $leftover): ?>
                        <?php
                          $leftoverReference = (string) ($leftover['item_reference'] ?? '');
                          $leftoverBarcode = (string) ($leftover['item_barcode'] ?? '');
                      ?>
                      <li>
                        <?= htmlspecialchars(($leftover['item_name'] ?? '') . ' × ' . (string) ($leftover['quantity'] ?? 0), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <?php if ($leftoverReference !== '' || $leftoverBarcode !== ''): ?>
                          <small class="pc-builder__code">
                            <?php if ($leftoverReference !== ''): ?>Kod: <?= htmlspecialchars($leftoverReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                            <?php if ($leftoverReference !== '' && $leftoverBarcode !== ''): ?> • <?php endif; ?>
                            <?php if ($leftoverBarcode !== ''): ?>EAN: <?= htmlspecialchars($leftoverBarcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><?php endif; ?>
                          </small>

                        <?php endif; ?>
                        <?php if (($leftover['notes'] ?? '') !== ''): ?>
                          <small><?= htmlspecialchars((string) $leftover['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                        <?php if (($leftover['profile_manufacturer'] ?? '') !== ''): ?>
                          <small>Profil: <?= htmlspecialchars(($leftover['profile_manufacturer'] ?? '') . ' ' . ($leftover['profile_model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></small>
                        <?php endif; ?>
                      </li>
                    <?php endforeach; ?>
                  </ul>
                <?php else: ?>
                  <p>Brak zarejestrowanych pozostałości.</p>
                <?php endif; ?>
              </div>
            </section>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </main>
</body>
</html>