<?php

declare(strict_types=1);

use App\Exception\ValidationException;

namespace App\Support\Repositories;

use DateTimeImmutable;
use PDO;
use function __;

final class PartnerRepository
{
    public const STATUS_PENDING_ACTIVATION = 'pending_activation';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_ACTIVE = 'active';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(?string $status = null): array
    {
        $sql = 'SELECT * FROM partners';
        $params = [];

        if ($status !== null && $status !== '' && $status !== 'all') {
            $sql .= ' WHERE status = :status';
            $params['status'] = $status;
        }

        $sql .= ' ORDER BY created_at DESC';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);

        return $statement->fetchAll() ?: [];
    }

    public function find(int $partnerId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM partners WHERE id = :id');
        $statement->execute(['id' => $partnerId]);
        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    public function findByActivationToken(string $token): ?array
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT * FROM partners WHERE activation_token = :token AND status = :status AND (activation_expires IS NULL OR activation_expires > CURRENT_TIMESTAMP)"
        );
        $statement->execute([
            'token' => $normalized,
            'status' => self::STATUS_PENDING_ACTIVATION,
        ]);

        $record = $statement->fetch();

        return $record !== false ? $record : null;
    }

    public function create(
        string $fullName,
        string $companyName,
        string $email,
        string $phone,
        string $address,
        string $vatNumber,
        string $kvkNumber
    ): array {
        if ($this->emailExists($email)) {
            throw new ValidationException(['email' => __('partners.messages.email_exists')]);
        }

        $partnerCode = $this->generatePartnerCode();
        $activationToken = bin2hex(random_bytes(20));
        $expiresAt = (new DateTimeImmutable('+3 days'))->format('Y-m-d H:i:s');

        $statement = $this->pdo->prepare(
            'INSERT INTO partners (partner_code, full_name, company_name, email, phone, address, vat_number, kvk_number, status, activation_token, activation_expires)'
            . ' VALUES (:partner_code, :full_name, :company_name, :email, :phone, :address, :vat_number, :kvk_number, :status, :activation_token, :activation_expires)'
        );

        $statement->execute([
            'partner_code' => $partnerCode,
            'full_name' => $fullName,
            'company_name' => $companyName,
            'email' => $email,
            'phone' => $phone,
            'address' => $address,
            'vat_number' => $vatNumber,
            'kvk_number' => $kvkNumber,
            'status' => self::STATUS_PENDING_ACTIVATION,
            'activation_token' => $activationToken,
            'activation_expires' => $expiresAt,
        ]);

        return $this->find((int) $this->pdo->lastInsertId());
    }

    public function activate(int $partnerId, string $passwordHash): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE partners SET password_hash = :password_hash, status = :status, activated_at = CURRENT_TIMESTAMP, activation_token = NULL, activation_expires = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        $statement->execute([
            'password_hash' => $passwordHash,
            'status' => self::STATUS_PENDING_APPROVAL,
            'id' => $partnerId,
        ]);
    }

    public function approve(int $partnerId, ?int $approvedBy = null): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE partners SET status = :status, approved_at = CURRENT_TIMESTAMP, approved_by = :approved_by, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
        );

        $statement->execute([
            'status' => self::STATUS_ACTIVE,
            'approved_by' => $approvedBy,
            'id' => $partnerId,
        ]);
    }

    private function generatePartnerCode(): string
    {
        do {
            $candidate = 'P' . strtoupper(bin2hex(random_bytes(3)));
        } while ($this->codeExists($candidate));

        return $candidate;
    }

    private function codeExists(string $code): bool
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM partners WHERE partner_code = :code');
        $statement->execute(['code' => $code]);

        return ((int) $statement->fetchColumn()) > 0;
    }

    private function emailExists(string $email): bool
    {
        $normalized = trim($email);
        if ($normalized === '') {
            return false;
        }

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM partners WHERE email = :email');
        $statement->execute(['email' => $normalized]);

        return ((int) $statement->fetchColumn()) > 0;
    }
}