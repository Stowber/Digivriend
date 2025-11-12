<?php

declare(strict_types=1);

use App\Support\Customers\SuspiciousFlagRegistry;
use App\Support\Repositories\CustomerRepository;

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['data' => []], JSON_THROW_ON_ERROR);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode(['data' => []], JSON_THROW_ON_ERROR);
    exit;
}

if (mb_strlen($query) > 120) {
    $query = mb_substr($query, 0, 120);
}

if (mb_strlen($query) < 2) {
    echo json_encode(['data' => []], JSON_THROW_ON_ERROR);
    exit;
}

$customerRepository = new CustomerRepository($pdo);
$results = $customerRepository->listCustomers($query, 10);

$flagDefinitions = SuspiciousFlagRegistry::definitions();
$blockDefinitions = SuspiciousFlagRegistry::blockDefinitions();

$payload = array_map(static function (array $customer) use ($flagDefinitions, $blockDefinitions): array {
    $isSuspicious = isset($customer['is_suspicious']) && (int) $customer['is_suspicious'] === 1;
    $reason = $isSuspicious ? (string) ($customer['suspicious_reason'] ?? '') : '';
    $flags = $isSuspicious ? SuspiciousFlagRegistry::decodeFlags($customer['suspicious_flags'] ?? null) : [];
    $blocks = $isSuspicious ? SuspiciousFlagRegistry::blocksForFlags($flags) : [];

    $flagPayload = [];
    foreach ($flags as $flagKey) {
        if (!isset($flagDefinitions[$flagKey])) {
            continue;
        }

        $flagBlocks = [];
        foreach ($flagDefinitions[$flagKey]['blocks'] as $blockKey) {
            $blockDefinition = $blockDefinitions[$blockKey] ?? null;
            if ($blockDefinition !== null) {
                $flagBlocks[] = __($blockDefinition['label']);
            }
        }

        $flagPayload[] = [
            'key' => $flagKey,
            'label' => __($flagDefinitions[$flagKey]['label']),
            'description' => __($flagDefinitions[$flagKey]['description']),
            'blocks' => $flagBlocks,
        ];
    }

    $blockLabels = [];
    foreach ($blocks as $blockKey) {
        $blockDefinition = $blockDefinitions[$blockKey] ?? null;
        if ($blockDefinition !== null) {
            $blockLabels[] = __($blockDefinition['label']);
        }
    }

    $reasonText = $reason !== '' ? $reason : __('customers.profile.suspicious.reason_unknown');
    $warning = null;
    if ($isSuspicious) {
        $warning = __('intake.form.customer.suspicious_warning', [
            'reason' => $reasonText,
            'blocks' => $blockLabels !== [] ? implode(', ', $blockLabels) : __('customers.profile.suspicious.no_block_summary'),
            'contact' => __('customers.profile.suspicious.contact_manager'),
        ]);
    }
    return [
        'id' => (int) ($customer['id'] ?? 0),
        'code' => (string) ($customer['customer_code'] ?? ''),
        'name' => (string) ($customer['full_name'] ?? ''),
        'email' => (string) ($customer['email'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'address' => trim((string) ($customer['address'] ?? '')),
        'city' => (string) ($customer['city'] ?? ''),
        'postal_code' => (string) ($customer['postal_code'] ?? ''),
        'suspicious' => [
            'active' => $isSuspicious,
            'reason' => $reason,
            'flags' => $flagPayload,
            'blocks' => $blockLabels,
            'warning' => $warning,
            'intake_blocked' => in_array(SuspiciousFlagRegistry::BLOCK_INTAKES, $blocks, true),
        ],
    ];
}, $results);

echo json_encode(['data' => $payload], JSON_THROW_ON_ERROR);