<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use PDO;

final class RepairEventRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function log(
        int $deviceId,
        ?int $caseId,
        string $eventType,
        string $description,
        string $performedBy,
        array $metadata = [],
        ?string $performedAt = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO repair_events (case_id, device_id, event_type, description, performed_by, performed_at, metadata)
             VALUES (:case_id, :device_id, :event_type, :description, :performed_by, COALESCE(:performed_at, CURRENT_TIMESTAMP), :metadata)'
        );
        $statement->execute([
            'case_id' => $caseId ?: null,
            'device_id' => $deviceId,
            'event_type' => $eventType,
            'description' => $description,
            'performed_by' => $performedBy,
            'performed_at' => $performedAt,
            'metadata' => $metadata !== [] ? json_encode($metadata, JSON_THROW_ON_ERROR) : null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forDevice(int $deviceId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT re.*, c.summary AS case_summary, c.status AS case_status, c.type AS case_type
             FROM repair_events re
             LEFT JOIN cases c ON c.id = re.case_id
             WHERE re.device_id = :device_id
             ORDER BY re.performed_at DESC, re.id DESC'
        );
        $statement->execute(['device_id' => $deviceId]);

        $events = $statement->fetchAll() ?: [];

        foreach ($events as &$event) {
            $metadata = $event['metadata'] ?? null;
            if (is_string($metadata) && $metadata !== '') {
                $decoded = json_decode($metadata, true);
                $event['metadata'] = is_array($decoded) ? $decoded : [];
            } else {
                $event['metadata'] = [];
            }
        }

        return $events;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT re.*
             FROM repair_events re
             WHERE re.case_id = :case_id
             ORDER BY re.performed_at DESC, re.id DESC'
        );
        $statement->execute(['case_id' => $caseId]);

        $events = $statement->fetchAll() ?: [];

        foreach ($events as &$event) {
            $metadata = $event['metadata'] ?? null;
            if (is_string($metadata) && $metadata !== '') {
                $decoded = json_decode($metadata, true);
                $event['metadata'] = is_array($decoded) ? $decoded : [];
            } else {
                $event['metadata'] = [];
            }
        }

        return $events;
    }
}