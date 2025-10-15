<?php

declare(strict_types=1);

use App\Exception\ValidationException;
use App\Http\Response;
use App\Security\Auth;
use App\Security\Csrf;
use App\Support\Repositories\CaseRepository;
use App\Support\Repositories\DevicePhotoRepository;
use App\Support\Repositories\DeviceComponentRepository;
use App\Support\Repositories\DeviceRepository;
use App\Support\Repositories\NoteRepository;
use App\Support\Repositories\RepairEventRepository;
use App\Validation\InputValidator;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

if (!function_exists('formatComponentDuration')) {
    function formatComponentDuration(\DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $diff = $from->diff($to);

        $units = [
            'y' => ['jaar', 'jaar'],
            'm' => ['maand', 'maanden'],
            'd' => ['dag', 'dagen'],
            'h' => ['uur', 'uur'],
            'i' => ['minuut', 'minuten'],
        ];

        $parts = [];
        foreach ($units as $property => $labels) {
            $value = $diff->$property;
            if ($value <= 0) {
                continue;
            }

            [$singular, $plural] = $labels;
            $parts[] = $value . ' ' . ($value === 1 ? $singular : $plural);

            if (count($parts) === 2) {
                break;
            }
        }

        if ($parts === []) {
            return 'minder dan een minuut';
        }

        return implode(', ', $parts);
    }
}

$deviceRepository = new DeviceRepository($pdo);
$photoRepository = new DevicePhotoRepository($pdo);
$componentRepository = new DeviceComponentRepository($pdo);
$repairEventRepository = new RepairEventRepository($pdo);
$caseRepository = new CaseRepository($pdo);
$noteRepository = new NoteRepository($pdo);

$deviceIdParam = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$barcodeParam = trim((string) ($_GET['barcode'] ?? ''));

if ($barcodeParam !== '') {
    $device = $deviceRepository->findWithCustomerByBarcode($barcodeParam);
} elseif ($deviceIdParam) {
    $device = $deviceRepository->findWithCustomer((int) $deviceIdParam);
} else {
    $device = null;
}

if ($device === null) {
    http_response_code(404);
    echo '<h1>Apparaat niet gevonden</h1>';
    exit;
}

$deviceId = (int) $device['id'];
$barcode = $deviceRepository->ensureBarcode($deviceId);

$casesStatement = $pdo->prepare(
    'SELECT c.*, COUNT(re.id) AS events_count
     FROM cases c
     LEFT JOIN repair_events re ON re.case_id = c.id
     WHERE c.device_id = :device_id
     GROUP BY c.id
     ORDER BY c.updated_at DESC'
);
$casesStatement->execute(['device_id' => $deviceId]);
$cases = $casesStatement->fetchAll() ?: [];

$caseOptions = [];
foreach ($cases as $case) {
    $caseOptions[(int) $case['id']] = $case;
}

$photos = $photoRepository->forDevice($deviceId);
$events = $repairEventRepository->forDevice($deviceId);
$components = $componentRepository->forDevice($deviceId);
$globalComponentSuggestions = $componentRepository->distinctValues();

$componentSummary = [
    'total' => count($components),
    'active' => 0,
    'archived' => 0,
    'warranty_expiring' => 0,
    'warranty_expired' => 0,
    'maintenance_due' => 0,
    'maintenance_upcoming' => 0,
    'audit_missing' => 0,
    'audit_overdue' => 0,
    'audit_due_soon' => 0,
];
$componentInsights = [
    'never_audited' => 0,
    'audit_overdue' => 0,
    'audit_due_soon' => 0,
    'missing_serial' => 0,
    'missing_supplier' => 0,
    'average_age_days' => null,
    'oldest_component' => null,
    'newest_component' => null,
];
$componentSuggestionSets = [
    'names' => [],
    'manufacturers' => [],
    'models' => [],
    'suppliers' => [],
    'locations' => [],
];
$categoryBreakdown = [];
$categoryLabelsSet = [];
$ageTotalDays = 0;
$ageSampleCount = 0;
$oldestComponent = null;
$newestComponent = null;

$now = new \DateTimeImmutable('now');

if ($components !== []) {
    $warrantyAttentionThreshold = $now->modify('+30 days');
    $maintenanceAttentionThreshold = $now->modify('+7 days');
    $auditRecencyThreshold = $now->modify('-180 days');

    foreach ($components as $index => $component) {
        $isActive = empty($component['removed_at']);
        $components[$index]['is_active'] = $isActive;

        if ($isActive) {
            $componentSummary['active']++;
        } else {
            $componentSummary['archived']++;
        }

        $categoryLabel = (string) ($component['category'] ?? '');
        if ($categoryLabel !== '') {
            if (!isset($categoryBreakdown[$categoryLabel])) {
                $categoryBreakdown[$categoryLabel] = [
                    'total' => 0,
                    'active' => 0,
                    'archived' => 0,
                ];
            }
            $categoryBreakdown[$categoryLabel]['total']++;
            if ($isActive) {
                $categoryBreakdown[$categoryLabel]['active']++;
            } else {
                $categoryBreakdown[$categoryLabel]['archived']++;
            }

            if (!isset($categoryLabelsSet[$categoryLabel])) {
                $categoryLabelsSet[$categoryLabel] = true;
            }
        }

        $installedAt = null;
        if (!empty($component['installed_at'])) {
            $installedCandidate = date_create_immutable((string) $component['installed_at']);
            if ($installedCandidate instanceof \DateTimeImmutable) {
                $installedAt = $installedCandidate;
            }
        }

        if ($installedAt instanceof \DateTimeImmutable) {
            $ageInDays = (int) $installedAt->diff($now)->format('%a');
            $ageTotalDays += $ageInDays;
            $ageSampleCount++;

            if ($oldestComponent === null || $installedAt < $oldestComponent['installed_at']) {
                $oldestComponent = [
                    'name' => (string) $component['component_name'],
                    'category' => $categoryLabel,
                    'installed_at' => $installedAt,
                    'is_active' => $isActive,
                ];
            }

            if ($newestComponent === null || $installedAt > $newestComponent['installed_at']) {
                $newestComponent = [
                    'name' => (string) $component['component_name'],
                    'category' => $categoryLabel,
                    'installed_at' => $installedAt,
                    'is_active' => $isActive,
                ];
            }
        }

        if ($isActive) {
            if (empty($component['serial_number'])) {
                $componentInsights['missing_serial']++;
            }

            if (empty($component['supplier'])) {
                $componentInsights['missing_supplier']++;
            }
        }

        if (!empty($component['component_name'])) {
            $componentSuggestionSets['names'][(string) $component['component_name']] = true;
        }
        if (!empty($component['manufacturer'])) {
            $componentSuggestionSets['manufacturers'][(string) $component['manufacturer']] = true;
        }
        if (!empty($component['model'])) {
            $componentSuggestionSets['models'][(string) $component['model']] = true;
        }
        if (!empty($component['supplier'])) {
            $componentSuggestionSets['suppliers'][(string) $component['supplier']] = true;
        }
        if (!empty($component['inventory_location'])) {
            $componentSuggestionSets['locations'][(string) $component['inventory_location']] = true;
        }
        $components[$index]['installed_at_display'] = $installedAt instanceof \DateTimeImmutable ? $installedAt->format('d-m-Y H:i') : 'Onbekend';
        $components[$index]['installed_duration'] = $installedAt instanceof \DateTimeImmutable ? formatComponentDuration($installedAt, $now) : null;

        $warrantyStatus = 'none';
        $warrantyDisplay = '';
        $warrantyExpiresAt = null;
        if (!empty($component['warranty_expires_at'])) {
            $warrantyCandidate = date_create_immutable((string) $component['warranty_expires_at']);
            if ($warrantyCandidate instanceof \DateTimeImmutable) {
                $warrantyExpiresAt = $warrantyCandidate;
            }
        }

        if ($warrantyExpiresAt instanceof \DateTimeImmutable) {
            $warrantyDisplay = $warrantyExpiresAt->format('d-m-Y');
            if ($warrantyExpiresAt < $now) {
                $warrantyStatus = 'expired';
                $componentSummary['warranty_expired']++;
            } elseif ($warrantyExpiresAt <= $warrantyAttentionThreshold) {
                $warrantyStatus = 'expiring';
                $componentSummary['warranty_expiring']++;
            } else {
                $warrantyStatus = 'active';
            }
        }

        $components[$index]['warranty_expires_display'] = $warrantyDisplay;
        $components[$index]['warranty_status'] = $warrantyStatus;

        $lastAuditedAt = null;
        if (!empty($component['last_audited_at'])) {
            $lastAuditedCandidate = date_create_immutable((string) $component['last_audited_at']);
            if ($lastAuditedCandidate instanceof \DateTimeImmutable) {
                $lastAuditedAt = $lastAuditedCandidate;
            }
        }

        $components[$index]['last_audited_display'] = $lastAuditedAt instanceof \DateTimeImmutable ? $lastAuditedAt->format('d-m-Y H:i') : '';
        $components[$index]['last_audited_duration'] = $lastAuditedAt instanceof \DateTimeImmutable ? formatComponentDuration($lastAuditedAt, $now) : null;

        $maintenanceIntervalDays = $component['maintenance_interval_days'] !== null ? (int) $component['maintenance_interval_days'] : null;
        $components[$index]['maintenance_interval_days_int'] = $maintenanceIntervalDays;
        $maintenanceStatus = 'none';
        $nextMaintenanceDue = null;
        $maintenanceReferenceLabel = null;

        if ($maintenanceIntervalDays !== null && $maintenanceIntervalDays > 0) {
            $referenceDate = $lastAuditedAt instanceof \DateTimeImmutable ? $lastAuditedAt : $installedAt;
            if ($referenceDate instanceof \DateTimeImmutable) {
                $nextMaintenanceDue = $referenceDate->modify('+' . $maintenanceIntervalDays . ' days');
                if ($nextMaintenanceDue <= $now) {
                    $maintenanceStatus = 'overdue';
                    $componentSummary['maintenance_due']++;
                } elseif ($nextMaintenanceDue <= $maintenanceAttentionThreshold) {
                    $maintenanceStatus = 'due_soon';
                    $componentSummary['maintenance_upcoming']++;
                } else {
                    $maintenanceStatus = 'scheduled';
                }
                $maintenanceReferenceLabel = $lastAuditedAt instanceof \DateTimeImmutable ? 'Laatste controle' : 'Plaatsingsdatum';
            } else {
                $maintenanceStatus = 'scheduled';
            }
        }

        $components[$index]['next_maintenance_due_display'] = $nextMaintenanceDue instanceof \DateTimeImmutable ? $nextMaintenanceDue->format('d-m-Y') : '';
        $components[$index]['maintenance_status'] = $maintenanceStatus;
$components[$index]['maintenance_reference_label'] = $maintenanceReferenceLabel;

        $auditStatus = $isActive ? 'ok' : 'archived';
        $auditLabel = '';

        if ($isActive) {
            if (!($lastAuditedAt instanceof \DateTimeImmutable)) {
                $auditStatus = 'missing';
                $auditLabel = 'Nog geen controle geregistreerd';
                $componentSummary['audit_missing']++;
                $componentInsights['never_audited']++;
            } else {
                if ($nextMaintenanceDue instanceof \DateTimeImmutable) {
                    if ($nextMaintenanceDue <= $now) {
                        $auditStatus = 'overdue';
                        $componentSummary['audit_overdue']++;
                        $componentInsights['audit_overdue']++;
                    } elseif ($nextMaintenanceDue <= $maintenanceAttentionThreshold) {
                        $auditStatus = 'due_soon';
                        $componentSummary['audit_due_soon']++;
                        $componentInsights['audit_due_soon']++;
                    }
                } elseif ($lastAuditedAt <= $auditRecencyThreshold) {
                    $auditStatus = 'stale';
                    $componentSummary['audit_due_soon']++;
                    $componentInsights['audit_due_soon']++;
                }

                if ($auditStatus === 'overdue' && $nextMaintenanceDue instanceof \DateTimeImmutable) {
                    $auditLabel = 'Controle verlopen op ' . $nextMaintenanceDue->format('d-m-Y');
                    $overdueDuration = formatComponentDuration($nextMaintenanceDue, $now);
                    if ($overdueDuration !== '') {
                        $auditLabel .= ' (' . $overdueDuration . ' geleden)';
                    }
                } elseif ($auditStatus === 'due_soon' && $nextMaintenanceDue instanceof \DateTimeImmutable) {
                    $auditLabel = 'Controle gepland rond ' . $nextMaintenanceDue->format('d-m-Y');
                } elseif ($auditStatus === 'stale' && $lastAuditedAt instanceof \DateTimeImmutable) {
                    $auditLabel = 'Laatste controle ' . formatComponentDuration($lastAuditedAt, $now) . ' geleden';
                }
            }
        }

        if ($auditStatus === 'missing' && $auditLabel === '') {
            $auditLabel = 'Nog geen controle geregistreerd';
        }

        $components[$index]['audit_status'] = $auditStatus;
        $components[$index]['audit_label'] = $auditLabel;
    }
}

