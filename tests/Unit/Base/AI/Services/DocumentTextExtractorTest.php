<?php

use App\Base\AI\Services\DocumentTextExtractor;
use App\Base\AI\Services\PdfTextExtractor;
use App\Base\AI\Services\WebFetchService;
use App\Base\AI\Values\DocumentPageSelection;
use Tests\TestCase;

uses(TestCase::class);

const DOCUMENT_SERVICE_URL = 'https://reports.example.test/fy2025';

function documentServicePages(?string $pages = null): DocumentPageSelection
{
    return DocumentPageSelection::parse($pages, 200, 10000, 20);
}

function documentService(
    WebFetchService $webFetchService,
    ?PdfTextExtractor $pdfTextExtractor = null,
): DocumentTextExtractor {
    return new DocumentTextExtractor(
        $webFetchService,
        $pdfTextExtractor ?? Mockery::mock(PdfTextExtractor::class),
    );
}

it('maps SSRF and response size failures without attempting extraction', function () {
    $fetcher = Mockery::mock(WebFetchService::class);
    $fetcher->shouldReceive('download')->twice()->andReturn(
        ['validation_error' => 'Blocked: private address.'],
        ['response_too_large' => 'Response exceeds 100 bytes.'],
    );
    $pdf = Mockery::mock(PdfTextExtractor::class);
    $pdf->shouldNotReceive('extract');
    $service = documentService($fetcher, $pdf);

    $blocked = $service->extract(DOCUMENT_SERVICE_URL, documentServicePages(), 30, 60, 100, 1000);
    $oversized = $service->extract(DOCUMENT_SERVICE_URL, documentServicePages(), 30, 60, 100, 1000);

    expect($blocked->errorCode)->toBe('invalid_url')
        ->and($oversized->errorCode)->toBe('response_too_large');
});

it('detects PDF bytes and delegates bounded page extraction', function () {
    $pdfBytes = "%PDF-1.7\nmock annual report";
    $fetcher = Mockery::mock(WebFetchService::class);
    $fetcher->shouldReceive('download')->once()->andReturn([
        'body' => $pdfBytes,
        'byte_count' => strlen($pdfBytes),
        'content_type' => 'application/octet-stream',
        'final_url' => DOCUMENT_SERVICE_URL.'/download',
    ]);
    $pdf = Mockery::mock(PdfTextExtractor::class);
    $pdf->shouldReceive('extract')
        ->once()
        ->withArgs(function (
            string $bytes,
            DocumentPageSelection $pages,
            int $timeout,
            int $maxChars,
        ) use ($pdfBytes): bool {
            expect($bytes)->toBe($pdfBytes)
                ->and($pages->ranges)->toBe([[80, 90]])
                ->and($timeout)->toBe(60)
                ->and($maxChars)->toBe(5000);

            return true;
        })
        ->andReturn(['content' => 'Financial statements', 'truncated' => false]);

    $result = documentService($fetcher, $pdf)->extract(
        DOCUMENT_SERVICE_URL,
        documentServicePages('80-90'),
        30,
        60,
        1024,
        5000,
    );

    expect($result->successful())->toBeTrue()
        ->and($result->mediaType)->toBe('application/pdf')
        ->and($result->sourceUrl)->toBe(DOCUMENT_SERVICE_URL.'/download')
        ->and($result->pageSelection)->toBe('80-90')
        ->and($result->content)->toBe('Financial statements');
});

it('extracts bounded HTML through the shared readable-content implementation', function () {
    $html = '<html><body><script>ignore()</script><p>Annual report landing page</p></body></html>';
    $fetcher = Mockery::mock(WebFetchService::class);
    $fetcher->shouldReceive('download')->once()->andReturn([
        'body' => $html,
        'byte_count' => strlen($html),
        'content_type' => 'application/octet-stream',
        'final_url' => DOCUMENT_SERVICE_URL,
    ]);
    $fetcher->shouldReceive('extractReadableContent')
        ->once()
        ->with($html, 'text/html', 1000, 'text', DOCUMENT_SERVICE_URL)
        ->andReturn([
            'content' => 'Annual report landing page',
            'char_count' => 26,
            'truncated' => false,
        ]);

    $result = documentService($fetcher)->extract(
        DOCUMENT_SERVICE_URL,
        documentServicePages(),
        30,
        60,
        1024,
        1000,
    );

    expect($result->successful())->toBeTrue()
        ->and($result->mediaType)->toBe('text/html')
        ->and($result->content)->toBe('Annual report landing page');
});

it('does not silently apply PDF page filters to text documents', function () {
    $fetcher = Mockery::mock(WebFetchService::class);
    $fetcher->shouldReceive('download')->once()->andReturn([
        'body' => 'Plain filing text',
        'byte_count' => 17,
        'content_type' => 'text/plain',
        'final_url' => DOCUMENT_SERVICE_URL,
    ]);
    $fetcher->shouldNotReceive('extractReadableContent');

    $result = documentService($fetcher)->extract(
        DOCUMENT_SERVICE_URL,
        documentServicePages('1-3'),
        30,
        60,
        1024,
        1000,
    );

    expect($result->errorCode)->toBe('pages_require_pdf');
});

it('rejects unsupported binary content instead of returning raw bytes', function () {
    $fetcher = Mockery::mock(WebFetchService::class);
    $fetcher->shouldReceive('download')->once()->andReturn([
        'body' => "PK\x03\x04archive",
        'byte_count' => 11,
        'content_type' => 'application/zip',
        'final_url' => DOCUMENT_SERVICE_URL,
    ]);

    $result = documentService($fetcher)->extract(
        DOCUMENT_SERVICE_URL,
        documentServicePages(),
        30,
        60,
        1024,
        1000,
    );

    expect($result->errorCode)->toBe('unsupported_media_type')
        ->and($result->errorMessage)->toContain('application/zip');
});
