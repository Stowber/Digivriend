<?php

declare(strict_types=1);

use App\Http\Response;
use App\Support\Repositories\PcBuildRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($pdo)) {
    require __DIR__ . '/bootstrap.php';
}

if (!function_exists('generatePcBuildSummaryPdf')) {
    function generatePcBuildSummaryPdf(PDO $pdo, int $buildId, ?string $targetPath = null): string
    {
        $repository = new PcBuildRepository($pdo);
        $details = $repository->buildDetails($buildId);
        $build = $details['build'] ?? [];
        $planning = is_array($build['planning_payload'] ?? null) ? $build['planning_payload'] : [];
        $financials = is_array($planning['financials'] ?? null) ? $planning['financials'] : [];
        $components = is_array($planning['components'] ?? null) ? $planning['components'] : [];

        $status = (string) ($build['status'] ?? '');
        $statusLabels = $repository->statusLabels();
        $statusLabel = $statusLabels[$status] ?? ($status !== '' ? $status : 'Nieznany');
        $reference = (string) ($build['reference_code'] ?? '');
        $caseReference = (string) ($build['case_reference_code'] ?? '');
        $caseSummary = (string) ($build['case_summary'] ?? '');
        $customer = (string) ($build['customer_name'] ?? '');
        $customerEmail = (string) ($build['customer_email'] ?? '');
        $customerPhone = (string) ($build['customer_phone'] ?? '');
        $assignedEmployee = (string) ($build['assigned_employee'] ?? '');
        $summary = (string) ($build['summary'] ?? '');
        $currency = (string) ($build['planning_currency'] ?? 'PLN');
        $totalCents = (int) ($build['planning_total_cents'] ?? 0);
        $subtotalCents = (int) ($financials['subtotal_cents'] ?? $totalCents);
        $marginCents = (int) ($financials['margin_cents'] ?? max(0, $totalCents - $subtotalCents));
        $marginPercent = (float) ($financials['margin_percent'] ?? 0.0);
        $createdAt = (string) ($build['created_at'] ?? '');
        $updatedAt = (string) ($build['updated_at'] ?? '');

        $totalFormatted = number_format($totalCents / 100, 2, ',', ' ');
        $subtotalFormatted = number_format($subtotalCents / 100, 2, ',', ' ');
        $marginFormatted = number_format($marginCents / 100, 2, ',', ' ');
        $marginPercentFormatted = rtrim(rtrim(number_format($marginPercent, 2, ',', ' '), '0'), ',');
        if ($marginPercentFormatted === '') {
            $marginPercentFormatted = '0';
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <meta charset="UTF-8">
            <title>Podsumowanie budowy PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
            <style>
                body { font-family: "Helvetica", "Arial", sans-serif; color: #1f2933; margin: 0; padding: 26px; }
                h1 { font-size: 22px; margin-bottom: 8px; }
                h2 { font-size: 17px; margin-top: 24px; margin-bottom: 6px; }
                p, li { font-size: 13px; line-height: 1.4; }
                .meta { font-size: 13px; margin-bottom: 18px; }
                .meta strong { display: inline-block; min-width: 140px; }
                table { width: 100%; border-collapse: collapse; margin-top: 12px; }
                th, td { border: 1px solid #d1d5db; padding: 8px; font-size: 12px; }
                th { background: #f9fafb; text-align: left; }
                .notes { margin-top: 16px; white-space: pre-wrap; }
            </style>
        </head>
        <body>
            <h1>Podsumowanie budowy PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <div class="meta">
                <p><strong>Status:</strong> <?= htmlspecialchars($statusLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Case:</strong> <?= htmlspecialchars($caseReference !== '' ? $caseReference : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($caseSummary !== ''): ?><p><strong>Opis sprawy:</strong> <?= htmlspecialchars($caseSummary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <p><strong>Klient:</strong> <?= htmlspecialchars($customer !== '' ? $customer : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($customerEmail !== ''): ?><p><strong>E-mail:</strong> <?= htmlspecialchars($customerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <?php if ($customerPhone !== ''): ?><p><strong>Telefon:</strong> <?= htmlspecialchars($customerPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <p><strong>Opiekun:</strong> <?= htmlspecialchars($assignedEmployee !== '' ? $assignedEmployee : 'nieprzydzielony', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($createdAt !== ''): ?><p><strong>Utworzono:</strong> <?= htmlspecialchars($createdAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <?php if ($updatedAt !== '' && $updatedAt !== $createdAt): ?><p><strong>Aktualizacja:</strong> <?= htmlspecialchars($updatedAt, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
            </div>

            <?php if ($summary !== ''): ?>
                <div class="notes">
                    <h2>Opis wymagania klienta</h2>
                    <p><?= nl2br(htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                </div>
            <?php endif; ?>

            <h2>Parametry finansowe</h2>
            <table>
                <tbody>
                <tr>
                    <th>Wartość komponentów</th>
                    <td><?= $subtotalFormatted ?> <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                </tr>
                <tr>
                    <th>Marża</th>
                    <td><?= $marginFormatted ?> <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> (<?= htmlspecialchars($marginPercentFormatted, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>%)</td>
                </tr>
                <tr>
                    <th>Łączny koszt</th>
                    <td><strong><?= $totalFormatted ?> <?= htmlspecialchars($currency, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></strong></td>
                </tr>
                </tbody>
            </table>

            <h2>Kluczowe komponenty</h2>
            <table>
                <thead>
                <tr>
                    <th>Kategoria</th>
                    <th>Opis</th>
                    <th>Ilość</th>
                    <th>Uwagi</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($components === []): ?>
                    <tr>
                        <td colspan="4">Brak zapisanych komponentów na tym etapie.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($components as $component): ?>
                        <?php
                            $category = (string) ($component['category'] ?? '');
                            $label = (string) ($component['label'] ?? ($component['name'] ?? 'Nieznany element'));
                            $quantity = (int) ($component['quantity'] ?? 1);
                            $notes = (string) ($component['notes'] ?? '');
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= $quantity ?></td>
                            <td><?= htmlspecialchars($notes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
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
        $pdf = generatePcBuildSummaryPdf($pdo, (int) $buildId);
    } catch (Throwable $exception) {
        Response::error('Nie udało się wygenerować podsumowania buildu.', 500);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="pc-build-summary-' . (int) $buildId . '.pdf"');
    echo $pdf;
    exit;
}