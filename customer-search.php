<?php

declare(strict_types=1);

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

$payload = array_map(static function (array $customer): array {
    return [
        'id' => (int) ($customer['id'] ?? 0),
        'code' => (string) ($customer['customer_code'] ?? ''),
        'name' => (string) ($customer['full_name'] ?? ''),
        'email' => (string) ($customer['email'] ?? ''),
        'phone' => (string) ($customer['phone'] ?? ''),
        'address' => trim((string) ($customer['address'] ?? '')), 
        'city' => (string) ($customer['city'] ?? ''),
        'postal_code' => (string) ($customer['postal_code'] ?? ''),
    ];
}, $results);

echo json_encode(['data' => $payload], JSON_THROW_ON_ERROR);