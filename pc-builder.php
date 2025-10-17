<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\PcBuildRepository;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/generate-pc-build-plan.php';
require_once __DIR__ . '/generate-pc-build-release.php';

$pcBuildRepository = new PcBuildRepository($pdo);
$warehouseRepository = new WarehouseRepository($pdo);
$customerRepository = new CustomerRepository($pdo);
$notificationService = new NotificationService($pdo);

$caseOptions = $warehouseRepository->caseOptions(200);
$customerOptions = $customerRepository->listCustomers(null, 200);
$warehouseItems = $warehouseRepository->listItems(null, null, 200);
$buildStatusLabels = $pcBuildRepository->statusLabels();

$componentCategories = [
    'case' => 'Obudowa',
    'motherboard' => 'Płyta główna',
    'cpu' => 'Procesor',
    'memory' => 'Pamięć RAM',
    'storage' => 'Nośniki danych',
    'gpu' => 'Karta graficzna',
    'psu' => 'Zasilacz',
    'cooling' => 'Chłodzenie',
    'extras' => 'Dodatki i akcesoria',
];

$assemblyTasks = [
    'case_preparation' => 'Przygotowanie obudowy i montaż dystansów',
    'install_motherboard' => 'Instalacja płyty głównej',
    'install_cpu' => 'Instalacja CPU i pasty termoprzewodzącej',
    'install_memory' => 'Montaż modułów RAM',
    'install_storage' => 'Montowanie dysków / modułów M.2',
    'install_gpu' => 'Instalacja karty graficznej',
    'cable_management' => 'Zarządzanie okablowaniem',
    'power_on_test' => 'Test POST / uruchomienia',
    'os_install' => 'Konfiguracja BIOS / instalacja systemu',
];

$deliveryMethods = [
    'pickup' => 'Odbiór w salonie',
    'courier' => 'Wysyłka kurierem',
    'local_delivery' => 'Dowóz lokalny',
];

$errors = [
    'create' => [],
    'information' => [],
    'planning' => [],
    'assembly' => [],
    'release' => [],
];

$successMessage = null;
if (isset($_SESSION['pc_builder_success'])) {
    $successMessage = (string) $_SESSION['pc_builder_success'];
    unset($_SESSION['pc_builder_success']);
}

$createFormData = [
    'case_id' => '',
    'customer_id' => '',
    'summary' => '',
];

$informationFormData = [
    'case_id' => '',
    'customer_id' => '',
    'summary' => '',
    'assigned_employee' => Auth::username(),
];

$planningFormData = [
    'currency' => 'PLN',
    'notes' => '',
    'components' => [],
];

$assemblyFormData = [
    'tasks_completed' => [],
    'small_parts' => '',
    'notes' => '',
];

$releaseFormData = [
    'release_date' => date('Y-m-d'),
    'delivery_method' => 'pickup',
    'delivery_details' => '',
    'notes' => '',
    'signature_path' => '',
];

