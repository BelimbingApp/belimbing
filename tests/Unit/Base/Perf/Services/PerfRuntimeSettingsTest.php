<?php

use App\Base\Perf\DTO\PerfRuntimeSettingsSnapshot;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

beforeEach(function (): void {
    config(['settings.cache_ttl' => 0]);
});

it('resolves definition-owned defaults without config fallback', function (): void {
    $settings = app(SettingsService::class);

    foreach (PerfRuntimeSettings::KEYS as $key) {
        $settings->forget($key);
    }

    config()->set('perf.enabled', false);
    config()->set('perf.min_ms', 99_999);
    config()->set('perf.slow_sql_min_ms', 99_999);
    config()->set('perf.path', 'C:\\environment-fallback');
    config()->set('perf.retention_days', 999);

    $snapshot = app(PerfRuntimeSettings::class)->snapshot();

    expect($snapshot->enabled)->toBeTrue()
        ->and($snapshot->minimumDurationMs)->toBe(0.0)
        ->and($snapshot->slowSqlMinimumDurationMs)->toBe(20.0)
        ->and($snapshot->logPath)->toBeNull()
        ->and($snapshot->retentionDays)->toBe(14)
        ->and(file_exists(config_path('perf.php')))->toBeFalse();
});

it('returns typed global overrides and normalizes the optional path', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(PerfRuntimeSettings::ENABLED_KEY, false);
    $settings->set(PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY, 125.5);
    $settings->set(PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY, 45.5);
    $settings->set(PerfRuntimeSettings::LOG_PATH_KEY, '  D:\\perf logs  ');
    $settings->set(PerfRuntimeSettings::RETENTION_DAYS_KEY, 30);

    $snapshot = app(PerfRuntimeSettings::class)->snapshot();

    expect($snapshot->enabled)->toBeFalse()
        ->and($snapshot->minimumDurationMs)->toBe(125.5)
        ->and($snapshot->slowSqlMinimumDurationMs)->toBe(45.5)
        ->and($snapshot->logPath)->toBe('D:\\perf logs')
        ->and($snapshot->retentionDays)->toBe(30);
});

it('does not treat the synthetic console request as a long-lived request cache', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(PerfRuntimeSettings::ENABLED_KEY, false);

    $runtimeSettings = app(PerfRuntimeSettings::class);
    $runtimeSettings->refresh();
    app('request')->attributes->set(
        '_blb_perf_runtime_settings',
        new PerfRuntimeSettingsSnapshot(true, 999.0, 999.0, 'C:\\stale', 999),
    );

    expect($runtimeSettings->snapshot()->enabled)->toBeFalse();
});
