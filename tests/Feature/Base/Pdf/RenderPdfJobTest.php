<?php

use App\Base\Pdf\Events\PdfArtifactRendered;
use App\Base\Pdf\Jobs\RenderPdfJob;
use App\Base\Pdf\Services\PdfPostProcessor;
use App\Base\Pdf\Services\PdfRenderer;
use App\Base\Pdf\ValueObjects\PdfArtifact;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    config()->set('pdf.disk', 'local');
    config()->set('pdf.artifact_directory', 'pdf-artifacts');
    Event::fake();
});

function fakeArtifact(string $disk = 'local', string $path = 'pdf-artifacts/2026/05/11/abc.pdf'): PdfArtifact
{
    return new PdfArtifact(
        disk: $disk,
        path: $path,
        templateVersion: 'payslip@v1',
        dataVersion: 'payroll_run_id=42',
        bytes: 1024,
        sha256: str_repeat('a', 64),
        producedBy: 7,
        producedAt: new DateTimeImmutable(),
    );
}

it('runs the inline path, skips post-processing when no password, dispatches event', function () {
    $artifact = fakeArtifact();

    $renderer = Mockery::mock(PdfRenderer::class);
    $renderer->shouldReceive('renderInline')->once()->andReturn($artifact);
    $renderer->shouldNotReceive('renderView');

    $post = Mockery::mock(PdfPostProcessor::class);
    $post->shouldNotReceive('protectWithPassword');

    $this->app->instance(PdfRenderer::class, $renderer);
    $this->app->instance(PdfPostProcessor::class, $post);

    $job = new RenderPdfJob(
        view: 'pdf.payroll.payslip',
        data: ['totals' => ['net' => 4000]],
        actorUserId: 7,
        templateVersion: 'payslip@v1',
        dataVersion: 'payroll_run_id=42',
        metadata: ['payroll_run_id' => 42, 'employee_id' => 1],
    );
    $job->handle($renderer, $post);

    Event::assertDispatched(PdfArtifactRendered::class, function (PdfArtifactRendered $event) use ($artifact) {
        return $event->artifact === $artifact
            && $event->request->metadata === ['payroll_run_id' => 42, 'employee_id' => 1];
    });
});

it('routes to renderView when mode=view', function () {
    $artifact = fakeArtifact();

    $renderer = Mockery::mock(PdfRenderer::class);
    $renderer->shouldReceive('renderView')->once()->andReturn($artifact);
    $renderer->shouldNotReceive('renderInline');

    $post = Mockery::mock(PdfPostProcessor::class);

    $job = new RenderPdfJob(
        view: 'pdf.payroll.payslip',
        data: [],
        renderMode: RenderPdfJob::MODE_VIEW,
    );
    $job->handle($renderer, $post);

    Event::assertDispatched(PdfArtifactRendered::class);
});

it('chains the post-processor when a password is set', function () {
    $rendered = fakeArtifact(path: 'pdf-artifacts/2026/05/11/plain.pdf');
    $protected = fakeArtifact(path: 'pdf-artifacts/protected/2026/05/11/enc.pdf');

    $renderer = Mockery::mock(PdfRenderer::class);
    $renderer->shouldReceive('renderInline')->once()->andReturn($rendered);

    $post = Mockery::mock(PdfPostProcessor::class);
    $post->shouldReceive('protectWithPassword')
        ->once()
        ->with($rendered, 'secret-passphrase')
        ->andReturn($protected);

    $job = new RenderPdfJob(
        view: 'pdf.payroll.payslip',
        data: [],
        password: 'secret-passphrase',
    );
    $job->handle($renderer, $post);

    Event::assertDispatched(PdfArtifactRendered::class, function (PdfArtifactRendered $event) use ($protected) {
        return $event->artifact === $protected;
    });
});
