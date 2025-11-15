<?php

declare(strict_types=1);

use App\Services\DocumentGenerator;
use App\Services\DocumentRequest;
use App\Support\Documents\DocumentRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use PHPUnit\Framework\TestCase;

final class DocumentGeneratorTest extends TestCase
{
    public function testGeneratesPdfWithRemoteAccessEnabledAndPersistsDocument(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE documents (id INTEGER PRIMARY KEY AUTOINCREMENT, case_id INTEGER NULL, type TEXT NOT NULL, file_path TEXT NOT NULL, metadata TEXT NULL, created_at TEXT NOT NULL)');

        $repository = new DocumentRepository($pdo);

        $capturedOptions = null;
        $streamInfo = [];

        $generator = new DocumentGenerator(
            documentRepository: $repository,
            projectRoot: dirname(__DIR__),
            storageDirectory: 'storage/documents/tests',
            dompdfFactory: static function (Options $options) use (&$capturedOptions, &$streamInfo): Dompdf {
                $capturedOptions = $options;

                return new class($options, $streamInfo) extends Dompdf {
                    private $streamInfo;

                    public function __construct(Options $options, array &$streamInfo)
                    {
                        $this->streamInfo = &$streamInfo;
                        parent::__construct($options);
                    }

                    public function loadHtml($str, $encoding = null): void
                    {
                        // Skip DOM parsing in tests
                    }

                    public function render(): void
                    {
                        // Intentionally left blank for testing
                    }

                    public function output($options = null): string
                    {
                        return 'PDF-CONTENT';
                    }

                    public function stream($filename, $options = []): void
                    {
                        $this->streamInfo = [
                            'filename' => $filename,
                            'options' => $options,
                        ];
                    }
                };
            }
        );

        $filename = 'tests/TestDocument.pdf';

        try {
            $result = $generator->generate(
                new DocumentRequest(
                    template: 'pdf/test-document.php',
                    context: [
                        'title' => 'Test Document',
                        'heading' => 'Heading',
                        'content' => 'Body',
                    ],
                    filename: $filename,
                    store: true,
                    documentType: 'test_document',
                    metadata: ['foo' => 'bar'],
                )
            );

            self::assertInstanceOf(Options::class, $capturedOptions);
            self::assertTrue($capturedOptions->isRemoteEnabled());
            self::assertSame('PDF-CONTENT', $result);

            $expectedPath = dirname(__DIR__) . '/storage/documents/tests/TestDocument.pdf';
            self::assertFileExists($expectedPath);

            $statement = $pdo->query('SELECT type, file_path, metadata FROM documents');
            $documents = $statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            self::assertCount(1, $documents);
            self::assertSame('test_document', $documents[0]['type']);
            self::assertSame('storage/documents/tests/TestDocument.pdf', $documents[0]['file_path']);

            $metadata = json_decode((string) $documents[0]['metadata'], true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('bar', $metadata['foo'] ?? null);

            self::assertSame('tests/TestDocument.pdf', $streamInfo['filename'] ?? null);
            self::assertSame(['Attachment' => true], $streamInfo['options'] ?? []);
        } finally {
            $expectedPath = dirname(__DIR__) . '/storage/documents/tests/TestDocument.pdf';
            if (is_file($expectedPath)) {
                unlink($expectedPath);
            }

            $expectedDir = dirname($expectedPath);
            if (is_dir($expectedDir)) {
                @rmdir($expectedDir);
            }
        }
    }
}