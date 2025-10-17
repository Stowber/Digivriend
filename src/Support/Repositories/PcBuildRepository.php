<?php

declare(strict_types=1);

namespace App\Support\Repositories;

use App\Support\Clock;
use PDO;
use RuntimeException;
use JsonException;

final class PcBuildRepository
{
    /** @var array<string, string> */
    private const STATUS_LABELS = [
        'draft' => 'Szkic',
        'planning' => 'Planowanie',
         'assembly' => 'Montaż',
        'ready' => 'Do wydania',
        'approved' => 'Zatwierdzony',
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
            . 'COALESCE(build_cust.full_name, cust.full_name) AS customer_name, '
            . 'COALESCE(build_cust.email, cust.email) AS customer_email, '
            . 'COALESCE(build_cust.phone, cust.phone) AS customer_phone, '
            . 'hp.type AS profile_type, hp.manufacturer AS profile_manufacturer, '
            . 'hp.model AS profile_model '
            . 'FROM pc_builds pb '
            . 'LEFT JOIN cases c ON c.id = pb.case_id '
            . 'LEFT JOIN customers cust ON cust.id = c.customer_id '
            . 'LEFT JOIN customers build_cust ON build_cust.id = pb.customer_id '
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
            . 'LEFT JOIN customers build_cust ON build_cust.id = pb.customer_id '
            . 'LEFT JOIN hardware_profiles hp ON hp.id = pb.case_profile_id';

        $statement = $this->pdo->prepare(
            'SELECT pb.*, c.reference_code AS case_reference_code, c.summary AS case_summary, '
            . 'COALESCE(build_cust.full_name, cust.full_name) AS customer_name, '
            . 'COALESCE(build_cust.email, cust.email) AS customer_email, '
            . 'COALESCE(build_cust.phone, cust.phone) AS customer_phone, '
            . 'hp.type AS profile_type, hp.manufacturer AS profile_manufacturer, '
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
            'SELECT pb.*, c.reference_code AS case_reference_code, '
            . 'COALESCE(build_cust.full_name, cust.full_name) AS customer_name, '
            . 'COALESCE(build_cust.email, cust.email) AS customer_email, '
            . 'COALESCE(build_cust.phone, cust.phone) AS customer_phone '
            . 'FROM pc_builds pb '
            . 'LEFT JOIN cases c ON c.id = pb.case_id '
            . 'LEFT JOIN customers cust ON cust.id = c.customer_id '
            . 'LEFT JOIN customers build_cust ON build_cust.id = pb.customer_id '
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
        ?string $createdBy,
        ?int $customerId = null,
        ?string $assignedEmployee = null,
        string $currentStep = 'information'
    ): array {
        if (!$this->isValidStatus($status)) {
            $status = 'draft';
        }

        $now = Clock::nowFormatted();
        $reference = $this->generateReference();

        $statement = $this->pdo->prepare(
            'INSERT INTO pc_builds (reference_code, case_id, case_profile_id, customer_id, assigned_employee, status, current_step, summary, created_by, created_at, updated_at) '
            . 'VALUES (:reference_code, :case_id, :case_profile_id, :customer_id, :assigned_employee, :status, :current_step, :summary, :created_by, :created_at, :updated_at)'
        );

        $statement->execute([
            'reference_code' => $reference,
            'case_id' => $caseId,
            'case_profile_id' => $caseProfileId,
            'customer_id' => $customerId,
            'assigned_employee' => $this->normalizeNullableString($assignedEmployee),
            'status' => $status,
            'current_step' => $currentStep,
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

    public function updateInformation(
        int $buildId,
        int $caseId,
        ?int $caseProfileId,
        ?int $customerId,
        ?string $summary,
        ?string $assignedEmployee,
        ?string $username = null
    ): void {
        $this->requireEditableBuild($buildId);

        $columns = [
            'case_id' => $caseId,
            'case_profile_id' => $caseProfileId,
            'customer_id' => $customerId,
            'summary' => $this->normalizeNullableString($summary),
            'assigned_employee' => $this->normalizeNullableString($assignedEmployee),
            'current_step' => 'information',
        ];

        $this->updateBuildColumns($buildId, $columns);

        $payload = [
            'case_id' => $caseId,
            'case_profile_id' => $caseProfileId,
            'customer_id' => $customerId,
            'summary' => $summary,
            'assigned_employee' => $assignedEmployee,
        ];

        $this->saveWorkflowStep($buildId, 'information', $payload, $username);
        $this->recordJournalEntry($buildId, 'information', 'update', 'Zaktualizowano dane podstawowe buildu.', $payload, $username);
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

        $build['planning_payload'] = $this->decodePayload($build['planning_payload'] ?? null);
        $build['assembly_payload'] = $this->decodePayload($build['assembly_payload'] ?? null);
        $build['release_payload'] = $this->decodePayload($build['release_payload'] ?? null);
        $build['planning_total_cents'] = (int) ($build['planning_total_cents'] ?? 0);
        $build['planning_currency'] = (string) ($build['planning_currency'] ?? 'PLN');

        $components = $this->fetchComponents($buildId);
        $leftovers = $this->fetchLeftovers($buildId);
        $workflow = $this->fetchWorkflow($buildId);
        $journal = $this->fetchJournal($buildId);

        return [
            'build' => $build,
            'components' => $components,
            'leftovers' => $leftovers,
            'workflow' => $workflow,
            'journal' => $journal,
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

    public function workflowEntries(int $buildId): array
    {
        return $this->fetchWorkflow($buildId);
    }

    public function journalEntries(int $buildId): array
    {
        return $this->fetchJournal($buildId);
    }

    public function savePlanningData(int $buildId, array $planningData, int $totalCostCents, string $currency, ?string $username = null): void
    {
        $build = $this->requireEditableBuild($buildId);
        $normalizedCurrency = strtoupper(trim($currency));
        if ($normalizedCurrency === '') {
            $normalizedCurrency = 'PLN';
        }

        $status = (string) ($build['status'] ?? 'draft');
        if ($status === 'draft') {
            $status = 'planning';
        }

        $this->updateBuildColumns($buildId, [
            'planning_payload' => $this->encodePayload($planningData),
            'planning_total_cents' => max(0, $totalCostCents),
            'planning_currency' => $normalizedCurrency,
            'current_step' => 'planning',
            'status' => $status,
        ]);

        $this->saveWorkflowStep($buildId, 'planning', $planningData, $username);
        $this->recordJournalEntry($buildId, 'planning', 'update', 'Zaktualizowano plan komponentów.', $planningData, $username);
    }

    public function saveAssemblyData(int $buildId, array $assemblyData, ?string $username = null): void
    {
        $build = $this->requireEditableBuild($buildId);
        $status = (string) ($build['status'] ?? 'draft');
        if ($status === 'draft' || $status === 'planning') {
            $status = 'assembly';
        }

        $this->updateBuildColumns($buildId, [
            'assembly_payload' => $this->encodePayload($assemblyData),
            'current_step' => 'assembly',
            'status' => $status === 'assembly' ? 'assembly' : $status,
        ]);

        $this->saveWorkflowStep($buildId, 'assembly', $assemblyData, $username);
        $this->recordJournalEntry($buildId, 'assembly', 'update', 'Zaktualizowano checklistę montażu.', $assemblyData, $username);
    }

    public function saveReleaseData(int $buildId, array $releaseData, ?string $username = null): void
    {
        $build = $this->requireEditableBuild($buildId);
        $now = Clock::nowFormatted();

        $this->updateBuildColumns($buildId, [
            'release_payload' => $this->encodePayload($releaseData),
            'current_step' => 'release',
            'status' => 'approved',
            'approved_at' => $now,
            'approved_by' => $this->normalizeNullableString($username),
        ]);

        $this->saveWorkflowStep($buildId, 'release', $releaseData, $username);
        $this->recordJournalEntry($buildId, 'release', 'update', 'Zapisano dane wydania zestawu.', $releaseData, $username);
    }

    private function requireEditableBuild(int $buildId): array
    {
        $build = $this->findBuild($buildId);
        if ($build === null) {
            throw new RuntimeException('Budowa PC nie istnieje.');
        }

        if (isset($build['status']) && (string) $build['status'] === 'approved') {
            throw new RuntimeException('Budowa PC została zatwierdzona i nie można jej modyfikować.');
        }

        return $build;
    }

    private function saveWorkflowStep(int $buildId, string $step, array $payload, ?string $completedBy = null): void
    {
        $encoded = $this->encodePayload($payload);
        $now = Clock::nowFormatted();
        $completedAt = $completedBy !== null ? $now : null;

        $update = $this->pdo->prepare(
            'UPDATE pc_build_workflow SET payload = :payload, completed_at = :completed_at, completed_by = :completed_by, updated_at = :updated_at ' .
            'WHERE build_id = :build_id AND step = :step'
        );

        $update->execute([
            'payload' => $encoded,
            'completed_at' => $completedAt,
            'completed_by' => $this->normalizeNullableString($completedBy),
            'updated_at' => $now,
            'build_id' => $buildId,
            'step' => $step,
        ]);

        if ($update->rowCount() === 0) {
            $insert = $this->pdo->prepare(
                'INSERT INTO pc_build_workflow (build_id, step, payload, completed_at, completed_by, created_at, updated_at) ' .
                'VALUES (:build_id, :step, :payload, :completed_at, :completed_by, :created_at, :updated_at)'
            );

            $insert->execute([
                'build_id' => $buildId,
                'step' => $step,
                'payload' => $encoded,
                'completed_at' => $completedAt,
                'completed_by' => $this->normalizeNullableString($completedBy),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function recordJournalEntry(int $buildId, string $step, string $entryType, string $message, array $data, ?string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO pc_build_journal (build_id, step, entry_type, message, data, created_by, created_at) ' .
            'VALUES (:build_id, :step, :entry_type, :message, :data, :created_by, :created_at)'
        );

        $statement->execute([
            'build_id' => $buildId,
            'step' => $step,
            'entry_type' => $entryType,
            'message' => $message,
            'data' => $this->encodePayload($data),
            'created_by' => $this->normalizeNullableString($username),
            'created_at' => Clock::nowFormatted(),
        ]);
    }

    private function fetchWorkflow(int $buildId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pc_build_workflow WHERE build_id = :build_id ORDER BY step');
        $statement->execute(['build_id' => $buildId]);
        $rows = $statement->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['payload'] = $this->decodePayload($row['payload'] ?? null);
        }

        return $rows;
    }

    private function fetchJournal(int $buildId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM pc_build_journal WHERE build_id = :build_id ORDER BY created_at ASC');
        $statement->execute(['build_id' => $buildId]);
        $rows = $statement->fetchAll() ?: [];

        foreach ($rows as &$row) {
            $row['data'] = $this->decodePayload($row['data'] ?? null);
        }

        return $rows;
    }

    private function updateBuildColumns(int $buildId, array $columns): void
    {
        if ($columns === []) {
            return;
        }

        $columns['updated_at'] = Clock::nowFormatted();
        $setParts = [];
        foreach ($columns as $column => $_) {
            $setParts[] = $column . ' = :' . $column;
        }

        $sql = 'UPDATE pc_builds SET ' . implode(', ', $setParts) . ' WHERE id = :id';
        $statement = $this->pdo->prepare($sql);
        $columns['id'] = $buildId;
        $statement->execute($columns);
    }

    private function encodePayload(array $payload): string
    {
        try {
            return json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Nie udało się zakodować danych kroku budowy.', 0, $exception);
        }
    }

    private function decodePayload($payload): array
    {
        if (!is_string($payload) || $payload === '') {
            return [];
        }

        try {
            $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
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