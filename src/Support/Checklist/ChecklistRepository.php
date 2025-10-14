<?php

declare(strict_types=1);

namespace App\Support\Checklist;

use App\Support\Clock;
use PDO;

final class ChecklistRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function forCase(int $caseId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM case_checklists WHERE case_id = :case_id ORDER BY created_at ASC'
        );
        $statement->execute(['case_id' => $caseId]);
        $checklists = $statement->fetchAll() ?: [];

        if (empty($checklists)) {
            return [];
        }

        $ids = array_map(static fn (array $checklist): int => (int) $checklist['id'], $checklists);
        $itemsStatement = $this->pdo->prepare(
            'SELECT * FROM case_checklist_items WHERE checklist_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY created_at ASC'
        );
        $itemsStatement->execute($ids);
        $items = $itemsStatement->fetchAll() ?: [];

        $itemsByChecklist = [];
        foreach ($items as $item) {
            $itemsByChecklist[(int) $item['checklist_id']][] = $item;
        }

        foreach ($checklists as &$checklist) {
            $checklist['items'] = $itemsByChecklist[(int) $checklist['id']] ?? [];
        }

        return $checklists;
    }

    public function createChecklist(int $caseId, string $title, ?string $assignedTo, ?string $dueAt): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO case_checklists (case_id, title, assigned_to, due_at) VALUES (:case_id, :title, :assigned_to, :due_at)'
        );
        $statement->execute([
            'case_id' => $caseId,
            'title' => $title,
            'assigned_to' => $assignedTo,
            'due_at' => $dueAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function addItem(int $checklistId, string $description): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO case_checklist_items (checklist_id, description) VALUES (:checklist_id, :description)'
        );
        $statement->execute([
            'checklist_id' => $checklistId,
            'description' => $description,
        ]);
    }

    public function toggleItem(int $itemId, bool $completed, string $username): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE case_checklist_items SET is_completed = :completed, completed_by = :completed_by, completed_at = :completed_at WHERE id = :id'
        );
        $statement->execute([
            'completed' => $completed ? 1 : 0,
            'completed_by' => $completed ? $username : null,
            'completed_at' => $completed ? Clock::nowFormatted() : null,
            'id' => $itemId,
        ]);
    }

    public function removeChecklist(int $checklistId): void
    {
        $statement = $this->pdo->prepare('DELETE FROM case_checklists WHERE id = :id');
        $statement->execute(['id' => $checklistId]);
    }
}