$componentSummary['total'] = count($components);
$componentSummary['warranty_attention_total'] = $componentSummary['warranty_expiring'] + $componentSummary['warranty_expired'];
$componentSummary['maintenance_attention_total'] = $componentSummary['maintenance_due'] + $componentSummary['maintenance_upcoming'];
$componentSummary['audit_attention_total'] = $componentSummary['audit_missing'] + $componentSummary['audit_overdue'];

if ($ageSampleCount > 0) {
    $componentInsights['average_age_days'] = (int) round($ageTotalDays / $ageSampleCount);
}

if ($oldestComponent !== null && isset($oldestComponent['installed_at']) && $oldestComponent['installed_at'] instanceof \DateTimeImmutable) {
    $componentInsights['oldest_component'] = [
        'name' => $oldestComponent['name'],
        'category' => $oldestComponent['category'],
        'installed_at' => $oldestComponent['installed_at']->format('d-m-Y'),
        'age' => formatComponentDuration($oldestComponent['installed_at'], $now),
        'is_active' => $oldestComponent['is_active'],
    ];
}

if ($newestComponent !== null && isset($newestComponent['installed_at']) && $newestComponent['installed_at'] instanceof \DateTimeImmutable) {
    $componentInsights['newest_component'] = [
        'name' => $newestComponent['name'],
        'category' => $newestComponent['category'],
        'installed_at' => $newestComponent['installed_at']->format('d-m-Y'),
        'age' => formatComponentDuration($newestComponent['installed_at'], $now),
        'is_active' => $newestComponent['is_active'],
    ];
}

$componentSuggestions = [];
foreach ($componentSuggestionSets as $key => $set) {
    $componentSuggestions[$key] = array_keys($set);
}

$suggestionMap = [
    'component_name' => 'names',
    'manufacturer' => 'manufacturers',
    'model' => 'models',
    'supplier' => 'suppliers',
    'inventory_location' => 'locations',
];

foreach ($suggestionMap as $column => $key) {
    if (!isset($componentSuggestions[$key])) {
        $componentSuggestions[$key] = [];
    }
    if (!empty($globalComponentSuggestions[$column])) {
        $componentSuggestions[$key] = array_values(array_unique(array_merge(
            $componentSuggestions[$key],
            $globalComponentSuggestions[$column]
        )));
    }
}

$componentTopCategories = [];
if ($categoryBreakdown !== []) {
    $sortedCategories = $categoryBreakdown;
    uasort($sortedCategories, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);
    $componentTopCategories = array_slice($sortedCategories, 0, 4, true);
}

$componentInsightItemsAvailable = $componentSummary['total'] > 0 && (
    $componentInsights['average_age_days'] !== null
    || $componentInsights['oldest_component'] !== null
    || $componentInsights['newest_component'] !== null
    || $componentInsights['missing_serial'] > 0
    || $componentInsights['missing_supplier'] > 0
    || $componentInsights['never_audited'] > 0
    || $componentInsights['audit_overdue'] > 0
    || $componentInsights['audit_due_soon'] > 0
    || $componentTopCategories !== []
);

$eventTypes = [
    'diagnose' => 'Diagnose',
    'component' => 'Onderdeel vervangen',
    'status' => 'Status update',
    'note' => 'Opmerking',
];

$deviceTypeSuggestions = [
    'Desktop PC',
    'Laptop',
    'Workstation',
    'Server',
    'Smartphone',
    'Tablet',
    'Gameconsole',
    'Netwerkapparaat',
    'All-in-one',
    'Overig',
];

$componentCategories = [
    'cpu' => 'Processor (CPU)',
    'gpu' => 'Grafische kaart (GPU)',
    'motherboard' => 'Moederbord',
    'memory' => 'Geheugen (RAM)',
    'storage' => 'Opslag (SSD/HDD)',
    'psu' => 'Voeding (PSU)',
    'cooling' => 'Koeling',
    'display' => 'Scherm / Display',
    'battery' => 'Batterij / Accu',
    'network' => 'Netwerkkaart / Modem',
    'peripheral' => 'Randapparatuur',
    'other' => 'Overig',
];

$componentCategoryOptions = array_values($componentCategories);
foreach (array_keys($categoryLabelsSet) as $label) {
    if ($label !== '' && !in_array($label, $componentCategoryOptions, true)) {
        $componentCategoryOptions[] = $label;
    }
}

$componentFilterValues = [
    'status' => 'all',
    'attention' => 'all',
    'category' => '',
    'search' => '',
];

$statusFilterParam = isset($_GET['component_status']) ? (string) $_GET['component_status'] : '';
if (in_array($statusFilterParam, ['all', 'active', 'archived'], true)) {
    $componentFilterValues['status'] = $statusFilterParam;
}

$attentionFilterParam = isset($_GET['component_attention']) ? (string) $_GET['component_attention'] : '';
if (in_array($attentionFilterParam, ['all', 'warranty', 'maintenance', 'audit'], true)) {
    $componentFilterValues['attention'] = $attentionFilterParam;
}

$categoryFilterParam = isset($_GET['component_category_filter']) ? (string) $_GET['component_category_filter'] : '';
if ($categoryFilterParam !== '' && in_array($categoryFilterParam, $componentCategoryOptions, true)) {
    $componentFilterValues['category'] = $categoryFilterParam;
}

$searchFilterParam = isset($_GET['component_search']) ? trim((string) $_GET['component_search']) : '';
if ($searchFilterParam !== '') {
    $componentFilterValues['search'] = $searchFilterParam;
}

$componentSearchTerm = $componentFilterValues['search'] !== '' ? mb_strtolower($componentFilterValues['search']) : '';

