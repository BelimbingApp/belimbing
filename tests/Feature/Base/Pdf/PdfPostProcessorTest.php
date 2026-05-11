<?php

use App\Base\Pdf\Exceptions\PdfPostProcessException;
use App\Base\Pdf\Services\PdfPostProcessor;
use App\Base\Pdf\Services\QpdfRunner;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('pdf.disk', 'local');
    config()->set('pdf.artifact_directory', 'pdf-artifacts');
});

function makeSourceArtifact(string $contents = "%PDF-1.4\nplain-bytes\n%%EOF"): PdfArtifact
{
    $sha = hash('sha256', $contents);
    $path = 'pdf-artifacts/2026/05/11/'.$sha.'.pdf';
    Storage::disk('local')->put($path, $contents);

    return new PdfArtifact(
        disk: 'local',
        path: $path,
        templateVersion: 'payslip@v1',
        dataVersion: 'payroll_run_id=42',
        bytes: strlen($contents),
        sha256: $sha,
        producedBy: 7,
        producedAt: new DateTimeImmutable(),
    );
}

it('encrypts a PdfArtifact and returns a new artifact with updated metadata', function () {
    $source = makeSourceArtifact();
    $encryptedBytes = "%PDF-1.4\nencrypted-mock-output\n%%EOF";

    $qpdf = Mockery::mock(QpdfRunner::class);
    $qpdf->shouldReceive('run')
        ->once()
        ->with(Mockery::on(function (array $args) use ($encryptedBytes) {
            expect($args[0])->toBe('--encrypt');
            expect($args[1])->toBe('user-pass');
            expect($args[2])->toBe('owner-pass');
            expect($args[3])->toBe('256');
            expect($args[4])->toBe('--');
            expect($args[5])->toBeString();
            expect($args[6])->toBeString();
            file_put_contents($args[6], $encryptedBytes);
            return true;
        }));

    $this->app->instance(QpdfRunner::class, $qpdf);

    $protected = app(PdfPostProcessor::class)->protectWithPassword($source, 'user-pass', 'owner-pass');

    expect($protected->disk)->toBe('local');
    expect($protected->path)->toStartWith('pdf-artifacts/protected/');
    expect($protected->path)->toEndWith('.pdf');
    expect($protected->bytes)->toBe(strlen($encryptedBytes));
    expect($protected->sha256)->toBe(hash('sha256', $encryptedBytes));
    expect($protected->templateVersion)->toBe('payslip@v1');
    expect($protected->dataVersion)->toBe('payroll_run_id=42 (encrypted)');
    expect($protected->producedBy)->toBe(7);

    Storage::disk('local')->assertExists($protected->path);
    Storage::disk('local')->assertExists($source->path);
});

it('defaults the owner password to the user password when not provided', function () {
    $source = makeSourceArtifact();
    $qpdf = Mockery::mock(QpdfRunner::class);
    $qpdf->shouldReceive('run')
        ->once()
        ->with(Mockery::on(function (array $args) {
            expect($args[1])->toBe($args[2]);
            file_put_contents($args[6], '%PDF-1.4 encrypted %%EOF');
            return true;
        }));

    $this->app->instance(QpdfRunner::class, $qpdf);

    app(PdfPostProcessor::class)->protectWithPassword($source, 'shared-pass');
});

it('refuses an empty user password', function () {
    $source = makeSourceArtifact();

    expect(fn () => app(PdfPostProcessor::class)->protectWithPassword($source, ''))
        ->toThrow(PdfPostProcessException::class, 'empty user password');
});

it('propagates a qpdf failure as PdfPostProcessException', function () {
    $source = makeSourceArtifact();
    $qpdf = Mockery::mock(QpdfRunner::class);
    $qpdf->shouldReceive('run')
        ->once()
        ->andThrow(PdfPostProcessException::qpdfFailed('boom from qpdf', 2));

    $this->app->instance(QpdfRunner::class, $qpdf);

    expect(fn () => app(PdfPostProcessor::class)->protectWithPassword($source, 'pw'))
        ->toThrow(PdfPostProcessException::class, 'boom from qpdf');
});
