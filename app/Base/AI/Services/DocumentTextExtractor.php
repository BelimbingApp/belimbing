<?php

namespace App\Base\AI\Services;

use App\Base\AI\DTO\DocumentExtractionResult;
use App\Base\AI\Exceptions\DocumentExtractionException;
use App\Base\AI\Values\DocumentPageSelection;

/**
 * Safe, stateless text extraction for public PDF, HTML, and text resources.
 */
class DocumentTextExtractor
{
    /** @var list<string> */
    private const TEXT_MEDIA_TYPES = [
        'application/json',
        'application/ld+json',
        'application/xhtml+xml',
        'application/xml',
    ];

    private const HTML_MEDIA_TYPE = 'text/html';

    public function __construct(
        private readonly WebFetchService $webFetchService,
        private readonly PdfTextExtractor $pdfTextExtractor,
    ) {}

    public function extract(
        string $url,
        DocumentPageSelection $pages,
        int $downloadTimeoutSeconds,
        int $pdfTimeoutSeconds,
        int $maxResponseBytes,
        int $maxChars,
    ): DocumentExtractionResult {
        $download = $this->webFetchService->download(
            url: $url,
            timeoutSeconds: $downloadTimeoutSeconds,
            maxResponseBytes: $maxResponseBytes,
            allowPrivateNetwork: false,
        );

        if (! isset($download['body'])) {
            return $this->downloadFailure($download);
        }

        $body = $download['body'];
        $sourceUrl = $download['final_url'] ?? $url;
        $sourceBytes = (int) ($download['byte_count'] ?? strlen($body));
        $mediaType = $this->normalizedMediaType($download['content_type'] ?? '');

        if ($this->isPdf($body, $mediaType)) {
            try {
                $extracted = $this->pdfTextExtractor->extract(
                    pdfBytes: $body,
                    pages: $pages,
                    timeoutSeconds: $pdfTimeoutSeconds,
                    maxChars: $maxChars,
                );
            } catch (DocumentExtractionException $exception) {
                return DocumentExtractionResult::failure($exception->errorCode, $exception->getMessage());
            }

            return DocumentExtractionResult::success(
                content: $extracted['content'],
                sourceUrl: $sourceUrl,
                mediaType: 'application/pdf',
                sourceBytes: $sourceBytes,
                truncated: $extracted['truncated'],
                pageSelection: $pages->label,
                defaultPageWindow: ! $pages->explicit,
            );
        }

        if ($pages->explicit) {
            return DocumentExtractionResult::failure(
                'pages_require_pdf',
                'The "pages" filter can only be used with PDF documents.',
            );
        }

        $isHtml = $this->isHtml($body, $mediaType);

        if (! $isHtml && ! $this->isSupportedText($mediaType)) {
            return DocumentExtractionResult::failure(
                'unsupported_media_type',
                'Unsupported document media type: '.($mediaType === '' ? 'unknown' : $mediaType).'.',
            );
        }

        $extracted = $this->webFetchService->extractReadableContent(
            body: $body,
            contentType: $isHtml ? self::HTML_MEDIA_TYPE : $mediaType,
            maxChars: $maxChars,
            extractMode: 'text',
            baseUrl: $sourceUrl,
        );

        return DocumentExtractionResult::success(
            content: $extracted['content'],
            sourceUrl: $sourceUrl,
            mediaType: $isHtml ? self::HTML_MEDIA_TYPE : $mediaType,
            sourceBytes: $sourceBytes,
            truncated: $extracted['truncated'],
        );
    }

    /**
     * @param  array{validation_error?: string, request_error?: string, response_too_large?: string, http_status?: int}  $download
     */
    private function downloadFailure(array $download): DocumentExtractionResult
    {
        if (isset($download['validation_error'])) {
            return DocumentExtractionResult::failure('invalid_url', $download['validation_error']);
        }

        if (isset($download['response_too_large'])) {
            return DocumentExtractionResult::failure('response_too_large', $download['response_too_large']);
        }

        if (isset($download['http_status'])) {
            return DocumentExtractionResult::failure(
                'http_error',
                'Unable to fetch document: HTTP '.$download['http_status'].'.',
            );
        }

        return DocumentExtractionResult::failure(
            'request_error',
            'Unable to fetch document: '.($download['request_error'] ?? 'unknown network failure'),
        );
    }

    private function normalizedMediaType(string $contentType): string
    {
        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function isPdf(string $body, string $mediaType): bool
    {
        return $mediaType === 'application/pdf'
            || str_contains(substr($body, 0, 1024), '%PDF-');
    }

    private function isHtml(string $body, string $mediaType): bool
    {
        if ($mediaType === 'text/html' || $mediaType === 'application/xhtml+xml') {
            return true;
        }

        $prefix = strtolower(ltrim(substr($body, 0, 512)));

        return str_starts_with($prefix, '<!doctype html')
            || str_starts_with($prefix, '<html');
    }

    private function isSupportedText(string $mediaType): bool
    {
        return str_starts_with($mediaType, 'text/')
            || in_array($mediaType, self::TEXT_MEDIA_TYPES, true);
    }
}
