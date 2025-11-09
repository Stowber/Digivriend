<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;

final class CustomerCompanyRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findByCustomerId(int $customerId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM customer_companies WHERE customer_id = :customer_id LIMIT 1'
        );

        $statement->execute(['customer_id' => $customerId]);

        $company = $statement->fetch(PDO::FETCH_ASSOC);

        return $company !== false ? $company : null;
    }

    public function upsert(
        int $customerId,
        string $name,
        string $kvk,
        ?string $btw,
        string $contactPerson,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $postalCode,
        ?string $city
    ): array {
        $existing = $this->findByCustomerId($customerId);
        $now = Clock::nowFormatted();

        if ($existing === null) {
            $statement = $this->pdo->prepare(
                'INSERT INTO customer_companies (
                    customer_id,
                    name,
                    kvk,
                    btw,
                    contact_person,
                    email,
                    phone,
                    address,
                    postal_code,
                    city,
                    created_at,
                    updated_at
                ) VALUES (
                    :customer_id,
                    :name,
                    :kvk,
                    :btw,
                    :contact_person,
                    :email,
                    :phone,
                    :address,
                    :postal_code,
                    :city,
                    :created_at,
                    :updated_at
                )'
            );

            $statement->execute([
                'customer_id' => $customerId,
                'name' => $name,
                'kvk' => $kvk,
                'btw' => $btw ?: null,
                'contact_person' => $contactPerson,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'address' => $address ?: null,
                'postal_code' => $postalCode ?: null,
                'city' => $city ?: null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $statement = $this->pdo->prepare(
                'UPDATE customer_companies
                 SET name = :name,
                     kvk = :kvk,
                     btw = :btw,
                     contact_person = :contact_person,
                     email = :email,
                     phone = :phone,
                     address = :address,
                     postal_code = :postal_code,
                     city = :city,
                     updated_at = :updated_at
                 WHERE customer_id = :customer_id'
            );

            $statement->execute([
                'customer_id' => $customerId,
                'name' => $name,
                'kvk' => $kvk,
                'btw' => $btw ?: null,
                'contact_person' => $contactPerson,
                'email' => $email ?: null,
                'phone' => $phone ?: null,
                'address' => $address ?: null,
                'postal_code' => $postalCode ?: null,
                'city' => $city ?: null,
                'updated_at' => $now,
            ]);
        }

        return $this->findByCustomerId($customerId) ?? [];
    }
    public function deleteByCustomerId(int $customerId): bool
    {
        $statement = $this->pdo->prepare('DELETE FROM customer_companies WHERE customer_id = :customer_id');
        $statement->execute(['customer_id' => $customerId]);

        return $statement->rowCount() > 0;
    }
}