<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;

final class PcBuildDocumentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        int $buildId,
        string $type,
        string $status,
        string $filePath,
        ?string $recipient = null,
        ?string $errorMessage = null,
        array $metadata = [],
        ?string $sentAt = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO pc_build_documents (build_id, type, status, file_path, recipient, error_message, metadata, sent_at, created_at, updated_at) '
            . 'VALUES (:build_id, :type, :status, :file_path, :recipient, :error_message, :metadata, :sent_at, :created_at, :updated_at)'
        );

        $now = Clock::nowFormatted();

        $statement->execute([
            'build_id' => $buildId,
            'type' => $type,
            'status' => $status,
            'file_path' => $filePath,
            'recipient' => $recipient,
            'error_message' => $errorMessage,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'sent_at' => $sentAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}