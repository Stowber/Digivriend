<?php

declare(strict_types=1);

use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Services\InventoryService;
use App\Support\Clock;
use App\Support\Notifications\NotificationService;
use App\Support\Repositories\CustomerRepository;
use App\Support\Repositories\PcBuildDocumentRepository;
use App\Support\Repositories\PcBuildRepository;
use App\Support\Repositories\WarehouseRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';
require_once __DIR__ . '/templates/partials/main-nav.php';
require_once __DIR__ . '/generate-pc-build-summary.php';
require_once __DIR__ . '/generate-pc-build-plan.php';
require_once __DIR__ . '/generate-pc-build-release.php';
require_once __DIR__ . '/generate-pc-build-delivery.php';

$pcBuildRepository = new PcBuildRepository($pdo);
$warehouseRepository = new WarehouseRepository($pdo);
$customerRepository = new CustomerRepository($pdo);
$notificationService = new NotificationService($pdo);
$pcBuildDocumentRepository = new PcBuildDocumentRepository($pdo);
$inventoryService = new InventoryService($warehouseRepository);

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
    'margin_percent' => '0',
    'components' => [],
    'totals' => [
        'subtotal_cents' => 0,
        'margin_cents' => 0,
        'total_cents' => 0,
    ],
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
                        $summaryPdfRelative = 'storage/documents/pc-builds/summary-' . (int) $selectedBuildId . '.pdf';
                        $summaryPdfPath = __DIR__ . '/' . $summaryPdfRelative;
                        $summaryGenerated = false;

                        try {
                            generatePcBuildSummaryPdf($pdo, (int) $selectedBuildId, $summaryPdfPath);
                            $pcBuildDocumentRepository->log(
                                (int) $selectedBuildId,
                                'information_summary',
                                'generated',
                                $summaryPdfRelative,
                                null,
                                null,
                                ['step' => 'information', 'document' => 'summary']
                            );
                            $summaryGenerated = true;
                        } catch (Throwable $documentException) {
                            $pcBuildDocumentRepository->log(
                                (int) $selectedBuildId,
                                'information_summary',
                                'failed',
                                $summaryPdfRelative,
                                null,
                                $documentException->getMessage(),
                                ['step' => 'information', 'document' => 'summary']
                            );
                            $errors['information']['general'] = 'Nie udało się wygenerować podsumowania buildu.';
                        }

                        if ($summaryGenerated) {
                            $_SESSION['pc_builder_success'] = 'Zapisano dane podstawowe budowy i wygenerowano podsumowanie PDF.';
                            Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                        }
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
                $planningFormData['margin_percent'] = trim((string) ($_POST['margin_percent'] ?? '0'));
                $rawComponents = is_array($_POST['components'] ?? null) ? $_POST['components'] : [];
                $componentsPayload = [];
                $shortages = [];
                $subtotalCents = 0;
                $marginPercentValue = $inventoryService->parsePercentage($planningFormData['margin_percent']);

                if ($selectedBuildId === null || $selectedBuildId <= 0) {
                    $errors['planning']['general'] = 'Nie wybrano budowy do aktualizacji planu.';
                }

                $existingPlanningPayload = [];
                $existingComponentsByCategory = [];
                if ($selectedBuildId !== null && $selectedBuildId > 0) {
                    try {
                        $currentDetails = $pcBuildRepository->buildDetails((int) $selectedBuildId);
                        $existingPlanningPayload = is_array($currentDetails['build']['planning_payload'] ?? null)
                            ? $currentDetails['build']['planning_payload']
                            : [];
                        foreach (is_array($existingPlanningPayload['components'] ?? null) ? $existingPlanningPayload['components'] : [] as $existingComponent) {
                            $category = (string) ($existingComponent['category'] ?? '');
                            if ($category !== '') {
                                $existingComponentsByCategory[$category] = $existingComponent;
                            }
                        }
                    } catch (Throwable $exception) {
                        $existingPlanningPayload = [];
                        $existingComponentsByCategory = [];
                    }
                }

                foreach ($componentCategories as $categoryKey => $categoryLabel) {
                    $planningFormData['components'][$categoryKey] = [
                        'item_id' => '',
                        'label' => '',
                        'quantity' => 1,
                        'notes' => '',
                        'unit_price_cents' => 0,
                        'availability' => [
                            'available_quantity' => null,
                            'missing_quantity' => 0,
                            'is_available' => true,
                        ],
                        'unit_price_input' => '',
                    ];
                    $componentInput = is_array($rawComponents[$categoryKey] ?? null) ? $rawComponents[$categoryKey] : [];
                    $itemIdRaw = $componentInput['item_id'] ?? '';
                    $itemIdValue = $itemIdRaw !== '' ? filter_var($itemIdRaw, FILTER_VALIDATE_INT) : null;
                    $itemId = $itemIdValue !== false && $itemIdValue !== null ? (int) $itemIdValue : null;
                    $quantity = isset($componentInput['quantity']) ? (int) $componentInput['quantity'] : 0;
                    $notes = trim((string) ($componentInput['notes'] ?? ''));
                    $customLabel = trim((string) ($componentInput['custom_name'] ?? ''));
                    $manualPriceInput = trim((string) ($componentInput['unit_price'] ?? ''));
                    $manualPriceCents = $manualPriceInput !== '' ? $inventoryService->parsePriceToCents($manualPriceInput) : null;

                    if ($itemId !== null) {
                        $planningFormData['components'][$categoryKey]['item_id'] = (string) $itemId;
                    }
                    if ($quantity > 0) {
                        $planningFormData['components'][$categoryKey]['quantity'] = $quantity;
                    }
                    if ($customLabel !== '') {
                        $planningFormData['components'][$categoryKey]['label'] = $customLabel;
                    }
                    $planningFormData['components'][$categoryKey]['notes'] = $notes;
                    if ($manualPriceInput !== '') {
                        $planningFormData['components'][$categoryKey]['unit_price_input'] = $manualPriceInput;
                    }

                    $existingComponent = $existingComponentsByCategory[$categoryKey] ?? [];
                    $preparedComponent = $inventoryService->prepareComponent(
                        $categoryKey,
                        $itemId,
                        $quantity,
                        $customLabel,
                        $manualPriceCents,
                        $notes,
                        $existingComponent
                    );

                    if ($preparedComponent === null) {
                        continue;
                    }

                    if (isset($preparedComponent['errors']['not_found'])) {
                        $errors['planning']['components'] = 'Nie znaleziono jednego z wybranych komponentów.';
                        continue;
                    }

                    $componentPayload = $preparedComponent['payload'];
                    $componentsPayload[] = $componentPayload;

                    $availability = $preparedComponent['availability'];
                    $planningFormData['components'][$categoryKey] = [
                        'item_id' => $componentPayload['item_id'] !== null ? (string) $componentPayload['item_id'] : '',
                        'label' => (string) ($componentPayload['label'] ?? ''),
                        'quantity' => (int) ($componentPayload['quantity'] ?? 1),
                        'notes' => (string) ($componentPayload['notes'] ?? ''),
                        'unit_price_cents' => (int) ($componentPayload['unit_price_cents'] ?? 0),
                        'availability' => $availability,
                        'unit_price_input' => $manualPriceInput !== '' ? $manualPriceInput : '',
                    ];

                    $subtotalCents += (int) ($componentPayload['total_price_cents'] ?? 0);

                    if (!$availability['is_available']) {
                        $shortages[] = [
                            'category' => $categoryLabel,
                            'label' => (string) ($componentPayload['label'] ?? $categoryLabel),
                            'required' => (int) ($componentPayload['quantity'] ?? 1),
                            'available' => (int) ($availability['available_quantity'] ?? 0),
                        ];
                    }
                }

                $marginCents = $inventoryService->calculateMargin(max(0, $subtotalCents), $marginPercentValue);
                $totalCostCents = max(0, $subtotalCents + $marginCents);
                $planningFormData['totals'] = [
                    'subtotal_cents' => max(0, $subtotalCents),
                    'margin_cents' => $marginCents,
                    'total_cents' => $totalCostCents,
                ];

                if ($shortages !== []) {
                    $messages = [];
                    foreach ($shortages as $shortage) {
                        $messages[] = sprintf(
                            '%s – dostępne %d z %d.',
                            $shortage['label'] !== '' ? $shortage['label'] : $shortage['category'],
                            (int) $shortage['available'],
                            (int) $shortage['required']
                        );
                    }
                    $errors['planning']['inventory'] = 'Nie można zapisać planu. ' . implode(' ', $messages);
                }

                if ($errors['planning'] === [] && $selectedBuildId !== null && $selectedBuildId > 0) {
                    try {
                        $planPdfRelative = 'storage/documents/pc-builds/plan-' . (int) $selectedBuildId . '.pdf';
                        $planningPayload = [
                            'components' => $componentsPayload,
                            'notes' => $planningFormData['notes'],
                            'plan_pdf_path' => $planPdfRelative,
                            'generated_at' => date('c'),
                            'financials' => [
                                'subtotal_cents' => max(0, $subtotalCents),
                                'margin_percent' => $marginPercentValue,
                                'margin_cents' => $marginCents,
                                'total_cents' => $totalCostCents,
                            ],
                            'currency' => $planningFormData['currency'],
                        ];
                        $pcBuildRepository->savePlanningData(
                            (int) $selectedBuildId,
                            $planningPayload,
                            $totalCostCents,
                            $planningFormData['currency'],
                            Auth::username()
                        );
                        $planPdfPath = __DIR__ . '/' . $planPdfRelative;
                        generatePcBuildPlanPdf($pdo, (int) $selectedBuildId, $planPdfPath);
                        $pcBuildDocumentRepository->log(
                            (int) $selectedBuildId,
                            'planning_plan',
                            'generated',
                            $planPdfRelative,
                            null,
                            null,
                            ['step' => 'planning', 'document' => 'plan']
                        );
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
                    $planningPayloadForInventory = [];
                    $caseIdForInventory = null;

                    try {
                        $buildDetails = $pcBuildRepository->buildDetails((int) $selectedBuildId);
                        $buildData = is_array($buildDetails['build'] ?? null) ? $buildDetails['build'] : [];
                        $planningPayloadForInventory = is_array($buildData['planning_payload'] ?? null)
                            ? $buildData['planning_payload']
                            : [];
                        $caseIdForInventory = isset($buildData['case_id']) ? (int) $buildData['case_id'] : null;
                    } catch (Throwable $exception) {
                        $errors['assembly']['general'] = 'Nie udało się pobrać planu komponentów do rezerwacji.';
                    }

                    if (
                        $errors['assembly'] === []
                        && $planningPayloadForInventory !== []
                        && is_array($planningPayloadForInventory['components'] ?? null)
                        && $planningPayloadForInventory['components'] !== []
                    ) {
                        $reservationResult = $inventoryService->reservePlannedComponents(
                            $planningPayloadForInventory,
                            (int) $selectedBuildId,
                            $caseIdForInventory,
                            Auth::username()
                        );

                        if ($reservationResult['errors'] !== []) {
                            $errors['assembly']['general'] = implode(' ', $reservationResult['errors']);
                        } else {
                            try {
                                $pcBuildRepository->updatePlanningPayload(
                                    (int) $selectedBuildId,
                                    $reservationResult['updated_payload'],
                                    Auth::username(),
                                    'Zarezerwowano komponenty w magazynie.'
                                );
                            } catch (Throwable $exception) {
                                $errors['assembly']['general'] = 'Nie udało się zaktualizować rezerwacji magazynowych.';
                            }
                        }
                    }
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
                    $planningPayloadForInventory = [];
                    $caseIdForInventory = null;

                    try {
                        $buildDetails = $pcBuildRepository->buildDetails((int) $selectedBuildId);
                        $buildData = is_array($buildDetails['build'] ?? null) ? $buildDetails['build'] : [];
                        $planningPayloadForInventory = is_array($buildData['planning_payload'] ?? null)
                            ? $buildData['planning_payload']
                            : [];
                        $caseIdForInventory = isset($buildData['case_id']) ? (int) $buildData['case_id'] : null;
                    } catch (Throwable $exception) {
                        $errors['release']['general'] = 'Nie udało się pobrać planu komponentów do rozliczenia.';
                    }

                    if (
                        $errors['release'] === []
                        && $planningPayloadForInventory !== []
                        && is_array($planningPayloadForInventory['components'] ?? null)
                        && $planningPayloadForInventory['components'] !== []
                    ) {
                        $consumptionResult = $inventoryService->finalizePlannedComponents(
                            $planningPayloadForInventory,
                            (int) $selectedBuildId,
                            $caseIdForInventory,
                            Auth::username()
                        );

                        if ($consumptionResult['errors'] !== []) {
                            $errors['release']['general'] = implode(' ', $consumptionResult['errors']);
                        } else {
                            try {
                                $pcBuildRepository->updatePlanningPayload(
                                    (int) $selectedBuildId,
                                    $consumptionResult['updated_payload'],
                                    Auth::username(),
                                    'Zużyto zarezerwowane komponenty do buildu.'
                                );
                            } catch (Throwable $exception) {
                                $errors['release']['general'] = 'Nie udało się zaktualizować danych magazynowych.';
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
                        $pcBuildDocumentRepository->log(
                            (int) $selectedBuildId,
                            'release_protocol',
                            'generated',
                            $releasePdfRelative,
                            null,
                            null,
                            ['step' => 'release', 'document' => 'protocol']
                        );

                        $deliveryPdfRelative = 'storage/documents/pc-builds/delivery-' . (int) $selectedBuildId . '.pdf';
                        $deliveryPdfPath = __DIR__ . '/' . $deliveryPdfRelative;

                        try {
                            generatePcBuildDeliveryPdf($pdo, (int) $selectedBuildId, $deliveryPdfPath);
                            $pcBuildDocumentRepository->log(
                                (int) $selectedBuildId,
                                'delivery_confirmation',
                                'generated',
                                $deliveryPdfRelative,
                                null,
                                null,
                                ['step' => 'release', 'document' => 'delivery']
                            );
                        } catch (Throwable $documentException) {
                            $pcBuildDocumentRepository->log(
                                (int) $selectedBuildId,
                                'delivery_confirmation',
                                'failed',
                                $deliveryPdfRelative,
                                null,
                                $documentException->getMessage(),
                                ['step' => 'release', 'document' => 'delivery']
                            );
                            $errors['release']['general'] = 'Nie udało się wygenerować potwierdzenia odbioru.';
                            throw $documentException;
                        }

                        $updatedBuild = $pcBuildRepository->findBuild((int) $selectedBuildId);
                        $recipient = isset($updatedBuild['customer_email']) ? (string) $updatedBuild['customer_email'] : '';
                        $emailNote = '';
                        if ($recipient !== '') {
                            $emailResult = $notificationService->sendPcBuildRelease(
                                isset($updatedBuild['case_id']) ? (int) $updatedBuild['case_id'] : null,
                                isset($updatedBuild['customer_id']) ? (int) $updatedBuild['customer_id'] : null,
                                $recipient,
                                [
                                    'customer_name' => (string) ($updatedBuild['customer_name'] ?? 'klient'),
                                    'build_reference' => (string) ($updatedBuild['reference_code'] ?? ''),
                                    'delivery_method' => $releasePayload['delivery_method'],
                                    'release_date' => $releasePayload['release_date'],
                                    'subject' => 'Potwierdzenie wydania zestawu PC ' . (string) ($updatedBuild['reference_code'] ?? ''),
                                    ],
                                [
                                    [
                                        'path' => $deliveryPdfPath,
                                        'name' => sprintf('potwierdzenie-odbioru-%d.pdf', (int) $selectedBuildId),
                                        'mime' => 'application/pdf',
                                    ],
                                ]
                            );

                            if ($emailResult['success']) {
                                $pcBuildDocumentRepository->log(
                                    (int) $selectedBuildId,
                                    'delivery_confirmation',
                                    'emailed',
                                    $deliveryPdfRelative,
                                    $recipient,
                                    null,
                                    ['step' => 'release', 'document' => 'delivery', 'action' => 'email'],
                                    Clock::nowFormatted()
                                );
                                $emailNote = ' Wysłano potwierdzenie e-mailem do klienta.';
                            } else {
                                $pcBuildDocumentRepository->log(
                                    (int) $selectedBuildId,
                                    'delivery_confirmation',
                                    'failed',
                                    $deliveryPdfRelative,
                                    $recipient,
                                    $emailResult['error'] ?? null,
                                    ['step' => 'release', 'document' => 'delivery', 'action' => 'email']
                                );
                                $emailNote = ' Nie udało się wysłać e-maila do klienta.';
                            }
                        }

                        $_SESSION['pc_builder_success'] = 'Budowa została zatwierdzona i wygenerowano dokumenty wydania.' . $emailNote;
                        Response::redirect('pc-builder.php?build_id=' . (int) $selectedBuildId);
                    } catch (Throwable $exception) {
                        if (!isset($errors['release']['general'])) {
                            $errors['release']['general'] = 'Nie udało się zapisać danych wydania.';
                        }
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
    if ($selectedBuild !== null) {
        $planningFormData['currency'] = (string) ($selectedBuild['planning_currency'] ?? $planningFormData['currency']);
    }

    $financials = is_array($planningPayload['financials'] ?? null) ? $planningPayload['financials'] : [];
    $subtotalFromPayload = (int) ($financials['subtotal_cents'] ?? 0);
    $marginFromPayload = (int) ($financials['margin_cents'] ?? 0);
    $totalFromPayload = (int) ($financials['total_cents'] ?? ($selectedBuild['planning_total_cents'] ?? ($subtotalFromPayload + $marginFromPayload)));

    if ($marginFromPayload <= 0 && $totalFromPayload > $subtotalFromPayload) {
        $marginFromPayload = $totalFromPayload - $subtotalFromPayload;
    }

    $planningFormData['totals'] = [
        'subtotal_cents' => max(0, $subtotalFromPayload),
        'margin_cents' => max(0, $marginFromPayload),
        'total_cents' => max(0, $totalFromPayload),
    ];

    $marginPercentValue = (float) ($financials['margin_percent'] ?? 0.0);
    $marginPercentFormatted = rtrim(rtrim(number_format($marginPercentValue, 2, '.', ''), '0'), '.');
    if ($marginPercentFormatted === '') {
        $marginPercentFormatted = '0';
    }
    $planningFormData['margin_percent'] = $marginPercentFormatted;
    $componentsByCategory = [];
    foreach (is_array($planningPayload['components'] ?? null) ? $planningPayload['components'] : [] as $component) {
        $category = (string) ($component['category'] ?? '');
        $componentsByCategory[$category] = $component;
    }
    foreach ($componentCategories as $categoryKey => $categoryLabel) {
        $component = $componentsByCategory[$categoryKey] ?? [];
        $requiredQuantity = max(1, (int) ($component['quantity'] ?? 1));
        $availableQuantity = isset($component['available_quantity']) ? (int) $component['available_quantity'] : null;
        $missingQuantity = $availableQuantity !== null ? max(0, $requiredQuantity - $availableQuantity) : 0;
        $planningFormData['components'][$categoryKey] = [
            'item_id' => isset($component['item_id']) ? (string) $component['item_id'] : '',
            'label' => (string) ($component['label'] ?? ''),
            'quantity' => $requiredQuantity,
            'notes' => (string) ($component['notes'] ?? ''),
            'unit_price_cents' => (int) ($component['unit_price_cents'] ?? 0),
            'availability' => [
                'available_quantity' => $availableQuantity,
                'missing_quantity' => $missingQuantity,
                'is_available' => $missingQuantity === 0,
            ],
            'unit_price_input' => '',
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
                        'availability' => [
                            'available_quantity' => null,
                            'missing_quantity' => 0,
                            'is_available' => true,
                        ],
                        'unit_price_input' => '',
                    ];
                    $selectedItemId = $componentData['item_id'] !== '' ? (int) $componentData['item_id'] : null;
                    if ($selectedItemId && !isset($itemOptionsById[$selectedItemId])) {
                        $itemOptionsById[$selectedItemId] = [
                            'id' => $selectedItemId,
                            'name' => $componentData['label'],
                            'unit_price_cents' => $componentData['unit_price_cents'],
                        ];
                    }
                    $availability = is_array($componentData['availability'] ?? null)
                        ? $componentData['availability']
                        : [
                            'available_quantity' => null,
                            'missing_quantity' => 0,
                            'is_available' => true,
                        ];
                    $unitPriceValue = (string) ($componentData['unit_price_input'] ?? '');
                    if ($unitPriceValue === '') {
                        $componentUnitPriceCents = (int) ($componentData['unit_price_cents'] ?? 0);
                        $unitPriceValue = $componentUnitPriceCents > 0
                            ? number_format($componentUnitPriceCents / 100, 2, ',', ' ')
                            : '';
                    }
                    $availableQuantityDisplay = isset($availability['available_quantity']) && $availability['available_quantity'] !== null
                        ? (int) $availability['available_quantity']
                        : null;
                    $missingQuantityDisplay = isset($availability['missing_quantity'])
                        ? max(0, (int) $availability['missing_quantity'])
                        : 0;
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
                      <input type="text" name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][unit_price]" value="<?= htmlspecialchars($unitPriceValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $isApproved ? 'disabled' : '' ?>>
                    </label>
                    <label>
                      Uwagi
                      <textarea name="components[<?= htmlspecialchars($categoryKey, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>][notes]" rows="2" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($componentData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
                    </label>
                    <?php if (($componentData['item_id'] ?? '') !== '' && isset($availability['is_available']) && !$availability['is_available']): ?>
                      <p class="form-error">Brakuje <?= $missingQuantityDisplay ?> szt. (dostępne <?= $availableQuantityDisplay !== null ? $availableQuantityDisplay : 0 ?>).</p>
                    <?php endif; ?>
                  </fieldset>
                <?php endforeach; ?>
              </div>

              <label>
                Marża (%)
                <input type="text" name="margin_percent" value="<?= htmlspecialchars($planningFormData['margin_percent'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="8" <?= $isApproved ? 'disabled' : '' ?>>
              </label>

              <label>
                Waluta
                <input type="text" name="currency" value="<?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" maxlength="8" <?= $isApproved ? 'disabled' : '' ?>>
              </label>

              <label>
                Uwagi do planu
                <textarea name="notes" rows="3" <?= $isApproved ? 'disabled' : '' ?>><?= htmlspecialchars($planningFormData['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
              </label>

              <?php
                $subtotalDisplay = number_format(($planningFormData['totals']['subtotal_cents'] ?? 0) / 100, 2, ',', ' ');
                $marginDisplay = number_format(($planningFormData['totals']['margin_cents'] ?? 0) / 100, 2, ',', ' ');
                $totalDisplay = number_format(($planningFormData['totals']['total_cents'] ?? 0) / 100, 2, ',', ' ');
              ?>
              <div class="pc-builder-wizard__totals">
                <p>Wartość komponentów: <strong><?= $subtotalDisplay ?> <?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                <p>Marża (<?= htmlspecialchars($planningFormData['margin_percent'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>%): <strong><?= $marginDisplay ?> <?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
                <p><strong>Łączny koszt: <?= $totalDisplay ?> <?= htmlspecialchars($planningFormData['currency'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></p>
              </div>

              <?php if (isset($errors['planning']['general'])): ?><p class="form-error"><?= htmlspecialchars($errors['planning']['general'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
              <?php if (isset($errors['planning']['components'])): ?><p class="form-error"><?= htmlspecialchars($errors['planning']['components'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
              <?php if (isset($errors['planning']['inventory'])): ?><p class="form-error"><?= htmlspecialchars($errors['planning']['inventory'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>

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
