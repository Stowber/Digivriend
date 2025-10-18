<?php

declare(strict_types=1);

use App\Support\Repositories\PcBuildRepository;

require __DIR__ . '/../../bootstrap.php';

$pdo = require __DIR__ . '/../../database.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$existing = $pdo->prepare('SELECT id FROM pc_builds WHERE created_by = :created_by LIMIT 1');
$existing->execute(['created_by' => 'Seeder']);
if ($existing->fetchColumn()) {
    echo "Seed PC buildera został już wykonany.\n";

    return;
}

$caseId = (int) ($pdo->query('SELECT id FROM cases ORDER BY id LIMIT 1')->fetchColumn() ?: 0);
$customerId = (int) ($pdo->query('SELECT id FROM customers ORDER BY id LIMIT 1')->fetchColumn() ?: 0);

if ($caseId === 0 || $customerId === 0) {
    echo "Brak rekordów w tabelach cases lub customers – pomijam seed PC buildera.\n";

    return;
}

$componentStatement = $pdo->query('SELECT id, name, unit_price_cents FROM warehouse_items ORDER BY id LIMIT 3');
$componentRows = $componentStatement ? $componentStatement->fetchAll(PDO::FETCH_ASSOC) : [];

$repository = new PcBuildRepository($pdo);
$build = $repository->createBuild(
    $caseId,
    null,
    'planning',
    'Przykładowa budowa demo',
    'Seeder',
    $customerId,
    'Seeder',
    'information'
);

$buildId = (int) ($build['id'] ?? 0);
if ($buildId <= 0) {
    throw new RuntimeException('Nie udało się utworzyć przykładowego buildu.');
}

$componentCategories = ['case', 'motherboard', 'cpu'];
$componentsPayload = [];
$subtotal = 0;

foreach ($componentRows as $index => $row) {
    $itemId = (int) ($row['id'] ?? 0);
    if ($itemId <= 0) {
        continue;
    }

    $unitPrice = (int) ($row['unit_price_cents'] ?? 150000);
    if ($unitPrice <= 0) {
        $unitPrice = 150000;
    }

    $componentsPayload[] = [
        'category' => $componentCategories[$index] ?? 'extras',
        'item_id' => $itemId,
        'label' => (string) ($row['name'] ?? ('Pozycja #' . $itemId)),
        'quantity' => 1,
        'unit_price_cents' => $unitPrice,
        'total_price_cents' => $unitPrice,
        'notes' => 'Element demonstracyjny',
    ];
    $subtotal += $unitPrice;
}

if ($componentsPayload === []) {
    $componentsPayload[] = [
        'category' => 'extras',
        'item_id' => null,
        'label' => 'Manualnie dodany komponent',
        'quantity' => 1,
        'unit_price_cents' => 120000,
        'total_price_cents' => 120000,
        'notes' => 'Komponent demonstracyjny bez powiązania magazynowego',
    ];
    $subtotal += 120000;
}

$marginPercent = 10.0;
$marginCents = (int) round($subtotal * ($marginPercent / 100));
$totalCents = $subtotal + $marginCents;

$planningPayload = [
    'notes' => 'Dane testowe do środowiska developerskiego.',
    'components' => $componentsPayload,
    'financials' => [
        'subtotal_cents' => $subtotal,
        'margin_percent' => $marginPercent,
        'margin_cents' => $marginCents,
        'total_cents' => $totalCents,
    ],
    'currency' => 'PLN',
];

$repository->savePlanningData($buildId, $planningPayload, $totalCents, 'PLN', 'Seeder');
$repository->syncComponentsFromPlanningPayload($buildId, $planningPayload);

$repository->saveAssemblyData(
    $buildId,
    [
        'tasks_completed' => ['case_preparation', 'install_motherboard'],
        'small_parts' => 'Śruby montażowe, opaski zaciskowe',
        'notes' => 'Zestaw przygotowany w ramach seed danych.',
    ],
    'Seeder'
);

echo sprintf("Dodano przykładowy build PC o identyfikatorze %d.\n", $buildId);