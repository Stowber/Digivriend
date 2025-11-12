<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use App\Support\Customers\CustomerCodeGenerator;
use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listCustomers(?string $search = null, int $limit = 200, ?string $type = null): array
    {
        $limit = max(1, min(500, $limit));

        $sql = 'SELECT id, customer_code, customer_type, is_suspicious, suspicious_reason, full_name, email, phone, address, postal_code, city, last_interaction_at, updated_at FROM customers';
        $conditions = [];
        $params = [];

        if ($search !== null && trim($search) !== '') {
            $like = '%' . trim($search) . '%';
            $conditions[] = '(full_name LIKE :search OR email LIKE :search OR phone LIKE :search OR customer_code LIKE :search)';
            $params['search'] = $like;
        }

        if ($type !== null && $type !== '') {
            $normalizedType = $this->normalizeType($type);
            $conditions[] = 'customer_type = :customer_type';
            $params['customer_type'] = $normalizedType;
        }

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sql .= ' ORDER BY updated_at DESC LIMIT :limit';

        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function upsert(
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address = null,
        ?string $postalCode = null,
        ?string $city = null,
        string $type = 'private'
    ): array {
        $customer = $this->findExisting($email, $phone, $fullName);
        $normalizedType = $this->normalizeType($type);

        if ($customer === null) {
            $customerId = $this->insert(
                $fullName,
                $email,
                $phone,
                $address,
                $postalCode,
                $city,
                $normalizedType,
                CustomerCodeGenerator::generate($this->pdo, $normalizedType)
            );
            $customer = $this->findById($customerId);
        } else {
            $this->update($customer['id'], $fullName, $email, $phone, $address, $postalCode, $city);
            if (!isset($customer['customer_code']) || trim((string) $customer['customer_code']) === '') {
                $this->assignCode((int) $customer['id']);
            }
            $customer = $this->findById((int) $customer['id']);
        }

        return $customer ?? [];
    }

    public function create(
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address = null,
        ?string $postalCode = null,
        ?string $city = null,
        string $type = 'private'
    ): array {
        $normalizedType = $this->normalizeType($type);
        $customerId = $this->insert(
            $fullName,
            $email,
            $phone,
            $address,
            $postalCode,
            $city,
            $normalizedType,
            CustomerCodeGenerator::generate($this->pdo, $normalizedType)
        );

        return $this->findById($customerId) ?? [];
    }

    public function touch(int $customerId): void
    {
        $statement = $this->pdo->prepare('UPDATE customers SET last_interaction_at = :last_interaction_at WHERE id = :id');
        $statement->execute([
            'id' => $customerId,
            'last_interaction_at' => Clock::nowFormatted(),
        ]);
    }

    public function updateType(int $customerId, string $type): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE customers SET customer_type = :customer_type, updated_at = :updated_at WHERE id = :id'
        );

        $statement->execute([
            'id' => $customerId,
            'customer_type' => $this->normalizeType($type),
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    public function updateProfile(
        int $customerId,
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $postalCode,
        ?string $city
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE customers
             SET full_name = :full_name,
                 email = :email,
                 phone = :phone,
                 address = :address,
                 postal_code = :postal_code,
                 city = :city,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $customerId,
            'full_name' => $fullName,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'postal_code' => $postalCode ?: null,
            'city' => $city ?: null,
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    public function markSuspicious(int $customerId, string $reason): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE customers
             SET is_suspicious = 1,
                 suspicious_reason = :reason,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $customerId,
            'reason' => $reason,
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    public function clearSuspicious(int $customerId): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE customers
             SET is_suspicious = 0,
                 suspicious_reason = NULL,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $customerId,
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    public function findById(int $customerId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $statement->execute(['id' => $customerId]);

        $customer = $statement->fetch(PDO::FETCH_ASSOC);

        return $customer !== false ? $customer : null;
    }

    public function findByCode(string $customerCode): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE customer_code = :customer_code LIMIT 1');
        $statement->execute(['customer_code' => $customerCode]);

        $customer = $statement->fetch(PDO::FETCH_ASSOC);

        return $customer !== false ? $customer : null;
    }

    private function insert(
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $postalCode,
        ?string $city,
        string $type,
        ?string $customerCode
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO customers (customer_code, customer_type, full_name, email, phone, address, postal_code, city, last_interaction_at, updated_at)
             VALUES (:customer_code, :customer_type, :full_name, :email, :phone, :address, :postal_code, :city, :last_interaction_at, :updated_at)'
        );

        $statement->execute([
            'customer_code' => $customerCode,
            'customer_type' => $type,
            'full_name' => $fullName,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'postal_code' => $postalCode ?: null,
            'city' => $city ?: null,
            'last_interaction_at' => Clock::nowFormatted(),
            'updated_at' => Clock::nowFormatted(),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function update(
        int $customerId,
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $postalCode,
        ?string $city
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE customers
             SET full_name = :full_name,
                 email = COALESCE(:email, email),
                 phone = COALESCE(:phone, phone),
                 address = COALESCE(:address, address),
                 postal_code = COALESCE(:postal_code, postal_code),
                 city = COALESCE(:city, city),
                 last_interaction_at = :last_interaction_at,
                 updated_at = :updated_at
             WHERE id = :id'
        );

        $statement->execute([
            'id' => $customerId,
            'full_name' => $fullName,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'postal_code' => $postalCode ?: null,
            'city' => $city ?: null,
            'last_interaction_at' => Clock::nowFormatted(),
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    private function assignCode(int $customerId): void
    {
        $customer = $this->findById($customerId);
        $typeValue = 'private';
        if (is_array($customer) && array_key_exists('customer_type', $customer)) {
            $typeValue = (string) $customer['customer_type'];
        }
        $type = $this->normalizeType($typeValue);

        $code = CustomerCodeGenerator::generate($this->pdo, $type);
        $statement = $this->pdo->prepare('UPDATE customers SET customer_code = :code, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'id' => $customerId,
            'code' => $code,
            'updated_at' => Clock::nowFormatted(),
        ]);
    }

    private function normalizeType(?string $type): string
    {
        $normalized = strtolower((string) $type);

        return $normalized === 'business' ? 'business' : 'private';
    }

    private function findExisting(?string $email, ?string $phone, string $fullName): ?array
    {
        if ($email) {
            $statement = $this->pdo->prepare('SELECT * FROM customers WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $customer = $statement->fetch(PDO::FETCH_ASSOC);
            if ($customer !== false) {
                return $customer;
            }
        }

        if ($phone) {
            $statement = $this->pdo->prepare('SELECT * FROM customers WHERE phone = :phone LIMIT 1');
            $statement->execute(['phone' => $phone]);
            $customer = $statement->fetch(PDO::FETCH_ASSOC);
            if ($customer !== false) {
                return $customer;
            }
        }

        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE full_name = :name ORDER BY updated_at DESC LIMIT 1');
        $statement->execute(['name' => $fullName]);
        $customer = $statement->fetch(PDO::FETCH_ASSOC);

        return $customer !== false ? $customer : null;
    }
}