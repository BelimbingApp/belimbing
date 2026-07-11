<?php

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Services\ReportedErrorRecorder;
use App\Base\System\Services\ReportedErrorStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;

it('records reported exceptions with fingerprint dedup and a rolling window', function (): void {
    $recorder = app(ReportedErrorRecorder::class);
    $recorder->clear();

    report(new RuntimeException('SQLite is locked'));
    report(new RuntimeException('SQLite is locked'));
    report(new LogicException('something else broke'));

    $recent = $recorder->recent();

    expect($recent)->toHaveCount(2)
        ->and(array_column($recent, 'count'))->toBe([2, 1])
        ->and(end($recent)['message'])->toBe('something else broke');

    $recorder->clear();
    expect($recorder->recent())->toBe([]);
});

it('bubbles reported errors to log-capable users and hides them from others', function (): void {
    $recorder = app(ReportedErrorRecorder::class);
    $recorder->clear();

    report(new RuntimeException('database is locked'));

    $provider = app(ReportedErrorStatusDiagnosticProvider::class);

    $diagnostics = collect($provider->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->id)->toBe('system.reported-errors')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->summary)->toContain('1 application error')
        ->and($diagnostics[0]->detail)->toContain('database is locked')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.logs.index'));

    $plainUser = User::factory()->create(['company_id' => Company::factory()->create()->id]);

    expect(collect($provider->diagnosticsFor($plainUser)))->toBeEmpty();

    $recorder->clear();
});

it('bubbles a rejected authz capability into the status bar diagnostic', function (): void {
    // The scenario that motivated this: a module ships a capability with an
    // unknown verb. The catalog prunes it (fails closed) and report()s -
    // that report must reach the diagnostic bubble, not just the log file.
    $recorder = app(ReportedErrorRecorder::class);
    $recorder->clear();

    $catalog = new CapabilityCatalog(
        domains: ['admin'],
        verbs: ['view'],
        capabilities: ['admin.thing.receive'],
    );
    $catalog->validate();

    $diagnostics = collect(app(ReportedErrorStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->detail)->toContain('admin.thing.receive');

    $recorder->clear();
});

it('renders the reported-error diagnostic in the status bar', function (): void {
    $recorder = app(ReportedErrorRecorder::class);
    $recorder->clear();

    report(new RuntimeException('exporter fell over'));

    $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'))
        ->assertOk()
        ->assertSee('1 application error in the last 24 hours')
        ->assertSee('exporter fell over');

    $recorder->clear();
});
