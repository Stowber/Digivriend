<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;
use RuntimeException;

final class PcBuildRepository
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'planning' => 'Planowanie',
        'in_progress' => 'W trakcie',
        'completed' => 'Zakończono',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<string, string>
     */
    public function statusLabels(): array
    {
        return self::STATUS_LABELS;
    }

    public function isValidStatus(string $status): bool
    {
        return isset(self::STATUS_LABELS[$status]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBuilds(?int $caseId = null, int $limit = 50): array
    {
        $sql = 'SELECT pb.*, c.reference_code AS case_reference_code, c.summary AS case_summary, '
            . 'cust.full_name AS customer_name, hp.type AS profile_type, hp.manufacturer AS profile_manufacturer, '
            . 'hp.model AS profile_model '
            . 'FROM pc_builds pb '
            . 'LEFT JOIN cases c ON c.id = pb.case_id '
            . 'LEFT JOIN customers cust ON cust.id = c.customer_id '
            . 'LEFT JOIN hardware_profiles hp ON hp.id = pb.case_profile_id';

        $params = [];
        if ($caseId !== null) {
            $sql .= ' WHERE pb.case_id = :case_id';
            $params['case_id'] = $caseId;
        }

        $sql .= ' ORDER BY pb.updated_at DESC LIMIT :limit';
        $statement = $this->pdo->prepare($sql);

        foreach ($params as $key => $value) {
            $statement->bindValue($key, $value, PDO::PARAM_INT);
        }

        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function paginatedBuilds(int $page, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $baseSelect = 'FROM pc_builds pb '
            . 'LEFT JOIN cases c ON c.id = pb.case_id '
            . 'LEFT JOIN customers cust ON cust.id = c.customer_id '
            . 'LEFT JOIN hardware_profiles hp ON hp.id = pb.case_profile_id';

        $statement = $this->pdo->prepare(
            'SELECT pb.*, c.reference_code AS case_reference_code, c.summary AS case_summary, '
            . 'cust.full_name AS customer_name, hp.type AS profile_type, hp.manufacturer AS profile_manufacturer, '
            . 'hp.model AS profile_model '
            . $baseSelect
            . ' ORDER BY pb.updated_at DESC LIMIT :limit OFFSET :offset'
        );

        $statement->bindValue('limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue('offset', $offset, PDO::PARAM_INT);
        $statement->execute();

        $items = $statement->fetchAll() ?: [];

        $countStatement = $this->pdo->query('SELECT COUNT(*) FROM pc_builds');
        $total = 0;
        if ($countStatement !== false) {
            $countValue = $countStatement->fetchColumn();
            $total = (int) ($countValue ?: 0);
        }

        $totalPages = $perPage > 0 ? (int) ceil($total / $perPage) : 1;
        if ($totalPages < 1) {
            $totalPages = 1;
        }

        return [
            'items' => $items,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ];
    }

    public function findBuild(int $buildId): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT pb.*, c.reference_code AS case_reference_code, cust.full_name AS customer_name '
            . 'FROM pc_builds pb '
            . 'LEFT JOIN cases c ON c.id = pb.case_id '
            . 'LEFT JOIN customers cust ON cust.id = c.customer_id '
            . 'WHERE pb.id = :id'
        );
        $statement->execute(['id' => $buildId]);
        $row = $statement->fetch();

        return $row !== false ? $row : null;
    }

    public function createBuild(
        int $caseId,
        ?int $caseProfileId,
        string $status,
        ?string $summary,
        ?string $createdBy
    ): array {
        if (!$this->isValidStatus($status)) {
            $status = 'planning';
        }

        $now = Clock::nowFormatted();
        $reference = $this->generateReference();

        $statement = $this->pdo->prepare(
            'INSERT INTO pc_builds (reference_code, case_id, case_profile_id, status, summary, created_by, created_at, updated_at) '
            . 'VALUES (:reference_code, :case_id, :case_profile_id, :status, :summary, :created_by, :created_at, :updated_at)'
        );

        $statement->execute([
            'reference_code' => $reference,
            'case_id' => $caseId,
            'case_profile_id' => $caseProfileId,
            'status' => $status,
            'summary' => $this->normalizeNullableString($summary),
            'created_by' => $this->normalizeNullableString($createdBy),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $build = $this->findBuild($id);
        if ($build === null) {
            throw new RuntimeException('Nie udało się utworzyć zlecenia budowy PC.');
        }

        return $build;
    }

    public function updateStatus(int $buildId, string $status): bool
    {
        if (!$this->isValidStatus($status)) {
            return false;
        }

        $statement = $this->pdo->prepare(
            'UPDATE pc_builds SET status = :status, updated_at = :updated_at WHERE id = :id'
        );

        return $statement->execute([
            'status' => $status,
            'updated_at' => Clock::nowFormatted(),
            'id' => $buildId,
        ]);
    }

    public function addComponent(int $buildId, int $itemId, int $quantity, ?string $notes = null): void
    {
        $quantity = max(1, $quantity);

        $statement = $this->pdo->prepare(
            'INSERT INTO pc_build_components (build_id, item_id, quantity, notes, created_at) '
            . 'VALUES (:build_id, :item_id, :quantity, :notes, :created_at)'
        );

        $statement->execute([
            'build_id' => $buildId,
            'item_id' => $itemId,
            'quantity' => $quantity,
            'notes' => $this->normalizeNullableString($notes),
            'created_at' => Clock::nowFormatted(),
        ]);
    }

    public function addLeftover(
        int $buildId,
        int $itemId,
        ?int $profileId,
        int $quantity,
        ?string $notes = null
    ): void {
        $quantity = max(0, $quantity);

        $statement = $this->pdo->prepare(
            'INSERT INTO pc_build_leftovers (build_id, item_id, profile_id, quantity, notes, created_at) '
            . 'VALUES (:build_id, :item_id, :profile_id, :quantity, :notes, :created_at)'
        );

        $statement->execute([
            'build_id' => $buildId,
            'item_id' => $itemId,
            'profile_id' => $profileId,
            'quantity' => $quantity,
            'notes' => $this->normalizeNullableString($notes),
            'created_at' => Clock::nowFormatted(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function buildDetails(int $buildId): array
    {
        $build = $this->findBuild($buildId);
        if ($build === null) {
            throw new RuntimeException('Budowa PC nie istnieje.');
        }

        $components = $this->fetchComponents($buildId);
        $leftovers = $this->fetchLeftovers($buildId);

        return [
            'build' => $build,
            'components' => $components,
            'leftovers' => $leftovers,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function metrics(): array
    {
        $statusCounts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        $otherStatuses = 0;

        $statusStatement = $this->pdo->query('SELECT status, COUNT(*) AS total FROM pc_builds GROUP BY status');
        if ($statusStatement !== false) {
            foreach ($statusStatement->fetchAll() ?: [] as $row) {
                $status = (string) ($row['status'] ?? '');
                $count = (int) ($row['total'] ?? 0);

                if ($status === '' || $count <= 0) {
                    continue;
                }

                if (isset($statusCounts[$status])) {
                    $statusCounts[$status] = $count;
                } else {
                    $otherStatuses += $count;
                }
            }
        }

        $totalBuilds = array_sum($statusCounts) + $otherStatuses;

        $componentStatement = $this->pdo->query('SELECT COALESCE(SUM(quantity), 0) AS total FROM pc_build_components');
        $componentCount = 0;
        if ($componentStatement !== false) {
            $componentValue = $componentStatement->fetchColumn();
            $componentCount = (int) ($componentValue ?: 0);
        }

        $leftoverStatement = $this->pdo->query('SELECT COALESCE(SUM(quantity), 0) AS total FROM pc_build_leftovers');
        $leftoverCount = 0;
        if ($leftoverStatement !== false) {
            $leftoverValue = $leftoverStatement->fetchColumn();
            $leftoverCount = (int) ($leftoverValue ?: 0);
        }

        $activeCasesStatement = $this->pdo->query(
            "SELECT COUNT(DISTINCT case_id) FROM pc_builds WHERE status != 'completed' AND case_id IS NOT NULL"
        );
        $activeCases = 0;
        if ($activeCasesStatement !== false) {
            $activeCasesValue = $activeCasesStatement->fetchColumn();
            $activeCases = (int) ($activeCasesValue ?: 0);
        }

        $latestStatement = $this->pdo->query(
            'SELECT reference_code, created_at, updated_at, status FROM pc_builds ORDER BY updated_at DESC LIMIT 1'
        );
        $latestBuild = null;
        if ($latestStatement !== false) {
            $latestRow = $latestStatement->fetch();
            if ($latestRow !== false) {
                $latestBuild = [
                    'reference_code' => (string) ($latestRow['reference_code'] ?? ''),
                    'status' => (string) ($latestRow['status'] ?? ''),
                    'updated_at' => (string) ($latestRow['updated_at'] ?? ''),
                    'created_at' => (string) ($latestRow['created_at'] ?? ''),
                ];
            }
        }

        return [
            'status_counts' => $statusCounts,
            'other_statuses' => $otherStatuses,
            'total_builds' => $totalBuilds,
            'total_components' => $componentCount,
            'total_leftovers' => $leftoverCount,
            'active_cases' => $activeCases,
            'latest_build' => $latestBuild,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchComponents(int $buildId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pbc.*, wi.name AS item_name, wi.reference_code AS item_reference, wi.barcode AS item_barcode '
            . 'FROM pc_build_components pbc '
            . 'INNER JOIN warehouse_items wi ON wi.id = pbc.item_id '
            . 'WHERE pbc.build_id = :build_id ORDER BY pbc.created_at DESC'
        );
        $statement->execute(['build_id' => $buildId]);

        return $statement->fetchAll() ?: [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchLeftovers(int $buildId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT pbl.*, wi.name AS item_name, wi.reference_code AS item_reference, wi.barcode AS item_barcode, '
            . 'hp.type AS profile_type, hp.manufacturer AS profile_manufacturer, hp.model AS profile_model '
            . 'FROM pc_build_leftovers pbl '
            . 'INNER JOIN warehouse_items wi ON wi.id = pbl.item_id '
            . 'LEFT JOIN hardware_profiles hp ON hp.id = pbl.profile_id '
            . 'WHERE pbl.build_id = :build_id ORDER BY pbl.created_at DESC'
        );
        $statement->execute(['build_id' => $buildId]);

        return $statement->fetchAll() ?: [];
    }

    private function generateReference(): string
    {
        do {
            $candidate = 'PCB' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
        } while ($this->referenceExists($candidate));

        return $candidate;
    }

    private function referenceExists(string $reference): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM pc_builds WHERE reference_code = :reference LIMIT 1');
        $statement->execute(['reference' => $reference]);

        return (bool) $statement->fetchColumn();
    }

    private function normalizeNullableString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}