<?php

use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Services\DeploymentRunHistory;
use App\Base\Software\Services\FrankenPhpDomainRuntimeReloader;
use App\Base\Software\Services\FrankenPhpWorkerStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\Cache;

it('reports a pending frankenphp worker reload', function (): void {
    $pendingSince = now()->utc()->toIso8601String();
    Cache::put(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY, $pendingSince, now()->addMinute());

    $diagnostics = collect(app(FrankenPhpWorkerStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.frankenphp-worker-reload.pending')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Warning)
        ->and($diagnostics[0]->summary)->toBe('FrankenPHP worker reload pending')
        ->and($diagnostics[0]->target)->toBe(route('admin.system.software.updates.index'))
        ->and($diagnostics[0]->metadata)->toMatchArray(['pending_since' => $pendingSince]);
});

it('reports a failed last frankenphp worker reload', function (): void {
    app(DeploymentRunHistory::class)->rememberReload(
        ok: false,
        message: 'Warning: web workers were not reloaded because the FrankenPHP admin API could not be reached.',
        adminUrl: 'http://127.0.0.1:2020/config/apps/frankenphp',
    );

    $diagnostics = collect(app(FrankenPhpWorkerStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1);
    expect($diagnostics[0]->id)->toBe('software.frankenphp-worker-reload.failed')
        ->and($diagnostics[0]->severity)->toBe(StatusVariant::Error)
        ->and($diagnostics[0]->summary)->toBe('FrankenPHP worker reload needs attention')
        ->and($diagnostics[0]->metadata)->toMatchArray([
            'message' => 'Warning: web workers were not reloaded because the FrankenPHP admin API could not be reached.',
            'admin_url' => 'http://127.0.0.1:2020/config/apps/frankenphp',
        ]);
});

it('clears the failed reload diagnostic after a successful reload is recorded', function (): void {
    $history = app(DeploymentRunHistory::class);

    $history->rememberReload(
        ok: false,
        message: 'Warning: web workers were not reloaded.',
        adminUrl: 'http://127.0.0.1:2020/config/apps/frankenphp',
    );
    $history->rememberReload(
        ok: true,
        message: 'Web workers reloaded.',
        adminUrl: 'http://127.0.0.1:2020/frankenphp/workers/restart',
    );

    expect(collect(app(FrankenPhpWorkerStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('hides frankenphp reload diagnostics from users without update access', function (): void {
    Cache::put(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY, now()->utc()->toIso8601String(), now()->addMinute());

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(FrankenPhpWorkerStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('surfaces frankenphp reload diagnostics through the status bar aggregator', function (): void {
    app(DeploymentRunHistory::class)->rememberReload(
        ok: false,
        message: 'Warning: web workers were not reloaded.',
        adminUrl: 'http://127.0.0.1:2020/config/apps/frankenphp',
    );

    $response = $this->actingAs(createAdminUser())
        ->get(route('admin.system.info.index'));

    $response->assertOk()
        ->assertSee('FrankenPHP worker reload needs attention')
        ->assertSee('href="'.route('admin.system.software.updates.index').'"', false);
});

afterEach(function (): void {
    Cache::forget(FrankenPhpDomainRuntimeReloader::PENDING_CACHE_KEY);
    app(SettingsService::class)->forget('system.update.frankenphp.last_reload');
});
