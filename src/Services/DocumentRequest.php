<?php

declare(strict_types=1);

namespace App\Services;

/**
 * @psalm-type DocumentMetadata = array<string, mixed>
 * @psalm-type DocumentContext = array<string, mixed>
 */
final class DocumentRequest
{
    /**
     * @param DocumentContext $context
     * @param DocumentMetadata $metadata
     */
    public function __construct(
        public readonly string $template,
        public readonly array $context,
        public readonly string $filename,
        public readonly string $paper = 'A4',
        public readonly string $orientation = 'portrait',
        public readonly bool $stream = true,
        public readonly bool $download = true,
        public readonly bool $store = false,
        public readonly ?string $documentType = null,
        public readonly ?int $caseId = null,
        public readonly array $metadata = [],
    ) {
    }
}