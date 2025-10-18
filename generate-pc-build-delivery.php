<?php

declare(strict_types=1);

use App\Http\Response;
use App\Support\Repositories\PcBuildRepository;
use Dompdf\Dompdf;
use Dompdf\Options;

if (!isset($pdo)) {
    require __DIR__ . '/bootstrap.php';
}

if (!function_exists('generatePcBuildDeliveryPdf')) {
    function generatePcBuildDeliveryPdf(PDO $pdo, int $buildId, ?string $targetPath = null): string
    {
        $repository = new PcBuildRepository($pdo);
        $details = $repository->buildDetails($buildId);
        $build = $details['build'] ?? [];
        $release = is_array($build['release_payload'] ?? null) ? $build['release_payload'] : [];
        $planning = is_array($build['planning_payload'] ?? null) ? $build['planning_payload'] : [];

        $reference = (string) ($build['reference_code'] ?? '');
        $caseReference = (string) ($build['case_reference_code'] ?? '');
        $customer = (string) ($build['customer_name'] ?? '');
        $customerEmail = (string) ($build['customer_email'] ?? '');
        $customerPhone = (string) ($build['customer_phone'] ?? '');
        $releaseDate = (string) ($release['release_date'] ?? date('Y-m-d'));
        $deliveryMethod = (string) ($release['delivery_method'] ?? 'odbiór w salonie');
        $deliveryNotes = (string) ($release['delivery_details'] ?? '');
        $releaseNotes = (string) ($release['notes'] ?? '');
        $signaturePath = (string) ($release['signature_path'] ?? '');
        $components = is_array($planning['components'] ?? null) ? $planning['components'] : [];

        $signatureImage = null;
        if ($signaturePath !== '') {
            $fullPath = str_starts_with($signaturePath, DIRECTORY_SEPARATOR)
                ? $signaturePath
                : __DIR__ . '/' . ltrim($signaturePath, '/');
            if (is_file($fullPath)) {
                $imageData = file_get_contents($fullPath);
                if ($imageData !== false) {
                    $signatureImage = 'data:image/png;base64,' . base64_encode($imageData);
                }
            }
        }

        ob_start();
        ?>
        <!DOCTYPE html>
        <html lang="pl">
        <head>
            <meta charset="UTF-8">
            <title>Potwierdzenie odbioru PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></title>
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
                .signature { margin-top: 24px; border: 1px solid #d1d5db; padding: 12px; min-height: 140px; }
                .signature img { max-height: 110px; }
                .acknowledgement { margin-top: 16px; background: #f3f4f6; padding: 12px; font-size: 12px; }
                .notes { margin-top: 16px; white-space: pre-wrap; }
            </style>
        </head>
        <body>
            <h1>Potwierdzenie odbioru zestawu PC <?= htmlspecialchars($reference !== '' ? $reference : '#' . $buildId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></h1>
            <div class="meta">
                <p><strong>Case:</strong> <?= htmlspecialchars($caseReference !== '' ? $caseReference : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Klient:</strong> <?= htmlspecialchars($customer !== '' ? $customer : 'brak', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($customerEmail !== ''): ?><p><strong>E-mail:</strong> <?= htmlspecialchars($customerEmail, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <?php if ($customerPhone !== ''): ?><p><strong>Telefon:</strong> <?= htmlspecialchars($customerPhone, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
                <p><strong>Data przekazania:</strong> <?= htmlspecialchars($releaseDate, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <p><strong>Sposób przekazania:</strong> <?= htmlspecialchars($deliveryMethod, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                <?php if ($deliveryNotes !== ''): ?><p><strong>Szczegóły dostawy:</strong> <?= htmlspecialchars($deliveryNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
            </div>

            <div class="acknowledgement">
                <p>Potwierdzam odbiór kompletnego zestawu komputerowego wraz z wymienionymi poniżej komponentami. Zapoznałem/am się z instrukcjami przekazanymi przez serwis Digivriend i akceptuję stan sprzętu w momencie odbioru.</p>
            </div>

            <h2>Wydane komponenty</h2>
            <table>
                <thead>
                <tr>
                    <th>Kategoria</th>
                    <th>Opis</th>
                    <th>Ilość</th>
                </tr>
                </thead>
                <tbody>
                <?php if ($components === []): ?>
                    <tr>
                        <td colspan="3">Brak zarejestrowanych komponentów.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($components as $component): ?>
                        <?php
                            $category = (string) ($component['category'] ?? '');
                            $label = (string) ($component['label'] ?? ($component['name'] ?? 'Nieznany element'));
                            $quantity = (int) ($component['quantity'] ?? 1);
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($category, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></td>
                            <td><?= $quantity ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ($releaseNotes !== ''): ?>
                <div class="notes">
                    <h2>Uwagi serwisu</h2>
                    <p><?= nl2br(htmlspecialchars($releaseNotes, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) ?></p>
                </div>
            <?php endif; ?>

            <div class="signature">
                <h2>Podpis klienta</h2>
                <?php if ($signatureImage !== null): ?>
                    <img src="<?= $signatureImage ?>" alt="Podpis klienta">
                <?php else: ?>
                    <p>Brak zapisanego podpisu.</p>
                <?php endif; ?>
            </div>
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
        $pdf = generatePcBuildDeliveryPdf($pdo, (int) $buildId);
    } catch (Throwable $exception) {
        Response::error('Nie udało się wygenerować potwierdzenia odbioru.', 500);
    }

    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="pc-build-delivery-' . (int) $buildId . '.pdf"');
    echo $pdf;
    exit;
}