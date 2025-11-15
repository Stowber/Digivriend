<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\Documents\DocumentRepository;
use App\Support\View;
use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;
use RuntimeException;

final class DocumentGenerator
{
    private string $projectRoot;

    /**
     * @var callable(Options):Dompdf
     */
    private $dompdfFactory;

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        ?string $projectRoot = null,
        private readonly string $storageDirectory = 'storage/documents',
        ?callable $dompdfFactory = null,
    ) {
        $this->projectRoot = $projectRoot ?? dirname(__DIR__, 2);
        $this->dompdfFactory = $dompdfFactory ?? static fn (Options $options): Dompdf => new Dompdf($options);
    }

    public function generate(DocumentRequest $request): string
    {
        if ($request->store && $request->documentType === null) {
            throw new InvalidArgumentException('A document type is required when persisting the PDF.');
        }

        $html = View::render($request->template, $request->context);

        $options = new Options();
        $options->setIsRemoteEnabled(true);

        /** @var callable(Options):Dompdf $factory */
        $factory = $this->dompdfFactory;
        $dompdf = $factory($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper($request->paper, $request->orientation);
        $dompdf->render();

        $pdfContent = $dompdf->output();

        if ($request->store) {
            $this->storeDocument($request, $pdfContent);
        }

        if ($request->stream) {
            $dompdf->stream($request->filename, ['Attachment' => $request->download]);
        }

        return $pdfContent;
    }

    private function storeDocument(DocumentRequest $request, string $pdfContent): string
    {
        $relativePath = rtrim($this->storageDirectory, '/') . '/' . ltrim($request->filename, '/');
        $absolutePath = $this->projectRoot . '/' . $relativePath;
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create document directory at %s', $directory));
        }

        if (file_put_contents($absolutePath, $pdfContent) === false) {
            throw new RuntimeException(sprintf('Unable to write PDF to %s', $absolutePath));
        }

        $this->documentRepository->store(
            $request->caseId,
            (string) $request->documentType,
            $relativePath,
            $request->metadata,
        );

        return $relativePath;
    }
}