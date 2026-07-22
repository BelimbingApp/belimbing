<?php

namespace App\Base\AI\Services;

use App\Base\AI\Exceptions\DocumentExtractionException;
use App\Base\AI\Values\DocumentPageSelection;
use App\Base\Support\ExecutableLocator;

/**
 * Materializes trusted-in-memory PDF bytes only long enough for pdftotext.
 */
class PdfTextExtractor
{
    public function __construct(
        private readonly ExecutableLocator $executableLocator,
        private readonly PdfToTextRunner $runner,
        private readonly ?string $configuredBinary = null,
        private readonly ?AiRuntimeSettings $runtimeSettings = null,
    ) {}

    /**
     * @return array{content: string, truncated: bool}
     */
    public function extract(
        string $pdfBytes,
        DocumentPageSelection $pages,
        int $timeoutSeconds,
        int $maxChars,
    ): array {
        $binary = $this->executableLocator->find($this->binaryCandidates());

        if ($binary === null) {
            throw new DocumentExtractionException(
                'pdf_extractor_unavailable',
                'PDF text extraction is unavailable because Poppler pdftotext is not configured or on PATH.',
            );
        }

        $pdfPath = tempnam(sys_get_temp_dir(), 'blb-pdf-source-');

        if ($pdfPath === false) {
            throw new DocumentExtractionException(
                'temporary_file_failed',
                'Unable to create a temporary PDF source file.',
            );
        }

        try {
            $written = @file_put_contents($pdfPath, $pdfBytes, LOCK_EX);

            if ($written !== strlen($pdfBytes)) {
                throw new DocumentExtractionException(
                    'temporary_file_failed',
                    'Unable to stage the PDF for text extraction.',
                );
            }

            $result = $this->runner->extract(
                binary: $binary,
                pdfPath: $pdfPath,
                ranges: $pages->ranges,
                timeoutSeconds: $timeoutSeconds,
                maxChars: $maxChars,
            );

            if (trim($result['content']) === '') {
                throw new DocumentExtractionException(
                    'pdf_has_no_text',
                    'No readable text was found in the selected PDF pages; the document may be image-only.',
                );
            }

            return $result;
        } finally {
            @unlink($pdfPath);
        }
    }

    /**
     * Prefer the operator-pinned binary while retaining a portable PATH lookup.
     *
     * @return list<string>
     */
    private function binaryCandidates(): array
    {
        if ($this->runtimeSettings !== null) {
            return $this->runtimeSettings->pdfToTextCandidates();
        }

        $candidates = [];

        if (is_string($this->configuredBinary) && trim($this->configuredBinary) !== '') {
            $candidates[] = trim($this->configuredBinary);
        }

        return [...$candidates, 'pdftotext', 'pdftotext.exe'];
    }
}