$filteredComponents = array_values(array_filter($components, static function (array $component) use ($componentFilterValues, $componentSearchTerm): bool {
    $isActive = isset($component['is_active']) ? (bool) $component['is_active'] : empty($component['removed_at']);

    if ($componentFilterValues['status'] === 'active' && !$isActive) {
        return false;
    }

    if ($componentFilterValues['status'] === 'archived' && $isActive) {
        return false;
    }

    if ($componentFilterValues['category'] !== '' && (string) ($component['category'] ?? '') !== $componentFilterValues['category']) {
        return false;
    }

    switch ($componentFilterValues['attention']) {
        case 'warranty':
            if (!in_array($component['warranty_status'] ?? 'none', ['expired', 'expiring'], true)) {
                return false;
            }
            break;
        case 'maintenance':
            if (!in_array($component['maintenance_status'] ?? 'none', ['overdue', 'due_soon'], true)) {
                return false;
            }
            break;
        case 'audit':
            if (!in_array($component['audit_status'] ?? 'ok', ['missing', 'overdue', 'due_soon', 'stale'], true)) {
                return false;
            }
            break;
    }

    if ($componentSearchTerm !== '') {
        $haystacks = [
            (string) ($component['component_name'] ?? ''),
            (string) ($component['manufacturer'] ?? ''),
            (string) ($component['model'] ?? ''),
            (string) ($component['serial_number'] ?? ''),
            (string) ($component['category'] ?? ''),
            (string) ($component['asset_tag'] ?? ''),
            (string) ($component['inventory_location'] ?? ''),
            (string) ($component['notes'] ?? ''),
        ];

        $matchesSearch = false;
        foreach ($haystacks as $haystack) {
            if ($haystack !== '' && mb_stripos($haystack, $componentSearchTerm) !== false) {
                $matchesSearch = true;
                break;
            }
        }

        if (!$matchesSearch) {
            return false;
        }
    }

    return true;
}));

$componentFiltersApplied = $componentFilterValues['status'] !== 'all'
    || $componentFilterValues['attention'] !== 'all'
    || $componentFilterValues['category'] !== ''
    || $componentFilterValues['search'] !== '';

$filteredComponentCount = count($filteredComponents);
$componentFilterSummary = '';
if ($components !== []) {
    $componentFilterSummary = 'Toont ' . $filteredComponentCount . ' van ' . count($components) . ' componenten';
}

$eventErrors = [];
$deviceFormErrors = [];
$componentFormErrors = [];
$componentActionErrors = [];
$generalErrors = [];

$componentFormValues = [
    'component_category' => '',
    'component_category_custom' => '',
    'component_name' => '',
    'component_manufacturer' => '',
    'component_model' => '',
    'component_serial' => '',
    'component_specifications' => '',
    'component_notes' => '',
    'component_installed_at' => '',
    'component_asset_tag' => '',
    'component_supplier' => '',
    'component_purchase_reference' => '',
    'component_purchase_cost' => '',
    'component_inventory_location' => '',
    'component_condition' => '',
    'component_warranty_expires_at' => '',
    'component_maintenance_interval_days' => '',
    'component_last_audited_at' => '',
];

