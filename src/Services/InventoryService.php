<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Repositories\WarehouseRepository;

final class InventoryService
{
    public function __construct(private readonly WarehouseRepository $warehouseRepository)
    {
    }

    public function parsePriceToCents(string $value): ?int
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ($normalized === '') {
            return null;
        }

        if (!is_numeric($normalized)) {
            return null;
        }

        $floatValue = (float) $normalized;
        if (!is_finite($floatValue)) {
            return null;
        }

        $cents = (int) round($floatValue * 100);

        return $cents >= 0 ? $cents : null;
    }

    public function parsePercentage(string $value): float
    {
        $normalized = str_replace([' ', ','], ['', '.'], trim($value));
        if ($normalized === '' || !is_numeric($normalized)) {
            return 0.0;
        }

        $percent = (float) $normalized;
        if (!is_finite($percent)) {
            return 0.0;
        }

        return max(0.0, $percent);
    }

    public function calculateMargin(int $subtotalCents, float $marginPercent): int
    {
        if ($subtotalCents <= 0 || $marginPercent <= 0) {
            return 0;
        }

        $margin = (int) round($subtotalCents * ($marginPercent / 100));

        return max(0, $margin);
    }

    /**
     * @param array<string, mixed> $existingComponent
     * @return array{
     *     payload: array<string, mixed>,
     *     availability: array{available_quantity: ?int, missing_quantity: int, is_available: bool},
     *     errors: array<string, string>
     * }|null
     */
    public function prepareComponent(
        string $category,
        ?int $itemId,
        int $quantity,
        string $customLabel,
        ?int $manualUnitPriceCents,
        string $notes,
        array $existingComponent = []
    ): ?array {
        $quantity = $quantity > 0 ? $quantity : 0;
        if ($itemId === null && $customLabel === '' && $notes === '' && $quantity === 0 && $manualUnitPriceCents === null) {
            return null;
        }

        $quantity = max(1, $quantity);
        $existingItemId = isset($existingComponent['item_id']) ? (int) $existingComponent['item_id'] : null;
        $keepExistingAllocation = $existingItemId !== null && $existingItemId === $itemId;

        $reservedQuantity = $keepExistingAllocation ? max(0, (int) ($existingComponent['reserved_quantity'] ?? 0)) : 0;
        $consumedQuantity = $keepExistingAllocation ? max(0, (int) ($existingComponent['consumed_quantity'] ?? 0)) : 0;

        $payload = [
            'category' => $category,
            'label' => $customLabel !== '' ? $customLabel : (string) ($existingComponent['label'] ?? ''),
            'item_id' => $itemId,
            'item_reference' => null,
            'quantity' => $quantity,
            'unit_price_cents' => 0,
            'total_price_cents' => 0,
            'notes' => $notes,
            'reserved_quantity' => $reservedQuantity,
            'consumed_quantity' => $consumedQuantity,
            'available_quantity' => null,
            'price_source' => $manualUnitPriceCents !== null ? 'manual' : 'inventory',
        ];

        $availability = [
            'available_quantity' => null,
            'missing_quantity' => 0,
            'is_available' => true,
        ];
        $errors = [];

        if ($itemId !== null) {
            $item = $this->warehouseRepository->findItem($itemId);
            if ($item === null) {
                $errors['not_found'] = 'Wybrany komponent magazynowy nie istnieje.';
            } else {
                $itemName = (string) ($item['name'] ?? '');
                if ($payload['label'] === '') {
                    $payload['label'] = $itemName;
                }
                $payload['item_reference'] = isset($item['reference_code']) ? (string) $item['reference_code'] : null;
                $inventoryUnitPrice = (int) ($item['unit_price_cents'] ?? 0);
                $available = max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['reserved_quantity'] ?? 0));
                $payload['available_quantity'] = $available;
                $availability['available_quantity'] = $available;
                $availability['missing_quantity'] = max(0, $quantity - $available);
                $availability['is_available'] = $availability['missing_quantity'] === 0;

                if ($manualUnitPriceCents === null) {
                    $payload['unit_price_cents'] = max(0, $inventoryUnitPrice);
                }
            }
        }

        if ($manualUnitPriceCents !== null) {
            $payload['unit_price_cents'] = max(0, $manualUnitPriceCents);
            $payload['price_source'] = 'manual';
        }

        if ($payload['label'] === '') {
            $payload['label'] = $customLabel !== '' ? $customLabel : 'Komponent';
        }

        $payload['total_price_cents'] = $payload['unit_price_cents'] * $payload['quantity'];

        return [
            'payload' => $payload,
            'availability' => $availability,
            'errors' => $errors,
        ];
    }

    /**
     * @return array{updated_payload: array<string, mixed>, errors: array<int, string>}
     */
    public function reservePlannedComponents(array $planningPayload, int $buildId, ?int $caseId, string $performedBy): array
    {
        $components = is_array($planningPayload['components'] ?? null) ? $planningPayload['components'] : [];
        $operations = [];
        $errors = [];
        $updatedComponents = [];

        foreach ($components as $component) {
            $itemId = isset($component['item_id']) ? (int) $component['item_id'] : 0;
            $quantity = max(1, (int) ($component['quantity'] ?? 1));
            $consumed = max(0, (int) ($component['consumed_quantity'] ?? 0));
            $reserved = max(0, (int) ($component['reserved_quantity'] ?? 0));
            $label = (string) ($component['label'] ?? 'komponent');

            if ($itemId <= 0) {
                $updatedComponents[] = $component;
                continue;
            }

            $remaining = max(0, $quantity - $consumed);
            $alreadyReserved = min($reserved, $remaining);
            $toReserve = max(0, $remaining - $alreadyReserved);

            $item = $this->warehouseRepository->findItem($itemId);
            if ($item === null) {
                $errors[] = sprintf('Pozycja magazynowa #%d nie istnieje.', $itemId);
                $component['available_quantity'] = 0;
                $updatedComponents[] = $component;
                continue;
            }

            $available = max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['reserved_quantity'] ?? 0));
            $component['available_quantity'] = $available;

            if ($toReserve > 0 && $available < $toReserve) {
                $errors[] = sprintf(
                    'Brak wystarczającej liczby sztuk "%s" (potrzeba %d, dostępne %d).',
                    $label,
                    $remaining,
                    $available
                );
                $updatedComponents[] = $component;
                continue;
            }

            $component['reserved_quantity'] = $alreadyReserved + $toReserve;
            if ($toReserve > 0) {
                $component['last_reserved_at'] = date('c');
            }
            $component['available_quantity'] = $available - $toReserve;

            $operations[] = [
                'item_id' => $itemId,
                'quantity' => $toReserve,
            ];
            $updatedComponents[] = $component;
        }

        if ($errors !== []) {
            $planningPayload['components'] = $updatedComponents;

            return [
                'updated_payload' => $planningPayload,
                'errors' => $errors,
            ];
        }

        foreach ($operations as $operation) {
            if ($operation['quantity'] <= 0) {
                continue;
            }

            $note = sprintf('Rezerwacja na build PC #%d', $buildId);
            $this->warehouseRepository->recordMovement(
                (int) $operation['item_id'],
                'reserve',
                (int) $operation['quantity'],
                $caseId,
                $note,
                $performedBy
            );
        }

        $planningPayload['components'] = $updatedComponents;

        return [
            'updated_payload' => $planningPayload,
            'errors' => [],
        ];
    }

    /**
     * @return array{updated_payload: array<string, mixed>, errors: array<int, string>}
     */
    public function finalizePlannedComponents(array $planningPayload, int $buildId, ?int $caseId, string $performedBy): array
    {
        $components = is_array($planningPayload['components'] ?? null) ? $planningPayload['components'] : [];
        $operations = [];
        $errors = [];
        $updatedComponents = [];

        foreach ($components as $componentIndex => $component) {
            $itemId = isset($component['item_id']) ? (int) $component['item_id'] : 0;
            $quantity = max(1, (int) ($component['quantity'] ?? 1));
            $consumed = max(0, (int) ($component['consumed_quantity'] ?? 0));
            $reserved = max(0, (int) ($component['reserved_quantity'] ?? 0));
            $label = (string) ($component['label'] ?? 'komponent');

            if ($itemId <= 0) {
                $updatedComponents[] = $component;
                continue;
            }

            $toConsume = max(0, $quantity - $consumed);
            if ($toConsume === 0) {
                $updatedComponents[] = $component;
                continue;
            }

            $item = $this->warehouseRepository->findItem($itemId);
            if ($item === null) {
                $errors[] = sprintf('Pozycja magazynowa #%d nie istnieje.', $itemId);
                $component['available_quantity'] = 0;
                $updatedComponents[] = $component;
                continue;
            }

            $currentQuantity = max(0, (int) ($item['quantity'] ?? 0));
            $currentReserved = max(0, (int) ($item['reserved_quantity'] ?? 0));

            if ($currentReserved < $toConsume) {
                $errors[] = sprintf(
                    'Brak zarezerwowanych sztuk "%s" do zużycia (wymagane %d, zarezerwowane %d).',
                    $label,
                    $toConsume,
                    $currentReserved
                );
                $component['available_quantity'] = $currentQuantity - $currentReserved;
                $updatedComponents[] = $component;
                continue;
            }

            if ($currentQuantity < $toConsume) {
                $errors[] = sprintf(
                    'Brak fizycznych sztuk "%s" w magazynie (wymagane %d, dostępne %d).',
                    $label,
                    $toConsume,
                    $currentQuantity
                );
                $component['available_quantity'] = $currentQuantity - $currentReserved;
                $updatedComponents[] = $component;
                continue;
            }

            $releaseQuantity = min($toConsume, $reserved);
            $operations[] = [
                'item_id' => $itemId,
                'to_release' => $releaseQuantity,
                'to_consume' => $toConsume,
                'component_index' => $componentIndex,
            ];

            $component['reserved_quantity'] = max(0, $reserved - $releaseQuantity);
            $component['consumed_quantity'] = $consumed + $toConsume;
            $component['last_consumed_at'] = date('c');
            $updatedComponents[] = $component;
        }

        if ($errors !== []) {
            $planningPayload['components'] = $updatedComponents;

            return [
                'updated_payload' => $planningPayload,
                'errors' => $errors,
            ];
        }

        foreach ($operations as $operation) {
            if ($operation['to_release'] > 0) {
                $note = sprintf('Zużycie rezerwacji do buildu PC #%d', $buildId);
                $this->warehouseRepository->recordMovement(
                    (int) $operation['item_id'],
                    'release',
                    (int) $operation['to_release'],
                    $caseId,
                    $note,
                    $performedBy
                );
            }

            if ($operation['to_consume'] > 0) {
                $note = sprintf('Zużycie komponentu do buildu PC #%d', $buildId);
                $this->warehouseRepository->recordMovement(
                    (int) $operation['item_id'],
                    'outbound',
                    (int) $operation['to_consume'],
                    $caseId,
                    $note,
                    $performedBy
                );
            }
        }

        foreach ($operations as $operation) {
            $item = $this->warehouseRepository->findItem((int) $operation['item_id']);
            if ($item === null) {
                continue;
            }

            $available = max(0, (int) ($item['quantity'] ?? 0) - (int) ($item['reserved_quantity'] ?? 0));
            $updatedComponents[$operation['component_index']]['available_quantity'] = $available;
            $updatedComponents[$operation['component_index']]['reserved_quantity'] = (int) ($item['reserved_quantity'] ?? 0);
        }

        $planningPayload['components'] = $updatedComponents;

        return [
            'updated_payload' => $planningPayload,
            'errors' => [],
        ];
    }
}