<?php

use App\Base\AI\Exceptions\DocumentExtractionException;
use App\Base\AI\Services\PdfTextExtractor;
use App\Base\AI\Services\PdfToTextRunner;
use App\Base\AI\Values\DocumentPageSelection;
use App\Base\Support\ExecutableLocator;

function pdfExtractorPages(): DocumentPageSelection
{
    return DocumentPageSelection::parse('2-4,9', 20, 100, 10);
}

it('uses the located binary and removes the staged PDF after extraction', function () {
    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')->once()->with(['pdftotext', 'pdftotext.exe'])->andReturn('safe-pdftotext');
    $runner = Mockery::mock(PdfToTextRunner::class);
    $stagedPath = null;
    $runner->shouldReceive('extract')
        ->once()
        ->withArgs(function (
            string $binary,
            string $pdfPath,
            array $ranges,
            int $timeout,
            int $maxChars,
        ) use (&$stagedPath): bool {
            $stagedPath = $pdfPath;

            expect($binary)->toBe('safe-pdftotext')
                ->and(is_file($pdfPath))->toBeTrue()
                ->and(file_get_contents($pdfPath))->toBe('%PDF-test')
                ->and($ranges)->toBe([[2, 4], [9, 9]])
                ->and($timeout)->toBe(12)
                ->and($maxChars)->toBe(5000);

            return true;
        })
        ->andReturn(['content' => 'Extracted text', 'truncated' => false]);

    $result = (new PdfTextExtractor($locator, $runner))->extract(
        '%PDF-test',
        pdfExtractorPages(),
        12,
        5000,
    );

    expect($result['content'])->toBe('Extracted text')
        ->and($stagedPath)->not->toBeNull()
        ->and(file_exists($stagedPath))->toBeFalse();
});

it('prefers an explicitly configured Poppler binary before PATH candidates', function () {
    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')
        ->once()
        ->with(['C:\\Poppler\\bin\\pdftotext.exe', 'pdftotext', 'pdftotext.exe'])
        ->andReturn('configured-pdftotext');
    $runner = Mockery::mock(PdfToTextRunner::class);
    $runner->shouldReceive('extract')
        ->once()
        ->withArgs(fn (string $binary): bool => $binary === 'configured-pdftotext')
        ->andReturn(['content' => 'Extracted text', 'truncated' => false]);

    $result = (new PdfTextExtractor(
        $locator,
        $runner,
        'C:\\Poppler\\bin\\pdftotext.exe',
    ))->extract(
        '%PDF-test',
        pdfExtractorPages(),
        12,
        5000,
    );

    expect($result['content'])->toBe('Extracted text');
});

it('cleans the staged PDF when the process runner fails', function () {
    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')->andReturn('safe-pdftotext');
    $runner = Mockery::mock(PdfToTextRunner::class);
    $stagedPath = null;
    $runner->shouldReceive('extract')
        ->once()
        ->withArgs(function (string $binary, string $pdfPath) use (&$stagedPath): bool {
            $stagedPath = $pdfPath;

            return true;
        })
        ->andThrow(new DocumentExtractionException('pdf_extraction_failed', 'Failed.'));

    $extract = fn () => (new PdfTextExtractor($locator, $runner))->extract(
        '%PDF-test',
        pdfExtractorPages(),
        12,
        5000,
    );

    expect($extract)->toThrow(DocumentExtractionException::class, 'Failed.')
        ->and($stagedPath)->not->toBeNull()
        ->and(file_exists($stagedPath))->toBeFalse();
});

it('reports a missing PDF extractor without creating a process', function () {
    $locator = Mockery::mock(ExecutableLocator::class);
    $locator->shouldReceive('find')->once()->andReturnNull();
    $runner = Mockery::mock(PdfToTextRunner::class);
    $runner->shouldNotReceive('extract');

    try {
        (new PdfTextExtractor($locator, $runner))->extract('%PDF-test', pdfExtractorPages(), 12, 5000);
        $this->fail('Expected a document extraction exception.');
    } catch (DocumentExtractionException $exception) {
        expect($exception->errorCode)->toBe('pdf_extractor_unavailable');
    }
});
