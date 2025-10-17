<?php

declare(strict_types=1);

use App\Http\Response;
use App\Support\Repositories\PcBuildRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($pdo)) {
    require __DIR__ . '/bootstrap.php';
}

if (!function_exists('generatePcBuildPlanPdf')) {
    function generatePcBuildPlanPdf(PDO $pdo, int $buildId, ?string $targetPath = null): string
    {
        $repository = new PcBuildRepository($pdo);
        $details = $repository->buildDetails($buildId);
        $build = $details['build'] ?? [];
        $planning = is_array($build['planning_payload'] ?? null) ? $build['planning_payload'] : [];
        $components = is_array($planning['components'] ?? null) ? $planning['components'] : [];
        $currency = (string) ($build['planning_currency'] ?? 'PLN');
        $totalCents = (int) ($build['planning_total_cents'] ?? 0);
        $totalFormatted = number_format($totalCents / 100, 2, ',', ' ');

        $reference = (string) ($build['reference_code'] ?? '');
        $caseReference = (string) ($build['case_reference_code'] ?? '');
        $customer = (string) ($build['customer_name'] ?? '');
        $assignedEmployee = (string) ($build['assigned_employee'] ?? '');
        $summary = (string) ($build['summary'] ?? '');
        $notes = is_string($planning['notes'] ?? null) ? $planning['notes'] : '';

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <meta charset="UTF-8">
            <title>Plan budowy PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
            <style>
                body { font-family: "Helvetica", "Arial", sans-serif; color: #2c3e50; margin: 0; padding: 24px; }
                h1 { font-size: 22px; margin-bottom: 4px; }
                h2 { font-size: 18px; margin-top: 28px; margin-bottom: 8px; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 13px; }
                th { background: #f3f4f6; text-align: left; }
                .meta { margin-bottom: 16px; font-size: 13px; }
                .meta dt { font-weight: bold; }
                .meta dd { margin: 0 0 8px; }
                .total { text-align: right; font-weight: bold; margin-top: 12px; }
                .notes { margin-top: 16px; font-size: 13px; white-space: pre-wrap; }
            </style>
        </head>
        <body>
            <h1>Plan budowy PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <p class="meta">
                <strong>Case:</strong> <?= htmlspecialchars($caseReference !== '' ? $caseReference : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                <strong>Klient:</strong> <?= htmlspecialchars($customer !== '' ? $customer : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                <strong>Opiekun:</strong> <?= htmlspecialchars($assignedEmployee !== '' ? $assignedEmployee : 'nieprzydzielony', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                <?php if ($summary !== ''): ?>
                    <strong>Opis:</strong> <?= htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?><br>
                <?php endif; ?>
            </p>

            <h2>Lista komponentów</h2>
            <table>
                <thead>
                <tr>
                    <th>Kategoria</th>
                    <th>Komponent</th>
                    <th>Ilość</th>
                    <th>Cena jedn. (<?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>)</th>
                    <th>Wartość</th>
                    <th>Uwagi</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($components === []): ?>
                    <tr>
                        <td colspan="6">Brak zarejestrowanych komponentów w planie.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($components as $component): ?>
                        <?php
                            $category = (string) ($component['category'] ?? '');
                            $label = (string) ($component['label'] ?? ($component['name'] ?? 'Nieznany element'));
                            $quantity = (int) ($component['quantity'] ?? 1);
                            $unitCents = (int) ($component['unit_price_cents'] ?? 0);
                            $rowTotal = (int) ($component['total_price_cents'] ?? $unitCents * $quantity);
                            $componentNotes = (string) ($component['notes'] ?? '');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= $quantity ?></td>
                            <td><?= number_format($unitCents / 100, 2, ',', ' ') ?></td>
                            <td><?= number_format($rowTotal / 100, 2, ',', ' ') ?></td>
                            <td><?= htmlspecialchars($componentNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <p class="total">Łączny koszt: <?= $totalFormatted ?> <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>

            <?php if ($notes !== ''): ?>
                <div class="notes">
                    <h2>Uwagi do planu</h2>
                    <p><?= nl2br(htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                </div>
            <?php endif; ?>
        </body>
        </html>
        <?php
        $html = ob_get_clean();

        $options = new Options();
        $options->setChroot(__DIR__);
        $options->setIsRemoteEnabled(true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $output = $dompdf->output();
        if ($targetPath !== null) {
            $directory = dirname($targetPath);
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            file_put_contents($targetPath, $output);
            return $targetPath;
        }

        return $output;
    }
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        Response::error('Dozwolone są wyłącznie zapytania GET.', 405);
    }

    $buildId = filter_input(INPUT_GET, 'build_id', FILTER_VALIDATE_INT);
    if ($buildId === null || $buildId === false || $buildId <= 0) {
        Response::error('Nieprawidłowy identyfikator budowy PC.', 422);
    }

    try {
        $pdf = generatePcBuildPlanPdf($pdo, (int) $buildId);
    } catch (Throwable $exception) {
        Response::error('Nie udało się wygenerować dokumentu planu.', 500);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="pc-build-plan-' . (int) $buildId . '.pdf"');
    echo $pdf;
    exit;
}