$deviceFormValues = [
    'brand' => (string) ($device['brand'] ?? ''),
    'model' => (string) ($device['model'] ?? ''),
    'serial_number' => (string) ($device['serial_number'] ?? ''),
    'device_type' => (string) ($device['device_type'] ?? ''),
    'notes' => (string) ($device['notes'] ?? ''),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    try {
        if (!Csrf::validate($_POST['csrf_token'] ?? '')) {
            throw new ValidationException(['general' => 'Ongeldige sessie, probeer opnieuw.']);
        }

        switch ($action) {
            case 'update-device':
                $deviceFormValues['brand'] = InputValidator::requireString($_POST, 'brand', 120);
                $deviceFormValues['model'] = InputValidator::optionalString($_POST, 'model', 191);
                $deviceFormValues['serial_number'] = InputValidator::optionalString($_POST, 'serial_number', 120);
                $deviceFormValues['device_type'] = InputValidator::optionalString($_POST, 'device_type', 120);
                $deviceFormValues['notes'] = InputValidator::optionalString($_POST, 'notes', 1000);

                $deviceRepository->updateDeviceProfile(
                    $deviceId,
                    $deviceFormValues['brand'],
                    $deviceFormValues['model'] !== '' ? $deviceFormValues['model'] : null,
                    $deviceFormValues['serial_number'] !== '' ? $deviceFormValues['serial_number'] : null,
                    $deviceFormValues['device_type'] !== '' ? $deviceFormValues['device_type'] : null,
                    $deviceFormValues['notes'] !== '' ? $deviceFormValues['notes'] : null
                );

                Response::redirect('device.php?id=' . $deviceId . '&device_updated=1');
                break;

            case 'add-component':
                $componentFormValues['component_category'] = InputValidator::requireString($_POST, 'component_category', 64);
                $categoryKey = $componentFormValues['component_category'];

                if ($categoryKey === 'other') {
                    $componentFormValues['component_category_custom'] = InputValidator::requireString($_POST, 'component_category_custom', 120);
                    $categoryLabel = $componentFormValues['component_category_custom'];
                } elseif (isset($componentCategories[$categoryKey])) {
                    $categoryLabel = $componentCategories[$categoryKey];
                    $componentFormValues['component_category_custom'] = '';
                } else {
                    throw new ValidationException(['component_category' => 'Selecteer een geldige categorie.']);
                }

                $componentFormValues['component_name'] = InputValidator::requireString($_POST, 'component_name', 191);
                $componentFormValues['component_manufacturer'] = InputValidator::optionalString($_POST, 'component_manufacturer', 120);
                $componentFormValues['component_model'] = InputValidator::optionalString($_POST, 'component_model', 191);
                $componentFormValues['component_serial'] = InputValidator::optionalString($_POST, 'component_serial', 120);
                $componentFormValues['component_specifications'] = InputValidator::optionalString($_POST, 'component_specifications', 500);
                $componentFormValues['component_notes'] = InputValidator::optionalString($_POST, 'component_notes', 500);
                $componentFormValues['component_asset_tag'] = InputValidator::optionalString($_POST, 'component_asset_tag', 120);
                $componentFormValues['component_supplier'] = InputValidator::optionalString($_POST, 'component_supplier', 191);
                $componentFormValues['component_purchase_reference'] = InputValidator::optionalString($_POST, 'component_purchase_reference', 191);
                $componentFormValues['component_purchase_cost'] = InputValidator::optionalString($_POST, 'component_purchase_cost', 64);
                $componentFormValues['component_inventory_location'] = InputValidator::optionalString($_POST, 'component_inventory_location', 191);
                $componentFormValues['component_condition'] = InputValidator::optionalString($_POST, 'component_condition', 64);

                $installedAtInput = trim((string) ($_POST['component_installed_at'] ?? ''));
                $componentFormValues['component_installed_at'] = htmlspecialchars($installedAtInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $installedAt = null;
                if ($installedAtInput !== '') {
                    $normalized = str_replace('T', ' ', $installedAtInput);
                    $dateTime = date_create_immutable($normalized);
                    if ($dateTime === false) {
                        throw new ValidationException(['component_installed_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $installedAt = $dateTime->format('Y-m-d H:i:s');
                }

                $warrantyInput = trim((string) ($_POST['component_warranty_expires_at'] ?? ''));
                $componentFormValues['component_warranty_expires_at'] = htmlspecialchars($warrantyInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $warrantyExpiresAt = null;
                if ($warrantyInput !== '') {
                    $warrantyDate = date_create_immutable($warrantyInput);
                    if ($warrantyDate === false) {
                        throw new ValidationException(['component_warranty_expires_at' => 'Ongeldige datum opgegeven.']);
                    }
                    $warrantyExpiresAt = $warrantyDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
                }

                $maintenanceIntervalInput = trim((string) ($_POST['component_maintenance_interval_days'] ?? ''));
                $componentFormValues['component_maintenance_interval_days'] = htmlspecialchars($maintenanceIntervalInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $maintenanceIntervalDays = null;
                if ($maintenanceIntervalInput !== '') {
                    $maintenanceIntervalValue = filter_var(
                        $maintenanceIntervalInput,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 3650]]
                    );
                    if ($maintenanceIntervalValue === false) {
                        throw new ValidationException(['component_maintenance_interval_days' => 'Geef een geldig aantal dagen op (minimaal 1).']);
                    }
                    $maintenanceIntervalDays = (int) $maintenanceIntervalValue;
                }

                $lastAuditedInput = trim((string) ($_POST['component_last_audited_at'] ?? ''));
                $componentFormValues['component_last_audited_at'] = htmlspecialchars($lastAuditedInput, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $lastAuditedAt = null;
                if ($lastAuditedInput !== '') {
                    $normalizedAudit = str_replace('T', ' ', $lastAuditedInput);
                    $auditDate = date_create_immutable($normalizedAudit);
                    if ($auditDate === false) {
                        throw new ValidationException(['component_last_audited_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $lastAuditedAt = $auditDate->format('Y-m-d H:i:s');
                }

                $componentRepository->add(
                    $deviceId,
                    $categoryLabel,
                    $componentFormValues['component_name'],
                    $componentFormValues['component_manufacturer'] !== '' ? $componentFormValues['component_manufacturer'] : null,
                    $componentFormValues['component_model'] !== '' ? $componentFormValues['component_model'] : null,
                    $componentFormValues['component_serial'] !== '' ? $componentFormValues['component_serial'] : null,
                    $componentFormValues['component_specifications'] !== '' ? $componentFormValues['component_specifications'] : null,
                    $componentFormValues['component_notes'] !== '' ? $componentFormValues['component_notes'] : null,
                    $installedAt,
                    $componentFormValues['component_asset_tag'] !== '' ? $componentFormValues['component_asset_tag'] : null,
                    $componentFormValues['component_supplier'] !== '' ? $componentFormValues['component_supplier'] : null,
                    $componentFormValues['component_purchase_reference'] !== '' ? $componentFormValues['component_purchase_reference'] : null,
                    $componentFormValues['component_purchase_cost'] !== '' ? $componentFormValues['component_purchase_cost'] : null,
                    $componentFormValues['component_inventory_location'] !== '' ? $componentFormValues['component_inventory_location'] : null,
                    $componentFormValues['component_condition'] !== '' ? $componentFormValues['component_condition'] : null,
                    $warrantyExpiresAt,
                    $maintenanceIntervalDays,
                    $lastAuditedAt
                );

                Response::redirect('device.php?id=' . $deviceId . '&component_added=1');
                break;

            case 'retire-component':
                $componentId = filter_var($_POST['component_id'] ?? null, FILTER_VALIDATE_INT);
                if ($componentId === false || $componentId === null) {
                    throw new ValidationException(['component' => 'Ongeldig component geselecteerd.']);
                }

                $component = $componentRepository->find((int) $componentId);
                if ($component === null || (int) $component['device_id'] !== $deviceId) {
                    throw new ValidationException(['component' => 'Component hoort niet bij dit apparaat.']);
                }

                if (!empty($component['removed_at'])) {
                    throw new ValidationException(['component' => 'Dit component is al gearchiveerd.']);
                }

                $removalReason = InputValidator::optionalString($_POST, 'removal_reason', 500);
                $componentRepository->retire((int) $componentId, $removalReason !== '' ? $removalReason : null);

                Response::redirect('device.php?id=' . $deviceId . '&component_retired=1');
                break;

            case 'replace-component':
                $componentId = filter_var($_POST['component_id'] ?? null, FILTER_VALIDATE_INT);
                if ($componentId === false || $componentId === null) {
                    throw new ValidationException(['component' => 'Ongeldig component geselecteerd.']);
                }

                $component = $componentRepository->find((int) $componentId);
                if ($component === null || (int) $component['device_id'] !== $deviceId) {
                    throw new ValidationException(['component' => 'Component hoort niet bij dit apparaat.']);
                }

                if (!empty($component['removed_at'])) {
                    throw new ValidationException(['component' => 'Dit component is al gearchiveerd.']);
                }

                $replacementCategoryKey = InputValidator::requireString($_POST, 'replacement_category', 64);
                if ($replacementCategoryKey === 'other') {
                    $replacementCategoryLabel = InputValidator::requireString($_POST, 'replacement_category_custom', 120);
                } elseif (isset($componentCategories[$replacementCategoryKey])) {
                    $replacementCategoryLabel = $componentCategories[$replacementCategoryKey];
                } else {
                    throw new ValidationException(['replacement_category' => 'Selecteer een geldige categorie.']);
                }

                $replacementName = InputValidator::requireString($_POST, 'replacement_name', 191);
                $replacementManufacturer = InputValidator::optionalString($_POST, 'replacement_manufacturer', 120);
                $replacementModel = InputValidator::optionalString($_POST, 'replacement_model', 191);
                $replacementSerial = InputValidator::optionalString($_POST, 'replacement_serial', 120);
                $replacementSpecs = InputValidator::optionalString($_POST, 'replacement_specifications', 500);
                $replacementNotes = InputValidator::optionalString($_POST, 'replacement_notes', 500);
                $replacementAssetTag = InputValidator::optionalString($_POST, 'replacement_asset_tag', 120);
                $replacementSupplier = InputValidator::optionalString($_POST, 'replacement_supplier', 191);
                $replacementPurchaseReference = InputValidator::optionalString($_POST, 'replacement_purchase_reference', 191);
                $replacementPurchaseCost = InputValidator::optionalString($_POST, 'replacement_purchase_cost', 64);
                $replacementInventoryLocation = InputValidator::optionalString($_POST, 'replacement_inventory_location', 191);
                $replacementCondition = InputValidator::optionalString($_POST, 'replacement_condition', 64);
                $removalReason = InputValidator::optionalString($_POST, 'replacement_removal_reason', 500);

                $replacementInstalledAtInput = trim((string) ($_POST['replacement_installed_at'] ?? ''));
                $replacementInstalledAt = null;
                if ($replacementInstalledAtInput !== '') {
                    $normalizedReplacement = str_replace('T', ' ', $replacementInstalledAtInput);
                    $replacementDate = date_create_immutable($normalizedReplacement);
                    if ($replacementDate === false) {
                        throw new ValidationException(['replacement_installed_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $replacementInstalledAt = $replacementDate->format('Y-m-d H:i:s');
                }

                $replacementWarrantyInput = trim((string) ($_POST['replacement_warranty_expires_at'] ?? ''));
                $replacementWarrantyExpiresAt = null;
                if ($replacementWarrantyInput !== '') {
                    $replacementWarrantyDate = date_create_immutable($replacementWarrantyInput);
                    if ($replacementWarrantyDate === false) {
                        throw new ValidationException(['replacement_warranty_expires_at' => 'Ongeldige datum opgegeven.']);
                    }
                    $replacementWarrantyExpiresAt = $replacementWarrantyDate->setTime(23, 59, 59)->format('Y-m-d H:i:s');
                }

                $replacementMaintenanceIntervalInput = trim((string) ($_POST['replacement_maintenance_interval_days'] ?? ''));
                $replacementMaintenanceIntervalDays = null;
                if ($replacementMaintenanceIntervalInput !== '') {
                    $replacementMaintenanceIntervalValue = filter_var(
                        $replacementMaintenanceIntervalInput,
                        FILTER_VALIDATE_INT,
                        ['options' => ['min_range' => 1, 'max_range' => 3650]]
                    );
                    if ($replacementMaintenanceIntervalValue === false) {
                        throw new ValidationException(['replacement_maintenance_interval_days' => 'Geef een geldig aantal dagen op (minimaal 1).']);
                    }
                    $replacementMaintenanceIntervalDays = (int) $replacementMaintenanceIntervalValue;
                }

                $replacementLastAuditedInput = trim((string) ($_POST['replacement_last_audited_at'] ?? ''));
                $replacementLastAuditedAt = null;
                if ($replacementLastAuditedInput !== '') {
                    $normalizedReplacementAudit = str_replace('T', ' ', $replacementLastAuditedInput);
                    $replacementAuditDate = date_create_immutable($normalizedReplacementAudit);
                    if ($replacementAuditDate === false) {
                        throw new ValidationException(['replacement_last_audited_at' => 'Ongeldige datum/tijd opgegeven.']);
                    }
                    $replacementLastAuditedAt = $replacementAuditDate->format('Y-m-d H:i:s');
                }

                $pdo->beginTransaction();
                try {
                    $newComponentId = $componentRepository->add(
                        $deviceId,
                        $replacementCategoryLabel,
                        $replacementName,
                        $replacementManufacturer !== '' ? $replacementManufacturer : null,
                        $replacementModel !== '' ? $replacementModel : null,
                        $replacementSerial !== '' ? $replacementSerial : null,
                        $replacementSpecs !== '' ? $replacementSpecs : null,
                        $replacementNotes !== '' ? $replacementNotes : null,
                        $replacementInstalledAt,
                        $replacementAssetTag !== '' ? $replacementAssetTag : null,
                        $replacementSupplier !== '' ? $replacementSupplier : null,
                        $replacementPurchaseReference !== '' ? $replacementPurchaseReference : null,
                        $replacementPurchaseCost !== '' ? $replacementPurchaseCost : null,
                        $replacementInventoryLocation !== '' ? $replacementInventoryLocation : null,
                        $replacementCondition !== '' ? $replacementCondition : null,
                        $replacementWarrantyExpiresAt,
                        $replacementMaintenanceIntervalDays,
                        $replacementLastAuditedAt
                    );

                    $componentRepository->retire((int) $componentId, $removalReason !== '' ? $removalReason : null, $newComponentId);
                    $pdo->commit();
                } catch (\Throwable $throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    throw $throwable;
                }

                Response::redirect('device.php?id=' . $deviceId . '&component_replaced=1');
                break;

            case 'add-event':
                $caseId = filter_var($_POST['case_id'] ?? null, FILTER_VALIDATE_INT);
                $eventType = InputValidator::requireString($_POST, 'event_type', 64);
                $description = InputValidator::requireString($_POST, 'description', 1000);
                $componentName = InputValidator::optionalString($_POST, 'component', 191);
                $statusUpdate = InputValidator::optionalString($_POST, 'status_update', 64);
                $costs = InputValidator::optionalString($_POST, 'costs', 64);

                if (!array_key_exists($eventType, $eventTypes)) {
                    throw new ValidationException(['event_type' => 'Ongeldig type.']);
                }

                if ($caseId !== false && $caseId !== null && !isset($caseOptions[(int) $caseId])) {
                    throw new ValidationException(['case_id' => 'Onbekende case geselecteerd.']);
                }

                $metadata = [];
                if ($componentName !== '') {
                    $metadata['component'] = $componentName;
                }
                if ($costs !== '') {
                    $metadata['costs'] = $costs;
                }
                if ($statusUpdate !== '') {
                    $metadata['status'] = $statusUpdate;
                }

                $selectedCaseId = $caseId ? (int) $caseId : (isset($cases[0]['id']) ? (int) $cases[0]['id'] : null);

                $repairEventRepository->log(
                    $deviceId,
                    $selectedCaseId,
                    $eventType,
                    $description,
                    Auth::username(),
                    $metadata
                );

                if ($selectedCaseId !== null) {
                    if ($statusUpdate !== '') {
                        $caseRepository->updateStatus($selectedCaseId, $statusUpdate);
                    }

                    $noteBody = 'Reparatie-activiteit (' . $eventTypes[$eventType] . '): ' . $description;
                    if ($componentName !== '') {
                        $noteBody .= ' | Component: ' . $componentName;
                    }
                    if ($costs !== '') {
                        $noteBody .= ' | Kosten/onderdeel: ' . $costs;
                    }
                    $noteRepository->add($selectedCaseId, (int) $device['customer_id'], Auth::username(), $noteBody);
                }

                Response::redirect('device.php?id=' . $deviceId . '&event_added=1');
                break;

            default:
                throw new ValidationException(['general' => 'Onbekende actie.']);
        }
    } catch (ValidationException $exception) {
        $errorBag = $exception->errors();
        switch ($action) {
            case 'update-device':
                $deviceFormErrors = $errorBag;
                break;
            case 'add-component':
                $componentFormErrors = $errorBag;
                break;
            case 'retire-component':
            case 'replace-component':
                $componentId = isset($_POST['component_id']) ? (int) $_POST['component_id'] : 0;
                $componentActionErrors[$componentId] = $errorBag;
                if ($componentId === 0 && !empty($errorBag['component'])) {
                    $generalErrors[] = $errorBag['component'];
                }
                break;
            case 'add-event':
                $eventErrors = $errorBag;
                break;
            default:
                if (!empty($errorBag['general'])) {
                    $generalErrors[] = $errorBag['general'];
                }
                break;
        }
    } catch (\Throwable $throwable) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $generalErrors[] = 'Er is een onverwachte fout opgetreden bij het verwerken van het formulier.';
    }
}

$csrfToken = Csrf::token();
$created = isset($_GET['created']);
$eventAdded = isset($_GET['event_added']);
$deviceUpdated = isset($_GET['device_updated']);
$componentAdded = isset($_GET['component_added']);
$componentReplaced = isset($_GET['component_replaced']);
$componentRetired = isset($_GET['component_retired']);
$selectedCasePost = isset($_POST['case_id']) ? (string) $_POST['case_id'] : '';
$selectedEventTypePost = isset($_POST['event_type']) ? (string) $_POST['event_type'] : '';
?>
<!DOCTYPE html>
<html lang="nl">
<head>
  <meta charset="UTF-8">
  <title>Apparaat <?= htmlspecialchars((string) $barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> - Digivriend</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet" href="css/theme.css">
  <link rel="stylesheet" href="css/devices.css">
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
        <ul>
          <li><a href="index.php">Dashboard</a></li>
          <li><a href="devices.php" aria-current="page">Klanten &amp; apparaten</a></li>
          <li><a href="ophaalbevestiging.php">Ophaalbevestiging</a></li>
          <li><a href="reparatie-onderzoek.php">Reparatie &amp; Onderzoek</a></li>
          <li><a href="data-recovery.php">Data Recovery</a></li>
          <li><a href="klant-melding.php">Klant Melding</a></li>
          <li><a href="documents.php">Documenten</a></li>
          <li class="main-nav__spacer" aria-hidden="true"></li>
          <li><a href="logout.php" class="btn btn--ghost">Afmelden</a></li>
        </ul>
      </nav>
    </div>
  </header>
  <main class="container">
    <a href="devices.php" class="back-link">&larr; Terug naar overzicht</a>
    <div class="page-header">
      <div>
        <h1><?= htmlspecialchars(trim((string) ($device['brand'] ?? '') . ' ' . ($device['model'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
        <p class="page-intro">Barcode: <code><?= htmlspecialchars($barcode, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></code></p>
      </div>
      <a href="device-barcode.php?id=<?= $deviceId ?>" class="btn btn--secondary" target="_blank" rel="noopener">Print barcode</a>
    </div>

    <?php if ($created): ?>
      <div class="alert alert--success">Intake succesvol geregistreerd. Barcode en dossier zijn aangemaakt.</div>
    <?php endif; ?>
    <?php if ($deviceUpdated): ?>
      <div class="alert alert--success">Apparaatgegevens bijgewerkt.</div>
    <?php endif; ?>
    <?php if ($componentAdded): ?>
      <div class="alert alert--success">Nieuw component opgeslagen.</div>
    <?php endif; ?>
    <?php if ($componentReplaced): ?>
      <div class="alert alert--success">Component vervangen en historiek bijgewerkt.</div>
    <?php endif; ?>
    <?php if ($componentRetired): ?>
      <div class="alert alert--success">Component gearchiveerd.</div>
    <?php endif; ?>
    <?php if ($eventAdded): ?>
      <div class="alert alert--success">Nieuw reparatiemoment opgeslagen.</div>
    <?php endif; ?>
    <?php foreach ($generalErrors as $message): ?>
      <div class="alert alert--danger"><?= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <section class="card">
      <h2>Klantgegevens</h2>
      <dl class="data-list">
        <div>
          <dt>Naam</dt>
          <dd><?= htmlspecialchars((string) $device['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <?php if (!empty($device['email'])): ?>
          <div>
            <dt>E-mail</dt>
            <dd><a href="mailto:<?= htmlspecialchars((string) $device['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) $device['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['phone'])): ?>
          <div>
            <dt>Telefoon</dt>
            <dd><a href="tel:<?= htmlspecialchars((string) $device['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"><?= htmlspecialchars((string) $device['phone'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['address']) || !empty($device['postal_code']) || !empty($device['city'])): ?>
          <div>
            <dt>Adres</dt>
            <dd><?= htmlspecialchars(trim(($device['address'] ?? '') . ' ' . ($device['postal_code'] ?? '') . ' ' . ($device['city'] ?? '')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
      </dl>
    </section>

    <section class="card">
      <h2>Apparaatgegevens</h2>
      <dl class="data-list">
        <div>
          <dt>Merk</dt>
          <dd><?= htmlspecialchars((string) ($device['brand'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <div>
          <dt>Model</dt>
          <dd><?= htmlspecialchars((string) ($device['model'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
        </div>
        <?php if (!empty($device['device_type'])): ?>
          <div>
            <dt>Type</dt>
            <dd><?= htmlspecialchars((string) $device['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['serial_number'])): ?>
          <div>
            <dt>Serienummer</dt>
            <dd><?= htmlspecialchars((string) $device['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
          </div>
        <?php endif; ?>
        <?php if (!empty($device['notes'])): ?>
          <div>
            <dt>Notities</dt>
            <dd><?= nl2br(htmlspecialchars((string) $device['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
          </div>
        <?php endif; ?>
      </dl>
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="device-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="update-device">
        <div class="form-grid">
          <label>
            Merk*
            <input type="text" name="brand" value="<?= htmlspecialchars($deviceFormValues['brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" required>
            <?php if (!empty($deviceFormErrors['brand'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['brand'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Model
            <input type="text" name="model" value="<?= htmlspecialchars($deviceFormValues['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($deviceFormErrors['model'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Serienummer
            <input type="text" name="serial_number" value="<?= htmlspecialchars($deviceFormValues['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($deviceFormErrors['serial_number'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Type apparaat
            <input type="text" name="device_type" list="device-type-options" value="<?= htmlspecialchars($deviceFormValues['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. Laptop">
            <?php if (!empty($deviceFormErrors['device_type'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['device_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
        </div>
        <datalist id="device-type-options">
          <?php foreach ($deviceTypeSuggestions as $suggestion): ?>
            <option value="<?= htmlspecialchars($suggestion, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>"></option>
          <?php endforeach; ?>
        </datalist>
        <label>
          Interne notities
          <textarea name="notes" rows="3"><?= htmlspecialchars($deviceFormValues['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($deviceFormErrors['notes'])): ?><span class="form-error"><?= htmlspecialchars($deviceFormErrors['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--secondary">Opslaan</button>
        </div>
      </form>
      <div class="barcode-preview">
        <img src="device-barcode.php?id=<?= $deviceId ?>" alt="Barcode" loading="lazy">
      </div>
    </section>

    <section class="card">
      <h2>Hardwarecomponenten</h2>
      <p>Leg alle aanwezige hardwarecomponenten vast, markeer vervangen onderdelen en bewaak zo de volledige servicegeschiedenis.</p>
      <?php if ($componentSummary['total'] > 0): ?>
        <div class="component-overview" aria-live="polite">
          <div class="component-overview__item">
            <span class="component-overview__label">Actieve componenten</span>
            <span class="component-overview__value"><?= (int) $componentSummary['active'] ?></span>
            <span class="component-overview__meta">van <?= (int) $componentSummary['total'] ?> totaal</span>
          </div>
          <div class="component-overview__item">
            <span class="component-overview__label">Gearchiveerd</span>
            <span class="component-overview__value"><?= (int) $componentSummary['archived'] ?></span>
            <span class="component-overview__meta">historiek beschikbaar</span>
          </div>
          <div class="component-overview__item<?= $componentSummary['warranty_attention_total'] > 0 ? ' component-overview__item--alert' : '' ?>">
            <span class="component-overview__label">Garantie aandacht</span>
            <span class="component-overview__value"><?= (int) $componentSummary['warranty_attention_total'] ?></span>
            <span class="component-overview__meta">
              <?php if ($componentSummary['warranty_attention_total'] === 0): ?>
                Alles op orde
              <?php else: ?>
                <?= (int) $componentSummary['warranty_expired'] ?> verlopen
              <?php endif; ?>
            </span>
          </div>
          <div class="component-overview__item<?= $componentSummary['maintenance_attention_total'] > 0 ? ' component-overview__item--alert' : '' ?>">
            <span class="component-overview__label">Onderhoud aandacht</span>
            <span class="component-overview__value"><?= (int) $componentSummary['maintenance_attention_total'] ?></span>
            <span class="component-overview__meta">
              <?php if ($componentSummary['maintenance_attention_total'] === 0): ?>
                Geen acties nodig
              <?php else: ?>
                <?= (int) $componentSummary['maintenance_due'] ?> achterstallig
              <?php endif; ?>
            </span>
          </div>
          <div class="component-overview__item<?= $componentSummary['audit_attention_total'] > 0 ? ' component-overview__item--alert' : '' ?>">
            <span class="component-overview__label">Controle aandacht</span>
            <span class="component-overview__value"><?= (int) $componentSummary['audit_attention_total'] ?></span>
            <span class="component-overview__meta">
              <?php if ($componentSummary['audit_attention_total'] === 0): ?>
                <?php if ($componentSummary['audit_due_soon'] > 0): ?>
                  <?= htmlspecialchars('Binnenkort voor ' . (int) $componentSummary['audit_due_soon'] . ' componenten', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                <?php else: ?>
                  Controles bijgewerkt
                <?php endif; ?>
              <?php else: ?>
                <?php
                  $auditMetaSegments = [];
                  if ($componentSummary['audit_missing'] > 0) {
                      $auditMetaSegments[] = (int) $componentSummary['audit_missing'] . ' zonder registratie';
                  }
                  if ($componentSummary['audit_overdue'] > 0) {
                      $auditMetaSegments[] = (int) $componentSummary['audit_overdue'] . ' verlopen';
                  }
                  if ($componentSummary['audit_due_soon'] > 0) {
                      $auditMetaSegments[] = (int) $componentSummary['audit_due_soon'] . ' bijna vereist';
                  }
                  echo htmlspecialchars($auditMetaSegments === [] ? 'Controle aandacht vereist' : implode(', ', $auditMetaSegments), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                ?>
              <?php endif; ?>
            </span>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($componentInsightItemsAvailable): ?>
        <div class="component-insights" aria-live="polite">
          <div>
            <h4>Componentinzichten</h4>
            <ul class="component-insights__list">
              <?php if ($componentInsights['average_age_days'] !== null): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Gemiddelde leeftijd</span>
                  <span>ongeveer <?= (int) $componentInsights['average_age_days'] ?> dagen</span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['oldest_component'] !== null): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Oudste component</span>
                  <span>
                    <?= htmlspecialchars((string) $componentInsights['oldest_component']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    (<?= htmlspecialchars((string) $componentInsights['oldest_component']['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                    sinds <?= htmlspecialchars((string) $componentInsights['oldest_component']['installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php if (!empty($componentInsights['oldest_component']['age'])): ?>
                      · <?= htmlspecialchars((string) $componentInsights['oldest_component']['age'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php endif; ?>
                    <?php if (isset($componentInsights['oldest_component']['is_active']) && !$componentInsights['oldest_component']['is_active']): ?>
                      (gearchiveerd)
                    <?php endif; ?>
                  </span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['newest_component'] !== null): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Meest recent geplaatst</span>
                  <span>
                    <?= htmlspecialchars((string) $componentInsights['newest_component']['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    (<?= htmlspecialchars((string) $componentInsights['newest_component']['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)
                    sinds <?= htmlspecialchars((string) $componentInsights['newest_component']['installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php if (!empty($componentInsights['newest_component']['age'])): ?>
                      · <?= htmlspecialchars((string) $componentInsights['newest_component']['age'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php endif; ?>
                  </span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['missing_serial'] > 0): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Serienummer ontbreekt</span>
                  <span><?= (int) $componentInsights['missing_serial'] ?> actieve componenten</span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['missing_supplier'] > 0): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Leverancier onbekend</span>
                  <span><?= (int) $componentInsights['missing_supplier'] ?> actieve componenten</span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['never_audited'] > 0): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Geen controle geregistreerd</span>
                  <span><?= (int) $componentInsights['never_audited'] ?> componenten</span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['audit_overdue'] > 0): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Controle vereist</span>
                  <span><?= (int) $componentInsights['audit_overdue'] ?> componenten verlopen</span>
                </li>
              <?php endif; ?>
              <?php if ($componentInsights['audit_due_soon'] > 0): ?>
                <li class="component-insights__item">
                  <span class="component-insights__label">Controle bijna vereist</span>
                  <span><?= (int) $componentInsights['audit_due_soon'] ?> componenten</span>
                </li>
              <?php endif; ?>
            </ul>
          </div>
          <?php if ($componentTopCategories !== []): ?>
            <div class="component-insights__categories">
              <h4>Belangrijkste categorieën</h4>
              <ul class="component-insights__chips">
                <?php foreach ($componentTopCategories as $label => $stats): ?>
                  <li>
                    <?= htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= (int) $stats['active'] ?> actief / <?= (int) $stats['total'] ?> totaal
                  </li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>
        </div>
      <?php endif; ?>

      <?php if ($componentSummary['total'] > 0): ?>
        <form method="get" class="component-filters" aria-label="Componentfilters">
          <input type="hidden" name="id" value="<?= $deviceId ?>">
          <div class="component-filters__grid">
            <label>
              <span>Status</span>
              <select name="component_status">
                <option value="all" <?= $componentFilterValues['status'] === 'all' ? 'selected' : '' ?>>Alle</option>
                <option value="active" <?= $componentFilterValues['status'] === 'active' ? 'selected' : '' ?>>Actief</option>
                <option value="archived" <?= $componentFilterValues['status'] === 'archived' ? 'selected' : '' ?>>Gearchiveerd</option>
              </select>
            </label>
            <label>
              <span>Aandacht</span>
              <select name="component_attention">
                <option value="all" <?= $componentFilterValues['attention'] === 'all' ? 'selected' : '' ?>>Alles</option>
                <option value="warranty" <?= $componentFilterValues['attention'] === 'warranty' ? 'selected' : '' ?>>Garantie</option>
                <option value="maintenance" <?= $componentFilterValues['attention'] === 'maintenance' ? 'selected' : '' ?>>Onderhoud</option>
                <option value="audit" <?= $componentFilterValues['attention'] === 'audit' ? 'selected' : '' ?>>Controle</option>
              </select>
            </label>
            <label>
              <span>Categorie</span>
              <select name="component_category_filter">
                <option value="">Alle categorieën</option>
                <?php foreach ($componentCategoryOptions as $categoryOption): ?>
                  <option value="<?= htmlspecialchars((string) $categoryOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $componentFilterValues['category'] === $categoryOption ? 'selected' : '' ?>><?= htmlspecialchars((string) $categoryOption, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>
              <span>Zoeken</span>
              <input type="search" name="component_search" value="<?= htmlspecialchars($componentFilterValues['search'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Zoek op naam, serienummer, locatie">
            </label>
          </div>
          <div class="component-filters__actions">
            <button type="submit" class="btn btn--ghost">Filters toepassen</button>
            <?php if ($componentFiltersApplied): ?>
              <a href="device.php?id=<?= $deviceId ?>" class="component-filters__reset">Filters wissen</a>
            <?php endif; ?>
            <?php if ($componentFilterSummary !== ''): ?>
              <span class="component-filters__meta"><?= htmlspecialchars($componentFilterSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            <?php endif; ?>
          </div>
        </form>
      <?php endif; ?>

      <?php if ($componentSummary['total'] === 0): ?>
        <p>Er zijn nog geen hardwarecomponenten geregistreerd.</p>
        <?php elseif ($filteredComponents === []): ?>
        <p>Geen componenten gevonden met de huidige filters.</p>
      <?php else: ?>
        <div class="component-list">
          <?php foreach ($filteredComponents as $component): ?>
            <?php
              $componentId = (int) $component['id'];
              $isActive = isset($component['is_active']) ? (bool) $component['is_active'] : empty($component['removed_at']);
              $componentStatus = $isActive ? 'Actief' : 'Gearchiveerd';
              $removedAtDisplay = '';
              if (!empty($component['removed_at'])) {
                  $removedAtDisplay = date('d-m-Y H:i', strtotime((string) $component['removed_at']));
              }
              $replacementName = (string) ($component['replacement_component_name'] ?? '');
              $categoryKey = array_search($component['category'], $componentCategories, true);
              $replacementCategoryDefault = $categoryKey !== false ? (string) $categoryKey : 'other';
              $replacementCategoryCustomDefault = $categoryKey === false ? (string) $component['category'] : '';
              $installedDisplay = (string) ($component['installed_at_display'] ?? 'Onbekend');
              $installedDuration = $component['installed_duration'] ?? null;
              $warrantyStatus = (string) ($component['warranty_status'] ?? 'none');
              $warrantyDisplay = (string) ($component['warranty_expires_display'] ?? '');
              $warrantyLabel = '';
              if ($warrantyDisplay !== '') {
                  if ($warrantyStatus === 'expired') {
                      $warrantyLabel = 'Verlopen op ' . $warrantyDisplay;
                  } elseif ($warrantyStatus === 'expiring') {
                      $warrantyLabel = 'Loopt af op ' . $warrantyDisplay;
                  } else {
                      $warrantyLabel = 'Geldig tot ' . $warrantyDisplay;
                  }
              }
              $maintenanceStatus = (string) ($component['maintenance_status'] ?? 'none');
              $nextMaintenanceDisplay = (string) ($component['next_maintenance_due_display'] ?? '');
              $maintenanceReference = (string) ($component['maintenance_reference_label'] ?? '');
              $maintenanceInterval = $component['maintenance_interval_days_int'] ?? null;
              $lastAuditedDisplay = (string) ($component['last_audited_display'] ?? '');
              $lastAuditedDuration = $component['last_audited_duration'] ?? null;
              $auditStatus = (string) ($component['audit_status'] ?? 'ok');
              $auditLabel = (string) ($component['audit_label'] ?? '');
            ?>
            <article class="component-card<?= $isActive ? '' : ' component-card--archived' ?>">
              <header class="component-card__header">
                <div>
                  <h3><?= htmlspecialchars((string) $component['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h3>
                  <?php if (!empty($component['asset_tag'])): ?>
                    <span class="component-card__meta">Asset: <?= htmlspecialchars((string) $component['asset_tag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if (!empty($component['inventory_location'])): ?>
                    <span class="component-card__meta">Locatie: <?= htmlspecialchars((string) $component['inventory_location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                </div>
                <div class="component-card__status-group">
                  <span class="component-card__status"><?= htmlspecialchars($componentStatus, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php if ($warrantyStatus === 'expired'): ?>
                    <span class="badge badge--danger">Garantie verlopen</span>
                  <?php elseif ($warrantyStatus === 'expiring'): ?>
                    <span class="badge badge--warning">Garantie t/m <?= htmlspecialchars($warrantyDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                  <?php endif; ?>
                  <?php if ($maintenanceStatus === 'overdue'): ?>
                    <span class="badge badge--danger">Onderhoud vereist</span>
                  <?php elseif ($maintenanceStatus === 'due_soon'): ?>
                    <span class="badge badge--warning">Onderhoud binnen 7 dagen</span>
                  <?php endif; ?>
                  <?php if ($auditStatus === 'overdue'): ?>
                    <span class="badge badge--danger">Controle vereist</span>
                  <?php elseif ($auditStatus === 'missing'): ?>
                    <span class="badge badge--warning">Geen controle bekend</span>
                  <?php elseif ($auditStatus === 'due_soon'): ?>
                    <span class="badge badge--warning">Controle gepland</span>
                  <?php elseif ($auditStatus === 'stale'): ?>
                    <span class="badge badge--warning">Controle verouderd</span>
                  <?php endif; ?>
                </div>
              </header>
              <dl class="data-list data-list--compact">
                <div>
                  <dt>Categorie</dt>
                  <dd><?= htmlspecialchars((string) $component['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                </div>
                <?php if (!empty($component['manufacturer'])): ?>
                  <div>
                    <dt>Fabrikant</dt>
                    <dd><?= htmlspecialchars((string) $component['manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['model'])): ?>
                  <div>
                    <dt>Model</dt>
                    <dd><?= htmlspecialchars((string) $component['model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['serial_number'])): ?>
                  <div>
                    <dt>Serienummer</dt>
                    <dd><?= htmlspecialchars((string) $component['serial_number'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <div>
                  <dt>Geplaatst op</dt>
                  <dd>
                    <?= htmlspecialchars($installedDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                    <?php if ($installedDuration !== null): ?>
                      <span class="component-card__subtext"><?= htmlspecialchars($installedDuration, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    <?php endif; ?>
                  </dd>
                </div>
                <?php if ($warrantyLabel !== ''): ?>
                  <div>
                    <dt>Garantie</dt>
                    <dd><?= htmlspecialchars($warrantyLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if ($maintenanceInterval !== null): ?>
                  <div>
                     <dt>Onderhoudsinterval</dt>
                    <dd><?= (int) $maintenanceInterval ?> dagen</dd>
                  </div>
                <?php endif; ?>
                <?php if ($nextMaintenanceDisplay !== ''): ?>
                  <div>
                    <dt>Volgende onderhoud</dt>
                    <dd>
                      <?= htmlspecialchars($nextMaintenanceDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php if ($maintenanceReference !== ''): ?>
                        <span class="component-card__subtext"><?= htmlspecialchars('Gebaseerd op ' . $maintenanceReference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </dd>
                  </div>
                <?php endif; ?>
                <?php if ($lastAuditedDisplay !== ''): ?>
                  <div>
                    <dt>Laatste controle</dt>
                    <dd>
                      <?= htmlspecialchars($lastAuditedDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                      <?php if ($lastAuditedDuration !== null): ?>
                        <span class="component-card__subtext"><?= htmlspecialchars('(' . $lastAuditedDuration . ' geleden)', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                      <?php endif; ?>
                    </dd>
                  </div>
                <?php endif; ?>
                <?php if ($auditLabel !== ''): ?>
                  <div>
                    <dt>Controle status</dt>
                    <dd><?= htmlspecialchars($auditLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['condition_status'])): ?>
                  <div>
                    <dt>Conditie</dt>
                    <dd><?= htmlspecialchars((string) $component['condition_status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['supplier'])): ?>
                  <div>
                    <dt>Leverancier</dt>
                    <dd><?= htmlspecialchars((string) $component['supplier'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['purchase_reference'])): ?>
                  <div>
                    <dt>Inkoopreferentie</dt>
                    <dd><?= htmlspecialchars((string) $component['purchase_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['purchase_cost'])): ?>
                  <div>
                    <dt>Kostprijs / waarde</dt>
                    <dd><?= htmlspecialchars((string) $component['purchase_cost'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['specifications'])): ?>
                  <div>
                    <dt>Specificaties</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!empty($component['notes'])): ?>
                  <div>
                    <dt>Notities</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!$isActive && $removedAtDisplay !== ''): ?>
                  <div>
                    <dt>Verwijderd op</dt>
                    <dd><?= htmlspecialchars($removedAtDisplay, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!$isActive && !empty($component['removal_reason'])): ?>
                  <div>
                    <dt>Reden</dt>
                    <dd><?= nl2br(htmlspecialchars((string) $component['removal_reason'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></dd>
                  </div>
                <?php endif; ?>
                <?php if (!$isActive && $replacementName !== ''): ?>
                  <div>
                    <dt>Vervangen door</dt>
                    <dd><?= htmlspecialchars($replacementName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                  </div>
                <?php endif; ?>
              </dl>
              <?php if (!empty($componentActionErrors[$componentId])): ?>
                <div class="alert alert--danger component-card__alert">
                  <ul>
                    <?php foreach ($componentActionErrors[$componentId] as $errorMessage): ?>
                      <li><?= htmlspecialchars((string) $errorMessage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                  </ul>
                </div>
              <?php endif; ?>
              <?php if ($isActive): ?>
                <details class="component-card__details">
                  <summary>Nieuw onderdeel plaatsen</summary>
                  <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="replace-component">
                    <input type="hidden" name="component_id" value="<?= $componentId ?>">
                    <div class="form-grid">
                      <label>
                        Categorie*
                        <select name="replacement_category" required>
                          <?php foreach ($componentCategories as $key => $label): ?>
                            <?php if ($key === 'other') { continue; } ?>
                            <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $replacementCategoryDefault === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
                          <?php endforeach; ?>
                          <option value="other" <?= $replacementCategoryDefault === 'other' ? 'selected' : '' ?>>Overig (vrij invullen)</option>
                        </select>
                      </label>
                      <label>
                        Categorie (vrij)
                        <input type="text" name="replacement_category_custom" value="<?= htmlspecialchars($replacementCategoryCustomDefault, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. Ventilator">
                      </label>
                      <label>
                        Onderdeelnaam*
                        <input type="text" name="replacement_name" list="component-name-options" required>
                      </label>
                      <label>
                        Fabrikant
                        <input type="text" name="replacement_manufacturer" list="component-manufacturer-options">
                      </label>
                      <label>
                        Model
                        <input type="text" name="replacement_model" list="component-model-options">
                      </label>
                      <label>
                        Serienummer
                        <input type="text" name="replacement_serial">
                      </label>
                      <label>
                        Geplaatst op
                        <input type="datetime-local" name="replacement_installed_at">
                      </label>
                    </div>
                    <details class="form-expander">
                      <summary>Levenscyclus & voorraad (optioneel)</summary>
                      <div class="form-grid form-grid--compact">
                        <label>
                          Asset tag / magazijncode
                          <input type="text" name="replacement_asset_tag">
                        </label>
                        <label>
                          Locatie / magazijn
                          <input type="text" name="replacement_inventory_location" list="component-location-options">
                        </label>
                        <label>
                          Conditie
                          <input type="text" name="replacement_condition" list="component-condition-options">
                        </label>
                        <label>
                          Leverancier
                          <input type="text" name="replacement_supplier" list="component-supplier-options">
                        </label>
                        <label>
                          Inkoopreferentie
                          <input type="text" name="replacement_purchase_reference">
                        </label>
                        <label>
                          Kostprijs / waarde
                          <input type="text" name="replacement_purchase_cost" placeholder="Bijv. €95">
                        </label>
                        <label>
                          Garantie tot
                          <input type="date" name="replacement_warranty_expires_at">
                        </label>
                        <label>
                          Onderhoudsinterval (dagen)
                          <input type="number" name="replacement_maintenance_interval_days" min="1">
                        </label>
                        <label>
                          Laatst gecontroleerd op
                          <input type="datetime-local" name="replacement_last_audited_at">
                        </label>
                      </div>
                    </details>
                    <label>
                      Specificaties
                      <textarea name="replacement_specifications" rows="2"></textarea>
                    </label>
                    <label>
                      Notities
                      <textarea name="replacement_notes" rows="2"></textarea>
                    </label>
                    <label>
                      Reden van vervanging
                      <textarea name="replacement_removal_reason" rows="2" placeholder="Bijv. defecte ventilator of upgrade"></textarea>
                    </label>
                    <div class="form-actions">
                      <button type="submit" class="btn btn--primary">Vervanging registreren</button>
                    </div>
                  </form>
                </details>
                <details class="component-card__details">
                  <summary>Component archiveren</summary>
                  <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                    <input type="hidden" name="action" value="retire-component">
                    <input type="hidden" name="component_id" value="<?= $componentId ?>">
                    <label>
                      Reden (optioneel)
                      <textarea name="removal_reason" rows="2" placeholder="Bijv. klant neemt onderdeel mee"></textarea>
                    </label>
                    <div class="form-actions">
                      <button type="submit" class="btn btn--ghost">Markeren als verwijderd</button>
                    </div>
                  </form>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <hr>
      <h3>Nieuw component toevoegen</h3>
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="component-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add-component">
        <div class="form-grid">
          <label>
            Categorie*
            <select name="component_category" required>
              <?php foreach ($componentCategories as $key => $label): ?>
                <?php if ($key === 'other') { continue; } ?>
                <option value="<?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $componentFormValues['component_category'] === (string) $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
              <option value="other" <?= $componentFormValues['component_category'] === 'other' ? 'selected' : '' ?>>Overig (vrij invullen)</option>
            </select>
            <?php if (!empty($componentFormErrors['component_category'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Categorie (vrij)
            <input type="text" name="component_category_custom" value="<?= htmlspecialchars($componentFormValues['component_category_custom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Alleen invullen bij &#39;Overig&#39;">
            <?php if (!empty($componentFormErrors['component_category_custom'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_category_custom'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Onderdeelnaam*
            <input type="text" name="component_name" value="<?= htmlspecialchars($componentFormValues['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-name-options" required>
            <?php if (!empty($componentFormErrors['component_name'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Fabrikant
            <input type="text" name="component_manufacturer" value="<?= htmlspecialchars($componentFormValues['component_manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-manufacturer-options">
            <?php if (!empty($componentFormErrors['component_manufacturer'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_manufacturer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Model
            <input type="text" name="component_model" value="<?= htmlspecialchars($componentFormValues['component_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-model-options">
            <?php if (!empty($componentFormErrors['component_model'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_model'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Serienummer
            <input type="text" name="component_serial" value="<?= htmlspecialchars($componentFormValues['component_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_serial'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_serial'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Geplaatst op
            <input type="datetime-local" name="component_installed_at" value="<?= htmlspecialchars($componentFormValues['component_installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
            <?php if (!empty($componentFormErrors['component_installed_at'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_installed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
        </div>
        <details class="form-expander">
          <summary>Levenscyclus & voorraad (optioneel)</summary>
          <div class="form-grid form-grid--compact">
            <label>
              Asset tag / magazijncode
              <input type="text" name="component_asset_tag" value="<?= htmlspecialchars($componentFormValues['component_asset_tag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($componentFormErrors['component_asset_tag'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_asset_tag'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Locatie / magazijn
              <input type="text" name="component_inventory_location" value="<?= htmlspecialchars($componentFormValues['component_inventory_location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-location-options">
              <?php if (!empty($componentFormErrors['component_inventory_location'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_inventory_location'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Conditie
              <input type="text" name="component_condition" value="<?= htmlspecialchars($componentFormValues['component_condition'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-condition-options">
              <?php if (!empty($componentFormErrors['component_condition'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_condition'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Leverancier
              <input type="text" name="component_supplier" value="<?= htmlspecialchars($componentFormValues['component_supplier'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" list="component-supplier-options">
              <?php if (!empty($componentFormErrors['component_supplier'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_supplier'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Inkoopreferentie
              <input type="text" name="component_purchase_reference" value="<?= htmlspecialchars($componentFormValues['component_purchase_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($componentFormErrors['component_purchase_reference'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_purchase_reference'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Kostprijs / waarde
              <input type="text" name="component_purchase_cost" value="<?= htmlspecialchars($componentFormValues['component_purchase_cost'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. €95">
              <?php if (!empty($componentFormErrors['component_purchase_cost'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_purchase_cost'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Garantie tot
              <input type="date" name="component_warranty_expires_at" value="<?= htmlspecialchars($componentFormValues['component_warranty_expires_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($componentFormErrors['component_warranty_expires_at'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_warranty_expires_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Onderhoudsinterval (dagen)
              <input type="number" name="component_maintenance_interval_days" min="1" value="<?= htmlspecialchars($componentFormValues['component_maintenance_interval_days'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($componentFormErrors['component_maintenance_interval_days'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_maintenance_interval_days'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
            <label>
              Laatst gecontroleerd op
              <input type="datetime-local" name="component_last_audited_at" value="<?= htmlspecialchars($componentFormValues['component_last_audited_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
              <?php if (!empty($componentFormErrors['component_last_audited_at'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_last_audited_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
            </label>
          </div>
        </details>
        <label>
          Specificaties
          <textarea name="component_specifications" rows="2"><?= htmlspecialchars($componentFormValues['component_specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($componentFormErrors['component_specifications'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_specifications'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <label>
          Notities
          <textarea name="component_notes" rows="2"><?= htmlspecialchars($componentFormValues['component_notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($componentFormErrors['component_notes'])): ?><span class="form-error"><?= htmlspecialchars($componentFormErrors['component_notes'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--secondary">Component toevoegen</button>
        </div>
      </form>
      <datalist id="component-condition-options">
        <option value="Nieuw">
        <option value="Als nieuw">
        <option value="Gebruikt">
        <option value="Gereviseerd">
        <option value="Defect">
      </datalist>
      <datalist id="component-name-options">
        <?php foreach ($componentSuggestions['names'] as $option): ?>
          <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
      </datalist>
      <datalist id="component-manufacturer-options">
        <?php foreach ($componentSuggestions['manufacturers'] as $option): ?>
          <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
      </datalist>
      <datalist id="component-model-options">
        <?php foreach ($componentSuggestions['models'] as $option): ?>
          <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
      </datalist>
      <datalist id="component-supplier-options">
        <?php foreach ($componentSuggestions['suppliers'] as $option): ?>
          <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
      </datalist>
      <datalist id="component-location-options">
        <?php foreach ($componentSuggestions['locations'] as $option): ?>
          <option value="<?= htmlspecialchars((string) $option, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <?php endforeach; ?>
      </datalist>
    </section>

    <section class="card">
      <h2>Foto&#39;s van intake</h2>
      <?php if ($photos === []): ?>
        <p>Er zijn nog geen foto&#39;s opgeslagen.</p>
      <?php else: ?>
        <div class="photo-gallery">
          <?php foreach ($photos as $photo): ?>
            <a class="photo-gallery__item" href="device-photo.php?id=<?= (int) $photo['id'] ?>" target="_blank" rel="noopener">
              <img src="device-photo.php?id=<?= (int) $photo['id'] ?>" alt="<?= htmlspecialchars((string) $photo['orientation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" loading="lazy">
              <span><?= htmlspecialchars((string) $photo['orientation'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Reparatiegeschiedenis</h2>
      <?php if ($events === []): ?>
        <p>Er zijn nog geen reparatieactiviteiten geregistreerd.</p>
      <?php else: ?>
        <ul class="timeline">
          <?php foreach ($events as $event): ?>
            <li class="timeline__item">
              <header class="timeline__header">
                <span class="timeline__type"><?= htmlspecialchars($eventTypes[$event['event_type']] ?? (string) $event['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                <span class="timeline__meta">door <?= htmlspecialchars((string) $event['performed_by'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> op <?= htmlspecialchars((string) $event['performed_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
              </header>
              <p><?= nl2br(htmlspecialchars((string) $event['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
              <?php if (!empty($event['metadata'])): ?>
                <dl class="timeline__meta-list">
                  <?php foreach ($event['metadata'] as $key => $value): ?>
                    <div>
                      <dt><?= htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dt>
                      <dd><?= htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></dd>
                    </div>
                  <?php endforeach; ?>
                </dl>
              <?php endif; ?>
              <?php if (!empty($event['case_id'])): ?>
                <p class="timeline__case">Case: <a href="case.php?id=<?= (int) $event['case_id'] ?>">#<?= (int) $event['case_id'] ?> <?= htmlspecialchars((string) ($event['case_summary'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></a></p>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>

    <section class="card">
      <h2>Nieuwe reparatie-activiteit</h2>
      <form action="device.php?id=<?= $deviceId ?>" method="post" class="event-form">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
        <input type="hidden" name="action" value="add-event">
        <div class="form-grid">
          <label>
            Koppel aan case
            <select name="case_id">
              <option value="">(Meest recente)</option>
              <?php foreach ($cases as $case): ?>
                <?php $caseIdString = (string) $case['id']; ?>
                <option value="<?= (int) $case['id'] ?>" <?= $selectedCasePost === $caseIdString ? 'selected' : '' ?>>Case #<?= (int) $case['id'] ?> - <?= htmlspecialchars((string) $case['summary'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
             <?php if (!empty($eventErrors['case_id'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['case_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Type activiteit
            <select name="event_type" required>
              <?php foreach ($eventTypes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" <?= $selectedEventTypePost === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></option>
              <?php endforeach; ?>
            </select>
            <?php if (!empty($eventErrors['event_type'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['event_type'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
          </label>
          <label>
            Betrokken onderdeel
            <input type="text" name="component" value="<?= htmlspecialchars((string) ($_POST['component'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. RAM module">
          </label>
          <label>
            Nieuwe status
            <input type="text" name="status_update" value="<?= htmlspecialchars((string) ($_POST['status_update'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. in_reparatie">
          </label>
          <label>
            Kosten / referentie
            <input type="text" name="costs" value="<?= htmlspecialchars((string) ($_POST['costs'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>" placeholder="Bijv. €50 of ordernummer">
          </label>
        </div>
        <label>
          Beschrijving*
          <textarea name="description" rows="4" required><?= htmlspecialchars((string) ($_POST['description'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
          <?php if (!empty($eventErrors['description'])): ?><span class="form-error"><?= htmlspecialchars($eventErrors['description'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span><?php endif; ?>
        </label>
        <div class="form-actions">
          <button type="submit" class="btn btn--primary">Activiteit opslaan</button>
        </div>
      </form>
    </section>
  </main>
</body>
</html>