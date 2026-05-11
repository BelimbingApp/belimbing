<?php

use App\Base\Pdf\Services\PdfRenderer;
use App\Base\Pdf\Services\SignedRenderTokenStore;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Storage::fake('local');
    config()->set('pdf.disk', 'local');
    config()->set('pdf.artifact_directory', 'pdf-artifacts');
    config()->set('pdf.signed_url_ttl_seconds', 60);
});

it('renders a view via the signed URL and consumes the token', function () {
    $user = createAdminUser();

    $store = app(SignedRenderTokenStore::class);
    $tokenId = $store->issue([
        'view' => 'pdf.payroll.payslip',
        'data' => [
            'employer' => ['name' => 'Acme Sdn Bhd'],
            'employee' => ['name' => 'Test Employee', 'identifier' => 'EMP-001'],
            'payslip' => ['period' => '2026-01', 'run_id' => 42],
            'earnings' => [['label' => 'Base salary', 'amount' => 5000]],
            'deductions' => [['label' => 'EPF (employee)', 'amount' => 550]],
            'totals' => ['gross' => 5000, 'deductions' => 550, 'net' => 4450],
        ],
        'user_id' => $user->id,
        'template_version' => 'spike',
        'data_version' => 'spike',
    ], 60);

    $signedUrl = URL::temporarySignedRoute('blb.pdf.render', now()->addSeconds(60), ['token' => $tokenId]);

    $first = $this->get($signedUrl);
    $first->assertOk();
    $first->assertSee('Acme Sdn Bhd');
    $first->assertSee('Test Employee');
    $first->assertSee('Rendered as user #'.$user->id);

    $second = $this->get($signedUrl);
    $second->assertNotFound();
});

it('rejects an unsigned URL', function () {
    $store = app(SignedRenderTokenStore::class);
    $tokenId = $store->issue([
        'view' => 'pdf.payroll.payslip',
        'data' => [],
        'user_id' => null,
        'template_version' => 'spike',
        'data_version' => 'spike',
    ], 60);

    $unsignedUrl = route('blb.pdf.render', ['token' => $tokenId]);

    $this->get($unsignedUrl)->assertForbidden();
});

it('writes a PdfArtifact to the configured disk and returns lineage metadata', function () {
    $user = createAdminUser();
    Auth::login($user);

    $fakePdfBytes = "%PDF-1.4\nfake-spike-bytes\n%%EOF";

    $runner = Mockery::mock(PlaywrightRunner::class);
    $runner->shouldReceive('execute')
        ->once()
        ->with('pdf', Mockery::on(function (array $args) use ($fakePdfBytes) {
            expect($args)->toHaveKeys(['url', 'output_path', 'format', 'print_background', 'timeout_ms']);
            expect($args['url'])->toContain('/pdf/render/');
            expect($args['url'])->toContain('signature=');
            file_put_contents($args['output_path'], $fakePdfBytes);
            return true;
        }))
        ->andReturn(['ok' => true, 'action' => 'pdf']);

    $this->app->instance(PlaywrightRunner::class, $runner);

    $renderer = app(PdfRenderer::class);
    $artifact = $renderer->renderView(
        view: 'pdf.payroll.payslip',
        data: [
            'employer' => ['name' => 'Acme Sdn Bhd'],
            'payslip' => ['period' => '2026-01', 'run_id' => 42],
            'totals' => ['gross' => 5000, 'deductions' => 550, 'net' => 4450],
        ],
        actor: $user,
        templateVersion: 'payslip@v1',
        dataVersion: 'payroll_run_id=42',
    );

    expect($artifact->disk)->toBe('local');
    expect($artifact->path)->toStartWith('pdf-artifacts/');
    expect($artifact->path)->toEndWith('.pdf');
    expect($artifact->templateVersion)->toBe('payslip@v1');
    expect($artifact->dataVersion)->toBe('payroll_run_id=42');
    expect($artifact->bytes)->toBe(strlen($fakePdfBytes));
    expect($artifact->sha256)->toBe(hash('sha256', $fakePdfBytes));
    expect($artifact->producedBy)->toBe($user->id);

    Storage::disk('local')->assertExists($artifact->path);
    expect(Storage::disk('local')->get($artifact->path))->toBe($fakePdfBytes);
});

it('throws PdfRenderException when the runner reports failure', function () {
    $runner = Mockery::mock(PlaywrightRunner::class);
    $runner->shouldReceive('execute')
        ->once()
        ->andReturn(['ok' => false, 'error' => ['message' => 'chromium crashed']]);

    $this->app->instance(PlaywrightRunner::class, $runner);

    expect(fn () => app(PdfRenderer::class)->renderView('pdf.payroll.payslip', []))
        ->toThrow(\App\Base\Pdf\Exceptions\PdfRenderException::class, 'chromium crashed');
});
