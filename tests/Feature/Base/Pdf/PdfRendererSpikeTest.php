<?php

use App\Base\Pdf\Exceptions\PdfRenderException;
use App\Base\Pdf\Services\PdfRenderer;
use App\Base\Pdf\Services\SignedRenderTokenStore;
use App\Modules\Core\AI\Services\Browser\PlaywrightRunner;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

const SPIKE_EMPLOYER_NAME = 'Acme Sdn Bhd';
const PAYSLIP_TEMPLATE_VERSION = 'payslip@v1';

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
            'employer' => ['name' => SPIKE_EMPLOYER_NAME],
            'payslip' => [
                'employee' => ['name' => 'Test Employee', 'number' => 'EMP-001'],
                'period' => [
                    'code' => '2026-01',
                    'name' => 'January 2026',
                    'starts_on' => '2026-01-01',
                    'ends_on' => '2026-01-31',
                    'pay_date' => '2026-02-01',
                ],
                'sections' => [
                    'earnings' => [['label' => 'Base salary', 'amount' => 5000]],
                    'employee_deductions' => [['label' => 'EPF (employee)', 'amount' => 550]],
                ],
                'summary' => [
                    'gross_pay' => 5000,
                    'total_deductions' => 550,
                    'net_pay' => 4450,
                ],
            ],
        ],
        'user_id' => $user->id,
        'template_version' => 'spike',
        'data_version' => 'spike',
    ], 60);

    $signedUrl = URL::temporarySignedRoute('blb.pdf.render', now()->addSeconds(60), ['token' => $tokenId]);

    $first = $this->get($signedUrl);
    $first->assertOk();
    $first->assertSee(SPIKE_EMPLOYER_NAME);
    $first->assertSee('Test Employee');
    $first->assertSee('EMP-001');

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
            'employer' => ['name' => SPIKE_EMPLOYER_NAME],
            'payslip' => ['period' => '2026-01', 'run_id' => 42],
            'totals' => ['gross' => 5000, 'deductions' => 550, 'net' => 4450],
        ],
        actor: $user,
        templateVersion: PAYSLIP_TEMPLATE_VERSION,
        dataVersion: 'payroll_run_id=42',
    );

    expect($artifact->disk)->toBe('local');
    expect($artifact->path)->toStartWith('pdf-artifacts/');
    expect($artifact->path)->toEndWith('.pdf');
    expect($artifact->templateVersion)->toBe(PAYSLIP_TEMPLATE_VERSION);
    expect($artifact->dataVersion)->toBe('payroll_run_id=42');
    expect($artifact->bytes)->toBe(strlen($fakePdfBytes));
    expect($artifact->sha256)->toBe(hash('sha256', $fakePdfBytes));
    expect($artifact->producedBy)->toBe($user->id);

    Storage::disk('local')->assertExists($artifact->path);
    expect(Storage::disk('local')->get($artifact->path))->toBe($fakePdfBytes);
});

it('renders a Blade view inline through page.setContent without a signed URL', function () {
    $fakePdfBytes = "%PDF-1.4\ninline-spike-bytes\n%%EOF";

    $runner = Mockery::mock(PlaywrightRunner::class);
    $runner->shouldReceive('execute')
        ->once()
        ->with('pdf', Mockery::on(function (array $args) use ($fakePdfBytes) {
            expect($args)->toHaveKeys(['html', 'output_path', 'format', 'print_background', 'timeout_ms']);
            expect($args)->not->toHaveKey('url');
            expect($args['html'])->toContain('Inline Employer');
            file_put_contents($args['output_path'], $fakePdfBytes);

            return true;
        }))
        ->andReturn(['ok' => true, 'action' => 'pdf']);

    $this->app->instance(PlaywrightRunner::class, $runner);

    $artifact = app(PdfRenderer::class)->renderInline(
        view: 'pdf.payroll.payslip',
        data: [
            'employer' => ['name' => 'Inline Employer'],
            'totals' => ['gross' => 1000, 'deductions' => 0, 'net' => 1000],
        ],
        templateVersion: PAYSLIP_TEMPLATE_VERSION,
        dataVersion: 'inline-fixture',
        producedBy: 42,
    );

    expect($artifact->disk)->toBe('local');
    expect($artifact->path)->toEndWith('.pdf');
    expect($artifact->templateVersion)->toBe(PAYSLIP_TEMPLATE_VERSION);
    expect($artifact->dataVersion)->toBe('inline-fixture');
    expect($artifact->producedBy)->toBe(42);
    expect($artifact->sha256)->toBe(hash('sha256', $fakePdfBytes));

    Storage::disk('local')->assertExists($artifact->path);
});

it('throws PdfRenderException when the runner reports failure', function () {
    $runner = Mockery::mock(PlaywrightRunner::class);
    $runner->shouldReceive('execute')
        ->once()
        ->andReturn(['ok' => false, 'error' => ['message' => 'chromium crashed']]);

    $this->app->instance(PlaywrightRunner::class, $runner);

    expect(fn () => app(PdfRenderer::class)->renderView('pdf.payroll.payslip', []))
        ->toThrow(PdfRenderException::class, 'chromium crashed');
});
