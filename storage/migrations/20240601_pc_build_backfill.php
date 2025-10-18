<?php

declare(strict_types=1);

use App\Support\Clock;

require __DIR__ . '/../../bootstrap.php';

/** @var PDO $pdo */
$pdo = require __DIR__ . '/../../database.php';
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

function decodePayload(?string $payload): array
{
    if (!is_string($payload) || trim($payload) === '') {
        return [];
    }

    try {
        $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
    } catch (\JsonException $exception) {
        fwrite(STDERR, sprintf("[WARN] Nie można zdekodować danych kroku: %s\n", $exception->getMessage()));

        return [];
    }

    return is_array($decoded) ? $decoded : [];
}

function ensureWorkflowStep(PDO $pdo, int $buildId, string $step, array $payload, ?string $completedBy, ?string $completedAt): void
{
    $now = Clock::nowFormatted();
    $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

    $select = $pdo->prepare('SELECT id, payload, completed_at, completed_by FROM pc_build_workflow WHERE build_id = :build_id AND step = :step LIMIT 1');
    $select->execute(['build_id' => $buildId, 'step' => $step]);
    $existing = $select->fetch(\PDO::FETCH_ASSOC);

    if ($existing !== false) {
        $shouldUpdatePayload = trim((string) ($existing['payload'] ?? '')) === '' && $encoded !== '[]';
        $hasCompletedAt = isset($existing['completed_at']) && $existing['completed_at'] !== null && $existing['completed_at'] !== '';
        $hasCompletedBy = isset($existing['completed_by']) && $existing['completed_by'] !== null && $existing['completed_by'] !== '';
        $update = $pdo->prepare('UPDATE pc_build_workflow SET payload = CASE WHEN payload IS NULL OR payload = \'\' THEN :payload ELSE payload END, completed_at = COALESCE(completed_at, :completed_at), completed_by = COALESCE(completed_by, :completed_by), updated_at = :updated_at WHERE id = :id');

        if ($shouldUpdatePayload || (!$hasCompletedAt && $completedAt !== null) || (!$hasCompletedBy && $completedBy !== null)) {
            $update->execute([
                'payload' => $encoded,
                'completed_at' => $completedAt,
                'completed_by' => $completedBy,
                'updated_at' => $now,
                'id' => (int) $existing['id'],
            ]);
        }

        return;
    }

    $insert = $pdo->prepare('INSERT INTO pc_build_workflow (build_id, step, payload, completed_at, completed_by, created_at, updated_at) VALUES (:build_id, :step, :payload, :completed_at, :completed_by, :created_at, :updated_at)');
    $insert->execute([
        'build_id' => $buildId,
        'step' => $step,
        'payload' => $encoded,
        'completed_at' => $completedAt,
        'completed_by' => $completedBy,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}

function syncComponentsFromPlanning(PDO $pdo, int $buildId, array $planningPayload): void
{
    $countStatement = $pdo->prepare('SELECT COUNT(*) FROM pc_build_components WHERE build_id = :build_id');
    $countStatement->execute(['build_id' => $buildId]);
    $existingCount = (int) $countStatement->fetchColumn();
    if ($existingCount > 0) {
        return;
    }

    $components = is_array($planningPayload['components'] ?? null) ? $planningPayload['components'] : [];
    if ($components === []) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO pc_build_components (build_id, item_id, quantity, notes, created_at) VALUES (:build_id, :item_id, :quantity, :notes, :created_at)');

    foreach ($components as $component) {
        $itemId = isset($component['item_id']) ? filter_var($component['item_id'], FILTER_VALIDATE_INT) : false;
        if ($itemId === false || $itemId <= 0) {
            continue;
        }

        $insert->execute([
            'build_id' => $buildId,
            'item_id' => (int) $itemId,
            'quantity' => max(1, (int) ($component['quantity'] ?? 1)),
            'notes' => isset($component['notes']) && $component['notes'] !== '' ? (string) $component['notes'] : null,
            'created_at' => Clock::nowFormatted(),
        ]);
    }
}

function ensureMigrationJournal(PDO $pdo, int $buildId): void
{
    $check = $pdo->prepare('SELECT 1 FROM pc_build_journal WHERE build_id = :build_id LIMIT 1');
    $check->execute(['build_id' => $buildId]);
    if ($check->fetchColumn()) {
        return;
    }

    $insert = $pdo->prepare('INSERT INTO pc_build_journal (build_id, step, entry_type, message, data, created_by, created_at) VALUES (:build_id, :step, :entry_type, :message, :data, :created_by, :created_at)');
    $insert->execute([
        'build_id' => $buildId,
        'step' => 'migration',
        'entry_type' => 'migration',
        'message' => 'Zaimportowano historyczne dane buildu podczas migracji schematu.',
        'data' => json_encode(['source' => '20240601_pc_build_backfill'], JSON_THROW_ON_ERROR),
        'created_by' => 'system',
        'created_at' => Clock::nowFormatted(),
    ]);
}

$buildStatement = $pdo->query('SELECT * FROM pc_builds ORDER BY id');
$processed = 0;

foreach ($buildStatement->fetchAll(\PDO::FETCH_ASSOC) as $build) {
    $buildId = (int) ($build['id'] ?? 0);
    if ($buildId <= 0) {
        continue;
    }

    $informationPayload = [
        'case_id' => isset($build['case_id']) ? (int) $build['case_id'] : null,
        'case_profile_id' => isset($build['case_profile_id']) ? (int) $build['case_profile_id'] : null,
        'customer_id' => isset($build['customer_id']) ? (int) $build['customer_id'] : null,
        'summary' => $build['summary'] ?? null,
        'assigned_employee' => $build['assigned_employee'] ?? null,
    ];

    ensureWorkflowStep(
        $pdo,
        $buildId,
        'information',
        $informationPayload,
        $build['created_by'] ?? $build['assigned_employee'] ?? null,
        $build['created_at'] ?? null
    );

    $planningPayload = decodePayload($build['planning_payload'] ?? null);
    if ($planningPayload !== []) {
        ensureWorkflowStep(
            $pdo,
            $buildId,
            'planning',
            $planningPayload,
            $build['assigned_employee'] ?? null,
            $build['updated_at'] ?? null
        );
        syncComponentsFromPlanning($pdo, $buildId, $planningPayload);
    }

    $assemblyPayload = decodePayload($build['assembly_payload'] ?? null);
    if ($assemblyPayload !== []) {
        ensureWorkflowStep(
            $pdo,
            $buildId,
            'assembly',
            $assemblyPayload,
            $build['assigned_employee'] ?? null,
            $build['updated_at'] ?? null
        );
    }

    $releasePayload = decodePayload($build['release_payload'] ?? null);
    if ($releasePayload !== []) {
        ensureWorkflowStep(
            $pdo,
            $buildId,
            'release',
            $releasePayload,
            $build['approved_by'] ?? null,
            $build['approved_at'] ?? null
        );
    }

    ensureMigrationJournal($pdo, $buildId);
    $processed++;
}

echo sprintf("Zakończono migrację danych PC buildera. Przetworzono %d buildów.\n", $processed);