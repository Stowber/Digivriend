<?php

declare(strict_types=1);

namespace App\Support\Workflow;

use App\Support\Clock;
use PDO;

final class RepairWorkflowRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM case_workflow_tasks WHERE case_id = :case_id ORDER BY created_at ASC');
        $statement->execute(['case_id' => $caseId]);

        return $statement->fetchAll() ?: [];
    }

    public function find(int $taskId): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM case_workflow_tasks WHERE id = :id');
        $statement->execute(['id' => $taskId]);
        $task = $statement->fetch();

        return $task !== false ? $task : null;
    }

    public function create(
        int $caseId,
        string $title,
        string $stage,
        string $status,
        string $priority,
        ?string $assignedTo,
        ?string $dueAt,
        ?string $description
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO case_workflow_tasks (case_id, title, stage, status, priority, assigned_to, due_at, description) VALUES (:case_id, :title, :stage, :status, :priority, :assigned_to, :due_at, :description)'
        );
        $statement->execute([
            'case_id' => $caseId,
            'title' => $title,
            'stage' => $stage,
            'status' => $status,
            'priority' => $priority,
            'assigned_to' => $assignedTo,
            'due_at' => $dueAt,
            'description' => $description,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function updateStatus(int $taskId, string $status, ?string $blockedReason): void
    {
        $task = $this->find($taskId);
        if ($task === null) {
            return;
        }

        $startedAt = $task['started_at'] ?? null;
        $completedAt = $task['completed_at'] ?? null;

        if ($status === 'in_progress' && ($startedAt === null || $startedAt === '')) {
            $startedAt = Clock::nowFormatted();
        }

        if ($status === 'done') {
            $completedAt = Clock::nowFormatted();
        } else {
            $completedAt = null;
        }

        $statement = $this->pdo->prepare(
            'UPDATE case_workflow_tasks SET status = :status, blocked_reason = :blocked_reason, started_at = :started_at, completed_at = :completed_at, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'blocked_reason' => $status === 'blocked' ? $blockedReason : null,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'updated_at' => Clock::nowFormatted(),
            'id' => $taskId,
        ]);
    }

    public function moveToStage(int $taskId, string $stage): void
    {
        $statement = $this->pdo->prepare('UPDATE case_workflow_tasks SET stage = :stage, updated_at = :updated_at WHERE id = :id');
        $statement->execute([
            'stage' => $stage,
            'updated_at' => Clock::nowFormatted(),
            'id' => $taskId,
        ]);
    }

    public function delete(int $taskId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM case_workflow_tasks WHERE id = :id');
        $statement->execute(['id' => $taskId]);
    }
}