$selectedBuildId = filter_input(INPUT_GET, 'build_id', FILTER_VALIDATE_INT);
if ($selectedBuildId === false) {
    $selectedBuildId = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
        $errors['create']['general'] = 'Sesja wygasła. Odśwież stronę i spróbuj ponownie.';
        $errors['information']['general'] = $errors['create']['general'];
        $errors['planning']['general'] = $errors['create']['general'];
        $errors['assembly']['general'] = $errors['create']['general'];
        $errors['release']['general'] = $errors['create']['general'];
    } else {
        switch ($action) {
            case 'create-build':
                $createFormData['case_id'] = trim((string) ($_POST['case_id'] ?? ''));
                $createFormData['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
                $createFormData['summary'] = trim((string) ($_POST['summary'] ?? ''));

                $caseId = filter_var($createFormData['case_id'], FILTER_VALIDATE_INT);
                $customerId = filter_var($createFormData['customer_id'], FILTER_VALIDATE_INT);

                if ($caseId === false || $caseId <= 0) {
                    $errors['create']['case_id'] = 'Wybierz kartę serwisową powiązaną z budową.';
                }
                if ($customerId === false || $customerId <= 0) {
                    $errors['create']['customer_id'] = 'Wybierz klienta.';
                }

                if ($errors['create'] === []) {
                    try {
                        $summary = $createFormData['summary'] !== '' ? $createFormData['summary'] : null;
                        $assigned = Auth::username();
                        $build = $pcBuildRepository->createBuild(
                            (int) $caseId,
                            null,
                            'draft',
                            $summary,
                            $assigned,
                            $customerId !== false ? (int) $customerId : null,
                            $assigned,
                            'information'
                        );
                        $buildId = (int) ($build['id'] ?? 0);
                        if ($buildId <= 0) {
                            throw new RuntimeException('Nie udało się utworzyć budowy.');
                        }
                        $pcBuildRepository->updateInformation(
                            $buildId,
                            (int) $caseId,
                            null,
                            $customerId !== false ? (int) $customerId : null,
                            $summary,
                            $assigned,
                            $assigned
                        );
                        $_SESSION['pc_builder_success'] = 'Utworzono nową budowę PC.';
                        Response::redirect('pc-builder.php?build_id=' . $buildId);
                    } catch (Throwable $exception) {
                        $errors['create']['general'] = 'Nie udało się utworzyć budowy PC.';
                    }
                }
                break;

            case 'save-information':
                $selectedBuildId = filter_var($_POST['build_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $informationFormData['case_id'] = trim((string) ($_POST['case_id'] ?? ''));
                $informationFormData['customer_id'] = trim((string) ($_POST['customer_id'] ?? ''));
                $informationFormData['summary'] = trim((string) ($_POST['summary'] ?? ''));
                $informationFormData['assigned_employee'] = trim((string) ($_POST['assigned_employee'] ?? Auth::username()));

                $caseId = filter_var($informationFormData['case_id'], FILTER_VALIDATE_INT);
                $customerId = $informationFormData['customer_id'] !== ''
                    ? filter_var($informationFormData['customer_id'], FILTER_VALIDATE_INT)
                    : null;

                if ($selectedBuildId === null || $selectedBuildId <= 0) {
                    $errors['information']['general'] = 'Nie wybrano budowy do aktualizacji.';
                }
                if ($caseId === false || $caseId <= 0) {
                    $errors['information']['case_id'] = 'Wybierz prawidłową kartę serwisową.';
                }
                if ($customerId === false || ($customerId !== null && $customerId <= 0)) {
                    $errors['information']['customer_id'] = 'Wybierz klienta powiązanego z budową.';
                }

                if ($errors['information'] === []) {
                    try {
                        $pcBuildRepository->updateInformation(
                            (int) $selectedBuildId,
                            (int) $caseId,
                            null,
                            $customerId !== null ? (int) $customerId : null,
                            $informationFormData['summary'] !== '' ? $informationFormData['summary'] : null,
                            $informationFormData['assigned_employee'] !== '' ? $informationFormData['assigned_employee'] : null,
                            Auth::username()
                        );
                        $_SESSION['pc_builder_success'] = 'Zapisano dane podstawowe budowy.';
                        Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                    } catch (Throwable $exception) {
                        $errors['information']['general'] = 'Nie udało się zaktualizować danych budowy.';
                    }
                }
                break;

            case 'save-planning':
                $selectedBuildId = filter_var($_POST['build_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $planningFormData['currency'] = strtoupper(trim((string) ($_POST['currency'] ?? 'PLN')));
                if ($planningFormData['currency'] === '') {
                    $planningFormData['currency'] = 'PLN';
                }
                $planningFormData['notes'] = trim((string) ($_POST['notes'] ?? ''));
                $rawComponents = $_POST['components'] ?? [];
                $componentsPayload = [];
                $totalCostCents = 0;

                if ($selectedBuildId === null || $selectedBuildId <= 0) {
                    $errors['planning']['general'] = 'Nie wybrano budowy do aktualizacji planu.';
                }

                foreach ($componentCategories as $categoryKey => $categoryLabel) {
                    $componentInput = is_array($rawComponents[$categoryKey] ?? null) ? $rawComponents[$categoryKey] : [];
                    $itemIdRaw = $componentInput['item_id'] ?? '';
                    $itemId = $itemIdRaw !== '' ? filter_var($itemIdRaw, FILTER_VALIDATE_INT) : null;
                    $quantity = isset($componentInput['quantity']) ? (int) $componentInput['quantity'] : 0;
                    $quantity = $quantity > 0 ? $quantity : 0;
                    $notes = trim((string) ($componentInput['notes'] ?? ''));
                    $customLabel = trim((string) ($componentInput['custom_name'] ?? ''));
                    $manualPrice = trim((string) ($componentInput['unit_price'] ?? ''));

                    if (($itemId === null || $itemId === false) && $customLabel === '' && $notes === '') {
                        continue;
                    }

                    $unitPriceCents = 0;
                    $itemName = $customLabel;
                    $itemReference = null;

                    if ($itemId !== null && $itemId !== false) {
                        try {
                            $item = $warehouseRepository->findItem((int) $itemId);
                        } catch (Throwable $exception) {
                            $item = null;
                        }
                        if ($item === null) {
                            $errors['planning']['components'] = 'Nie znaleziono jednego z wybranych komponentów.';
                            break;
                        }
                        $itemName = (string) ($item['name'] ?? $itemName);
                        $itemReference = (string) ($item['reference_code'] ?? null);
                        $unitPriceCents = (int) ($item['unit_price_cents'] ?? 0);
                    }

                    if ($manualPrice !== '') {
                        $normalized = str_replace([' ', ','], ['', '.'], $manualPrice);
                        if (is_numeric($normalized)) {
                            $unitPriceCents = (int) round(((float) $normalized) * 100);
                        }
                    }

                    $componentTotal = $unitPriceCents * max(1, $quantity === 0 ? 1 : $quantity);
                    $totalCostCents += $componentTotal;

                    $componentsPayload[] = [
                        'category' => $categoryKey,
                        'label' => $itemName,
                        'item_id' => $itemId !== null && $itemId !== false ? (int) $itemId : null,
                        'item_reference' => $itemReference,
                        'quantity' => max(1, $quantity === 0 ? 1 : $quantity),
                        'unit_price_cents' => max(0, $unitPriceCents),
                        'total_price_cents' => max(0, $componentTotal),
                        'notes' => $notes,
                    ];
                }

                if ($errors['planning'] === [] && $selectedBuildId !== null && $selectedBuildId > 0) {
                    try {
                        $planPdfRelative = 'storage/documents/pc-builds/plan-' . (int) $selectedBuildId . '.pdf';
                        $planningPayload = [
                            'components' => $componentsPayload,
                            'notes' => $planningFormData['notes'],
                            'plan_pdf_path' => $planPdfRelative,
                            'generated_at' => date('c'),
                        ];
                        $pcBuildRepository->savePlanningData(
                            (int) $selectedBuildId,
                            $planningPayload,
                            max(0, $totalCostCents),
                            $planningFormData['currency'],
                            Auth::username()
                        );
                        $planPdfPath = __DIR__ . '/' . $planPdfRelative;
                        generatePcBuildPlanPdf($pdo, (int) $selectedBuildId, $planPdfPath);
                        $_SESSION['pc_builder_success'] = 'Zapisano plan komponentów i wygenerowano kosztorys PDF.';
                        Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                    } catch (Throwable $exception) {
                        $errors['planning']['general'] = 'Nie udało się zapisać danych planowania.';
                    }
                }
                break;

            case 'save-assembly':
                $selectedBuildId = filter_var($_POST['build_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $assemblyFormData['small_parts'] = trim((string) ($_POST['small_parts'] ?? ''));
                $assemblyFormData['notes'] = trim((string) ($_POST['notes'] ?? ''));
                $assemblyFormData['tasks_completed'] = array_filter(
                    is_array($_POST['tasks_completed'] ?? null) ? $_POST['tasks_completed'] : [],
                    static fn ($value): bool => is_string($value)
                );

                if ($selectedBuildId === null || $selectedBuildId <= 0) {
                    $errors['assembly']['general'] = 'Nie wybrano budowy do zapisania checklisty.';
                }

                if ($errors['assembly'] === []) {
                    try {
                        $assemblyPayload = [
                            'tasks_completed' => array_values(array_unique($assemblyFormData['tasks_completed'])),
                            'small_parts' => $assemblyFormData['small_parts'],
                            'notes' => $assemblyFormData['notes'],
                            'updated_at' => date('c'),
                        ];
                        $pcBuildRepository->saveAssemblyData(
                            (int) $selectedBuildId,
                            $assemblyPayload,
                            Auth::username()
                        );
                        $_SESSION['pc_builder_success'] = 'Zapisano checklistę montażu.';
                        Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                    } catch (Throwable $exception) {
                        $errors['assembly']['general'] = 'Nie udało się zapisać checklisty montażu.';
                    }
                }
                break;

            case 'save-release':
                $selectedBuildId = filter_var($_POST['build_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
                $releaseFormData['release_date'] = trim((string) ($_POST['release_date'] ?? date('Y-m-d')));
                $releaseFormData['delivery_method'] = trim((string) ($_POST['delivery_method'] ?? 'pickup'));
                $releaseFormData['delivery_details'] = trim((string) ($_POST['delivery_details'] ?? ''));
                $releaseFormData['notes'] = trim((string) ($_POST['notes'] ?? ''));

                if ($selectedBuildId === null || $selectedBuildId <= 0) {
                    $errors['release']['general'] = 'Nie wybrano budowy do zakończenia.';
                }

                $releaseDate = DateTimeImmutable::createFromFormat('Y-m-d', $releaseFormData['release_date']);
                if ($releaseDate === false) {
                    $errors['release']['release_date'] = 'Podaj prawidłową datę wydania (RRRR-MM-DD).';
                }

                if (!isset($deliveryMethods[$releaseFormData['delivery_method']])) {
                    $errors['release']['delivery_method'] = 'Wybierz prawidłowy sposób dostarczenia.';
                }

                $signatureRelativePath = $releaseFormData['signature_path'];
                if (isset($_FILES['signature_file']) && is_array($_FILES['signature_file'])) {
                    $file = $_FILES['signature_file'];
                    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && ($file['tmp_name'] ?? '') !== '') {
                        $tmpName = (string) $file['tmp_name'];
                        $mime = mime_content_type($tmpName) ?: '';
                        $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg'];
                        if (!isset($allowed[$mime])) {
                            $errors['release']['signature_file'] = 'Podpis musi być obrazem PNG lub JPG.';
                        } else {
                            $extension = $allowed[$mime];
                            $signatureDirectory = __DIR__ . '/storage/documents/pc-builds/signatures';
                            if (!is_dir($signatureDirectory)) {
                                mkdir($signatureDirectory, 0775, true);
                            }
                            $signatureFilename = sprintf('build-%d-%s.%s', (int) $selectedBuildId, date('YmdHis'), $extension);
                            $targetPath = $signatureDirectory . '/' . $signatureFilename;
                            if (!move_uploaded_file($tmpName, $targetPath)) {
                                $errors['release']['signature_file'] = 'Nie udało się zapisać pliku z podpisem.';
                            } else {
                                $signatureRelativePath = 'storage/documents/pc-builds/signatures/' . $signatureFilename;
                            }
                        }
                    }
                }

                if ($errors['release'] === []) {
                    try {
                        $releasePdfRelative = 'storage/documents/pc-builds/release-' . (int) $selectedBuildId . '.pdf';
                        $releasePayload = [
                            'release_date' => $releaseFormData['release_date'],
                            'delivery_method_code' => $releaseFormData['delivery_method'],
                            'delivery_method' => $deliveryMethods[$releaseFormData['delivery_method']] ?? $releaseFormData['delivery_method'],
                            'delivery_details' => $releaseFormData['delivery_details'],
                            'notes' => $releaseFormData['notes'],
                            'signature_path' => $signatureRelativePath,
                            'release_pdf_path' => $releasePdfRelative,
                            'completed_at' => date('c'),
                        ];

                        $pcBuildRepository->saveReleaseData(
                            (int) $selectedBuildId,
                            $releasePayload,
                            Auth::username()
                        );

                        $releasePdfPath = __DIR__ . '/' . $releasePdfRelative;
                        generatePcBuildReleasePdf($pdo, (int) $selectedBuildId, $releasePdfPath);

                        $updatedBuild = $pcBuildRepository->findBuild((int) $selectedBuildId);
                        $recipient = isset($updatedBuild['customer_email']) ? (string) $updatedBuild['customer_email'] : '';
                        if ($recipient !== '') {
                            $notificationService->sendPcBuildRelease(
                                isset($updatedBuild['case_id']) ? (int) $updatedBuild['case_id'] : null,
                                isset($updatedBuild['customer_id']) ? (int) $updatedBuild['customer_id'] : null,
                                $recipient,
                                [
                                    'customer_name' => (string) ($updatedBuild['customer_name'] ?? 'klient'),
                                    'build_reference' => (string) ($updatedBuild['reference_code'] ?? ''),
                                    'delivery_method' => $releasePayload['delivery_method'],
                                    'release_date' => $releasePayload['release_date'],
                                    'subject' => 'Potwierdzenie wydania zestawu PC ' . (string) ($updatedBuild['reference_code'] ?? ''),
                                ]
                            );
                        }

                        $_SESSION['pc_builder_success'] = 'Budowa została zatwierdzona i wygenerowano protokół wydania.';
                        Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                    } catch (Throwable $exception) {
                        $errors['release']['general'] = 'Nie udało się zapisać danych wydania.';
                    }
                }
                break;

            default:
                $errors['create']['general'] = 'Nieznana akcja formularza.';
        }
    }
}

try {
    $builds = $pcBuildRepository->listBuilds(null, 200);
} catch (Throwable $exception) {
    $builds = [];
}

if ($selectedBuildId === null && $builds !== []) {
    $firstBuildId = (int) ($builds[0]['id'] ?? 0);
    if ($firstBuildId > 0) {
        $selectedBuildId = $firstBuildId;
    }
}

$selectedDetails = null;
$selectedBuild = null;
$planningPayload = [];
$assemblyPayload = [];
$releasePayload = [];
if ($selectedBuildId !== null && $selectedBuildId > 0) {
    try {
        $selectedDetails = $pcBuildRepository->buildDetails((int) $selectedBuildId);
        $selectedBuild = $selectedDetails['build'] ?? null;
        $planningPayload = is_array($selectedBuild['planning_payload'] ?? null) ? $selectedBuild['planning_payload'] : [];
        $assemblyPayload = is_array($selectedBuild['assembly_payload'] ?? null) ? $selectedBuild['assembly_payload'] : [];
        $releasePayload = is_array($selectedBuild['release_payload'] ?? null) ? $selectedBuild['release_payload'] : [];
    } catch (Throwable $exception) {
        $selectedDetails = null;
        $selectedBuild = null;
    }
}

$caseOptionsById = [];
foreach ($caseOptions as $case) {
    $caseOptionsById[(int) ($case['id'] ?? 0)] = $case;
}

$customerOptionsById = [];
foreach ($customerOptions as $customer) {
    $customerOptionsById[(int) ($customer['id'] ?? 0)] = $customer;
}

$itemOptionsById = [];
foreach ($warehouseItems as $item) {
    $itemOptionsById[(int) ($item['id'] ?? 0)] = $item;
}

if ($selectedBuild !== null) {
    $caseId = (int) ($selectedBuild['case_id'] ?? 0);
    if ($caseId > 0 && !isset($caseOptionsById[$caseId])) {
        $caseOptionsById[$caseId] = [
            'id' => $caseId,
            'reference_code' => $selectedBuild['case_reference_code'] ?? ('CASE #' . $caseId),
            'summary' => $selectedBuild['case_summary'] ?? '',
            'full_name' => $selectedBuild['customer_name'] ?? '',
        ];
        $caseOptions[] = $caseOptionsById[$caseId];
    }

    $customerId = isset($selectedBuild['customer_id']) ? (int) $selectedBuild['customer_id'] : 0;
    if ($customerId > 0 && !isset($customerOptionsById[$customerId])) {
        $customerOptionsById[$customerId] = [
            'id' => $customerId,
            'full_name' => $selectedBuild['customer_name'] ?? ('Klient #' . $customerId),
            'email' => $selectedBuild['customer_email'] ?? null,
            'phone' => $selectedBuild['customer_phone'] ?? null,
        ];
        $customerOptions[] = $customerOptionsById[$customerId];
    }
}

if ($selectedBuild !== null && $errors['information'] === []) {
    $informationFormData['case_id'] = (string) ((int) ($selectedBuild['case_id'] ?? 0));
    $informationFormData['customer_id'] = (string) ((int) ($selectedBuild['customer_id'] ?? 0));
    $informationFormData['summary'] = (string) ($selectedBuild['summary'] ?? '');
    $informationFormData['assigned_employee'] = (string) ($selectedBuild['assigned_employee'] ?? Auth::username());
}

if ($planningPayload !== [] && $errors['planning'] === []) {
    $planningFormData['notes'] = (string) ($planningPayload['notes'] ?? '');
    $componentsByCategory = [];
    foreach (is_array($planningPayload['components'] ?? null) ? $planningPayload['components'] : [] as $component) {
        $category = (string) ($component['category'] ?? '');
        $componentsByCategory[$category] = $component;
    }
    foreach ($componentCategories as $categoryKey => $categoryLabel) {
        $component = $componentsByCategory[$categoryKey] ?? [];
        $planningFormData['components'][$categoryKey] = [
            'item_id' => isset($component['item_id']) ? (string) $component['item_id'] : '',
            'label' => (string) ($component['label'] ?? ''),
            'quantity' => (int) ($component['quantity'] ?? 1),
            'notes' => (string) ($component['notes'] ?? ''),
            'unit_price_cents' => (int) ($component['unit_price_cents'] ?? 0),
        ];
    }
}

if ($assemblyPayload !== [] && $errors['assembly'] === []) {
    $assemblyFormData['tasks_completed'] = is_array($assemblyPayload['tasks_completed'] ?? null)
        ? array_map('strval', $assemblyPayload['tasks_completed'])
        : [];
    $assemblyFormData['small_parts'] = (string) ($assemblyPayload['small_parts'] ?? '');
    $assemblyFormData['notes'] = (string) ($assemblyPayload['notes'] ?? '');
}

if ($releasePayload !== [] && $errors['release'] === []) {
    $releaseFormData['release_date'] = (string) ($releasePayload['release_date'] ?? date('Y-m-d'));
    $releaseFormData['delivery_method'] = (string) ($releasePayload['delivery_method_code'] ?? 'pickup');
    $releaseFormData['delivery_details'] = (string) ($releasePayload['delivery_details'] ?? '');
    $releaseFormData['notes'] = (string) ($releasePayload['notes'] ?? '');
    $releaseFormData['signature_path'] = (string) ($releasePayload['signature_path'] ?? '');
}

$csrfToken = Csrf::token();

?>
<!DOCTYPE html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Kreator budowy PC - Digivriend</title>
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/pc-builder.css">
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

  <main class="container pc-builder-wizard">
    <div class="pc-builder-wizard__layout">
      <aside class="pc-builder-wizard__sidebar" aria-label="Lista budów">
        <h2>Budowy PC</h2>
        <?php if ($builds === []): ?>
          <p>Brak zarejestrowanych budów.</p>
        <?php else: ?>
          <ul class="pc-builder-wizard__build-list">
            <?php foreach ($builds as $build): ?>
              <?php
                $buildId = (int) ($build['id'] ?? 0);
                if ($buildId <= 0) {
                    continue;
                }
                $reference = (string) ($build['reference_code'] ?? '');
                $status = (string) ($build['status'] ?? 'draft');
                $statusLabel = $buildStatusLabels[$status] ?? $status;
                $customer = (string) ($build['customer_name'] ?? '');
              ?>
              <li>
                <a class="pc-builder-wizard__build-link<?= $selectedBuildId === $buildId ? ' pc-builder-wizard__build-link--active' : '' ?>" href="?build_id=<?= $buildId ?>">
                  <span class="pc-builder-wizard__build-ref"><?= htmlspecialchars($reference !== '' ? $reference : 'Build #' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <span class="pc-builder-wizard__build-status" data-status="<?= htmlspecialchars($status, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php if ($customer !== ''): ?>
                    <span class="pc-builder-wizard__build-customer"><?= htmlspecialchars($customer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </aside>

      <section class="pc-builder-wizard__content">
        <header class="pc-builder-wizard__header">
          <div>
            <h1>Kreator budowy PC</h1>
            <p>Przejdź przez cztery kroki od zebrania informacji po wydanie zestawu. Edycja jest blokowana po zatwierdzeniu budowy.</p>
          </div>
          <div class="pc-builder-wizard__actions">
            <a class="btn btn--ghost" href="pc-builds.php">Historia buildów</a>
            <a class="btn btn--ghost" href="magazyn.php">Magazyn</a>
          </div>
        </header>

        <?php if ($successMessage !== null): ?>
          <div class="alert alert--success" role="status"><?= htmlspecialchars($successMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="pc-builder-wizard__card">
          <h2>Rozpocznij nową budowę</h2>
          <form method="post" class="pc-builder-wizard__form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create-build">

            <label>
              Karta serwisowa
              <select name="case_id" required>
                <option value="">-- wybierz --</option>
                <?php foreach ($caseOptions as $case): ?>
                  <?php $optionId = (int) ($case['id'] ?? 0); ?>
                  <option value="<?= $optionId ?>" <?= $createFormData['case_id'] === (string) $optionId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($case['reference_code'] ?? 'Case #' . $optionId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – <?= htmlspecialchars((string) ($case['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if (isset($errors['create']['case_id'])): ?><p class="form-error"><?= htmlspecialchars($errors['create']['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

            <label>
              Klient
              <select name="customer_id" required>
                <option value="">-- wybierz --</option>
                <?php foreach ($customerOptions as $customer): ?>
                  <?php $customerId = (int) ($customer['id'] ?? 0); ?>
                  <option value="<?= $customerId ?>" <?= $createFormData['customer_id'] === (string) $customerId ? 'selected' : '' ?>>
                    <?= htmlspecialchars((string) ($customer['full_name'] ?? 'Klient #' . $customerId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php if (($customer['email'] ?? '') !== ''): ?>
                      (<?= htmlspecialchars((string) $customer['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                    <?php endif; ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </label>
            <?php if (isset($errors['create']['customer_id'])): ?><p class="form-error"><?= htmlspecialchars($errors['create']['customer_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

            <label>
              Krótki opis (opcjonalnie)
              <textarea name="summary" rows="2"><?= htmlspecialchars($createFormData['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </label>

            <?php if (isset($errors['create']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['create']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

            <button type="submit" class="btn">Utwórz budowę</button>
          </form>
        </section>

        <?php if ($selectedBuild === null): ?>
          <p>Wybierz budowę z listy, aby kontynuować pracę w kreatorze.</p>
        <?php else: ?>
          <?php
            $currentStatus = (string) ($selectedBuild['status'] ?? 'draft');
            $currentStep = (string) ($selectedBuild['current_step'] ?? 'information');
            $referenceCode = (string) ($selectedBuild['reference_code'] ?? '');
            $isApproved = $currentStatus === 'approved';
          ?>
          <section class="pc-builder-wizard__card pc-builder-wizard__card--summary">
            <header>
              <h2>Budowa <?= htmlspecialchars($referenceCode !== '' ? $referenceCode : '#' . $selectedBuildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h2>
              <span class="pc-builder__status-badge" data-status="<?= htmlspecialchars($currentStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                <?= htmlspecialchars($buildStatusLabels[$currentStatus] ?? $currentStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
              </span>
            </header>
            <dl>
              <div>
                <dt>Aktualny krok</dt>
                <dd><?= htmlspecialchars(match ($currentStep) {
                    'information' => 'Krok 1: Informacje',
                    'planning' => 'Krok 2: Planowanie',
                    'assembly' => 'Krok 3: Montaż',
                    'release' => 'Krok 4: Wydanie',
                    default => ucfirst($currentStep),
                }, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <div>
                <dt>Klient</dt>
                <dd><?= htmlspecialchars((string) ($selectedBuild['customer_name'] ?? 'Nie przypisano'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <div>
                <dt>Opiekun</dt>
                <dd><?= htmlspecialchars((string) ($selectedBuild['assigned_employee'] ?? 'Nie przypisano'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
              <div>
                <dt>Case</dt>
                <dd><?= htmlspecialchars((string) ($selectedBuild['case_reference_code'] ?? 'Brak'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
              </div>
            </dl>
          </section>

          <section class="pc-builder-wizard__card" id="step-information">
            <h2>Krok 1: Informacje</h2>
            <p>Wybierz klienta oraz kartę serwisową. Pracownik zostanie przypisany automatycznie na podstawie aktualnie zalogowanego konta.</p>
            <form method="post" class="pc-builder-wizard__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save-information">
              <input type="hidden" name="build_id" value="<?= (int) $selectedBuildId ?>">

              <label>
                Karta serwisowa
                <select name="case_id" <?= $isApproved ? 'disabled' : '' ?> required>
                  <option value="">-- wybierz --</option>
                  <?php foreach ($caseOptionsById as $caseId => $case): ?>
                    <option value="<?= $caseId ?>" <?= $informationFormData['case_id'] === (string) $caseId ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) ($case['reference_code'] ?? 'Case #' . $caseId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> – <?= htmlspecialchars((string) ($case['full_name'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php if (isset($errors['information']['case_id'])): ?><p class="form-error"><?= htmlspecialchars($errors['information']['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <label>
                Klient
                <select name="customer_id" <?= $isApproved ? 'disabled' : '' ?> required>
                  <option value="">-- wybierz --</option>
                  <?php foreach ($customerOptionsById as $customerId => $customer): ?>
                    <option value="<?= $customerId ?>" <?= $informationFormData['customer_id'] === (string) $customerId ? 'selected' : '' ?>>
                      <?= htmlspecialchars((string) ($customer['full_name'] ?? 'Klient #' . $customerId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php if (isset($errors['information']['customer_id'])): ?><p class="form-error"><?= htmlspecialchars($errors['information']['customer_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <label>
                Podsumowanie
                <textarea name="summary" rows="3" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($informationFormData['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <label>
                Przypisany pracownik
                <input type="text" name="assigned_employee" value="<?= htmlspecialchars($informationFormData['assigned_employee'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $isApproved ? 'disabled' : '' ?>>
              </label>

              <?php if (isset($errors['information']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['information']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <button type="submit" class="btn" <?= $isApproved ? 'disabled' : '' ?>>Zapisz informacje</button>
            </form>
          </section>

          <section class="pc-builder-wizard__card" id="step-planning">
            <h2>Krok 2: Planowanie</h2>
            <p>Dobierz kluczowe komponenty z magazynu i określ koszt zestawu. Możesz nadpisać cenę jednostkową ręcznie.</p>
            <form method="post" class="pc-builder-wizard__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save-planning">
              <input type="hidden" name="build_id" value="<?= (int) $selectedBuildId ?>">

              <div class="pc-builder-wizard__grid">
                <?php foreach ($componentCategories as $categoryKey => $categoryLabel): ?>
                  <?php
                    $componentData = $planningFormData['components'][$categoryKey] ?? [
                        'item_id' => '',
                        'label' => '',
                        'quantity' => 1,
                        'notes' => '',
                        'unit_price_cents' => 0,
                    ];
                    $selectedItemId = $componentData['item_id'] !== '' ? (int) $componentData['item_id'] : null;
                    if ($selectedItemId && !isset($itemOptionsById[$selectedItemId])) {
                        $itemOptionsById[$selectedItemId] = [
                            'id' => $selectedItemId,
                            'name' => $componentData['label'],
                            'unit_price_cents' => $componentData['unit_price_cents'],
                        ];
                    }
                  ?>
                  <fieldset class="pc-builder-wizard__component" <?= $isApproved ? 'disabled' : '' ?>>
                    <legend><?= htmlspecialchars($categoryLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></legend>
                    <label>
                      Pozycja magazynowa
                      <select name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][item_id]" <?= $isApproved ? 'disabled' : '' ?>>
                        <option value="">-- brak --</option>
                        <?php foreach ($itemOptionsById as $itemId => $item): ?>
                          <option value="<?= $itemId ?>" <?= $selectedItemId === $itemId ? 'selected' : '' ?>>
                            <?= htmlspecialchars((string) ($item['name'] ?? 'Pozycja #' . $itemId), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </label>
                    <label>
                      Nazwa niestandardowa (gdy brak na magazynie)
                      <input type="text" name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][custom_name]" value="<?= htmlspecialchars($componentData['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $isApproved ? 'disabled' : '' ?>>
                    </label>
                    <label>
                      Ilość
                      <input type="number" min="1" name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][quantity]" value="<?= max(1, (int) $componentData['quantity']) ?>" <?= $isApproved ? 'disabled' : '' ?>>
                    </label>
                    <label>
                      Cena jednostkowa (<?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                      <input type="text" name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][unit_price]" value="<?= $componentData['unit_price_cents'] > 0 ? number_format($componentData['unit_price_cents'] / 100, 2, ',', ' ') : '' ?>" <?= $isApproved ? 'disabled' : '' ?>>
                    </label>
                    <label>
                      Uwagi
                      <textarea name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][notes]" rows="2" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($componentData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </label>
                  </fieldset>
                <?php endforeach; ?>
              </div>

              <label>
                Waluta
                <input type="text" name="currency" value="<?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="8" <?= $isApproved ? 'disabled' : '' ?>>
              </label>

              <label>
                Uwagi do planu
                <textarea name="notes" rows="3" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($planningFormData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <?php if (isset($errors['planning']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['planning']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
              <?php if (isset($errors['planning']['components'])): ?><p class="form-error"><?= htmlspecialchars($errors['planning']['components'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <button type="submit" class="btn" <?= $isApproved ? 'disabled' : '' ?>>Zapisz plan i kosztorys</button>
              <?php if (($planningPayload['plan_pdf_path'] ?? '') !== ''): ?>
                <a class="btn btn--ghost" href="<?= htmlspecialchars($planningPayload['plan_pdf_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Pobierz ostatni plan PDF</a>
              <?php endif; ?>
            </form>
          </section>

          <section class="pc-builder-wizard__card" id="step-assembly">
            <h2>Krok 3: Montaż</h2>
            <p>Odhacz wykonane czynności i zanotuj zużyte drobiazgi. Wszystkie zmiany trafiają do dziennika budowy.</p>
            <form method="post" class="pc-builder-wizard__form">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save-assembly">
              <input type="hidden" name="build_id" value="<?= (int) $selectedBuildId ?>">

              <fieldset class="pc-builder-wizard__tasks" <?= $isApproved ? 'disabled' : '' ?>>
                <legend>Checklista montażowa</legend>
                <?php foreach ($assemblyTasks as $taskKey => $taskLabel): ?>
                  <label class="pc-builder-wizard__checkbox">
                    <input type="checkbox" name="tasks_completed[]" value="<?= htmlspecialchars($taskKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= in_array($taskKey, $assemblyFormData['tasks_completed'], true) ? 'checked' : '' ?> <?= $isApproved ? 'disabled' : '' ?>>
                    <span><?= htmlspecialchars($taskLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  </label>
                <?php endforeach; ?>
              </fieldset>

              <label>
                Zużyte drobiazgi / części
                <textarea name="small_parts" rows="2" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($assemblyFormData['small_parts'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <label>
                Uwagi serwisu
                <textarea name="notes" rows="3" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($assemblyFormData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <?php if (isset($errors['assembly']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['assembly']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <button type="submit" class="btn" <?= $isApproved ? 'disabled' : '' ?>>Zapisz checklistę</button>
            </form>
          </section>

          <section class="pc-builder-wizard__card" id="step-release">
            <h2>Krok 4: Wydanie</h2>
            <p>Uzupełnij dane wydania, zbierz podpis i zakończ budowę. Po zapisaniu generowany jest protokół PDF i wysyłany e-mail do klienta.</p>
            <form method="post" class="pc-builder-wizard__form" enctype="multipart/form-data">
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <input type="hidden" name="action" value="save-release">
              <input type="hidden" name="build_id" value="<?= (int) $selectedBuildId ?>">

              <label>
                Data wydania
                <input type="date" name="release_date" value="<?= htmlspecialchars($releaseFormData['release_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $isApproved ? 'disabled' : '' ?> required>
              </label>
              <?php if (isset($errors['release']['release_date'])): ?><p class="form-error"><?= htmlspecialchars($errors['release']['release_date'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <label>
                Sposób dostarczenia
                <select name="delivery_method" <?= $isApproved ? 'disabled' : '' ?> required>
                  <?php foreach ($deliveryMethods as $methodKey => $methodLabel): ?>
                    <option value="<?= htmlspecialchars($methodKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $releaseFormData['delivery_method'] === $methodKey ? 'selected' : '' ?>>
                      <?= htmlspecialchars($methodLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </label>
              <?php if (isset($errors['release']['delivery_method'])): ?><p class="form-error"><?= htmlspecialchars($errors['release']['delivery_method'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <label>
                Szczegóły dostawy (np. numer przesyłki)
                <input type="text" name="delivery_details" value="<?= htmlspecialchars($releaseFormData['delivery_details'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $isApproved ? 'disabled' : '' ?>>
              </label>

              <label>
                Uwagi końcowe
                <textarea name="notes" rows="3" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($releaseFormData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <label>
                Podpis odbiorcy (PNG lub JPG)
                <input type="file" name="signature_file" accept="image/png,image/jpeg" <?= $isApproved ? 'disabled' : '' ?>>
              </label>
              <?php if ($releaseFormData['signature_path'] !== ''): ?>
                <p>Aktualny podpis: <a href="<?= htmlspecialchars($releaseFormData['signature_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">zobacz</a></p>
              <?php endif; ?>

              <?php if (isset($errors['release']['signature_file'])): ?><p class="form-error"><?= htmlspecialchars($errors['release']['signature_file'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
              <?php if (isset($errors['release']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['release']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

              <button type="submit" class="btn" <?= $isApproved ? 'disabled' : '' ?>>Zakończ budowę i wygeneruj protokół</button>
              <?php if (($releasePayload['release_pdf_path'] ?? '') !== ''): ?>
                <a class="btn btn--ghost" href="<?= htmlspecialchars($releasePayload['release_pdf_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" target="_blank" rel="noopener">Pobierz protokół wydania</a>
              <?php endif; ?>
            </form>
          </section>
        <?php endif; ?>
      </section>
    </div>
  </main>
</body>
</html>
