<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class CustomerRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function upsert(
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address = null,
        ?string $postalCode = null,
        ?string $city = null
    ): array {
        $customer = $this->findExisting($email, $phone, $fullName);

        if ($customer === null) {
            $this->insert($fullName, $email, $phone, $address, $postalCode, $city);
            $customer = $this->findExisting($email, $phone, $fullName);
        } else {
            $this->update($customer['id'], $fullName, $email, $phone, $address, $postalCode, $city);
            $customer = $this->findById((int) $customer['id']);
        }

        return $customer ?? [];
    }

    public function touch(int $customerId): void
    {
        $statement = $this->pdo->prepare('UPDATE customers SET last_interaction_at = NOW() WHERE id = :id');
        $statement->execute(['id' => $customerId]);
    }

    public function findById(int $customerId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE id = :id');
        $statement->execute(['id' => $customerId]);

        $customer = $statement->fetch();

        return $customer !== false ? $customer : null;
    }

    private function insert(
        string $fullName,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $postalCode,
        ?string $city
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO customers (full_name, email, phone, address, postal_code, city, last_interaction_at)
             VALUES (:full_name, :email, :phone, :address, :postal_code, :city, NOW())'
        );

        $statement->execute([
            'full_name' => $fullName,
            'email' => $email ?: null,
            'phone' => $phone ?: null,
            'address' => $address ?: null,
            'postal_code' => $postalCode ?: null,
            'city' => $city ?: null,
        ]);
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
                 last_interaction_at = NOW()
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
        ]);
    }

    private function findExisting(?string $email, ?string $phone, string $fullName): ?array
    {
        if ($email) {
            $statement = $this->pdo->prepare('SELECT * FROM customers WHERE email = :email LIMIT 1');
            $statement->execute(['email' => $email]);
            $customer = $statement->fetch();
            if ($customer !== false) {
                return $customer;
            }
        }

        if ($phone) {
            $statement = $this->pdo->prepare('SELECT * FROM customers WHERE phone = :phone LIMIT 1');
            $statement->execute(['phone' => $phone]);
            $customer = $statement->fetch();
            if ($customer !== false) {
                return $customer;
            }
        }

        $statement = $this->pdo->prepare('SELECT * FROM customers WHERE full_name = :name ORDER BY updated_at DESC LIMIT 1');
        $statement->execute(['name' => $fullName]);
        $customer = $statement->fetch();

        return $customer !== false ? $customer : null;
    }
}