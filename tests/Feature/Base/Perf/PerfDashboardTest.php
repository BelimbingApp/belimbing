<?php

use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Exceptions\AuthorizationDeniedException;
use App\Base\Authz\Models\PrincipalCapability;
use App\Base\Perf\Livewire\Dashboard\Index;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\Models\Setting;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->perfDir = storage_path('framework/testing/perf-dash-'.uniqid());
    $settings = app(SettingsService::class);
    $settings->set(PerfRuntimeSettings::ENABLED_KEY, false);
    $settings->set(PerfRuntimeSettings::LOG_PATH_KEY, $this->perfDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfDir);
});

function writePerfFixture(string $dir, array $overrides = []): void
{
    File::ensureDirectoryExists($dir);

    $entry = array_merge([
        'ts' => now()->toIso8601String(),
        'method' => 'GET',
        'path' => '/dashboard',
        'route' => 'dashboard',
        'status' => 200,
        'ms' => 432.1,
        'db_ms' => 21.4,
        'queries' => 45,
        'cache_hits' => 15,
        'cache_misses' => 1,
        'cache_writes' => 0,
        'procs' => 0,
        'proc_ms' => 0,
        'resp_bytes' => 1291000,
        'navigate' => false,
        'livewire' => false,
        'mem_mb' => 48.0,
    ], $overrides);

    File::append(
        $dir.DIRECTORY_SEPARATOR.'perf-'.now()->format('Y-m-d').'.jsonl',
        json_encode($entry).PHP_EOL,
    );
}

it('shows the performance dashboard to authorized admins', function (): void {
    writePerfFixture($this->perfDir);
    writePerfFixture($this->perfDir, [
        'route' => 'admin.system.software.modules.index',
        'path' => '/admin/system/software/modules',
        'ms' => 9421.4,
        'procs' => 18,
        'proc_ms' => 8259.7,
    ]);

    $this->actingAs(createAdminUser())
        ->get(route('admin.system.perf.index'))
        ->assertOk()
        ->assertSee('Where the time goes')
        ->assertSee('admin.system.software.modules.index')
        ->assertSee('Recording settings')
        ->assertSee('perf:slowest');
});

it('teaches how to fill the log when the window is empty', function (): void {
    $this->actingAs(createAdminUser())
        ->get(route('admin.system.perf.index'))
        ->assertOk()
        ->assertSee('No requests recorded');
});

it('denies users without the perf capability', function (): void {
    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    $this->actingAs($user)
        ->get(route('admin.system.perf.index'))
        ->assertForbidden();
});

it('rejects unknown time windows without breaking', function (): void {
    writePerfFixture($this->perfDir);

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->call('setWindow', 'bogus')
        ->assertSet('window', '1h')
        ->call('setWindow', '7d')
        ->assertSet('window', '7d');
});

it('saves all performance controls through their definition-backed settings', function (): void {
    config()->set('perf.min_ms', 99_999);

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->assertSet('recordingEnabled', false)
        ->set('recordingEnabled', true)
        ->set('minimumDurationMs', '125.5')
        ->set('slowSqlMinimumDurationMs', '45.5')
        ->set('logPath', '  '.$this->perfDir.'  ')
        ->set('retentionDays', '30')
        ->call('saveRuntimeSettings')
        ->assertHasNoErrors()
        ->assertSet('minimumDurationMs', '125.5')
        ->assertSet('logPath', $this->perfDir);

    $settings = app(SettingsService::class);

    expect($settings->get(PerfRuntimeSettings::ENABLED_KEY))->toBeTrue()
        ->and($settings->get(PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY))->toBe(125.5)
        ->and($settings->get(PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY))->toBe(45.5)
        ->and($settings->get(PerfRuntimeSettings::LOG_PATH_KEY))->toBe($this->perfDir)
        ->and($settings->get(PerfRuntimeSettings::RETENTION_DAYS_KEY))->toBe(30);
});

it('restores every performance override to its definition-owned default', function (): void {
    $settings = app(SettingsService::class);
    $settings->set(PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY, 125.0);
    $settings->set(PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY, 45.0);
    $settings->set(PerfRuntimeSettings::RETENTION_DAYS_KEY, 30);

    config()->set('perf.enabled', false);
    config()->set('perf.min_ms', 99_999);
    config()->set('perf.slow_sql_min_ms', 99_999);
    config()->set('perf.path', 'C:\\environment-fallback');
    config()->set('perf.retention_days', 999);

    Livewire::actingAs(createAdminUser())
        ->test(Index::class)
        ->call('restoreRuntimeSettingDefaults')
        ->assertSet('recordingEnabled', true)
        ->assertSet('minimumDurationMs', '0')
        ->assertSet('slowSqlMinimumDurationMs', '20')
        ->assertSet('logPath', '')
        ->assertSet('retentionDays', '14');

    expect(Setting::query()->whereIn('key', PerfRuntimeSettings::KEYS)->exists())->toBeFalse();
});

it('lets view-only operators inspect settings but forbids mutations', function (): void {
    $company = Company::factory()->create();
    $user = User::factory()->create(['company_id' => $company->id]);

    PrincipalCapability::query()->create([
        'company_id' => $company->id,
        'principal_type' => PrincipalType::USER->value,
        'principal_id' => $user->id,
        'capability_key' => 'admin.system.perf.view',
        'is_allowed' => true,
    ]);

    $component = Livewire::actingAs($user)
        ->test(Index::class)
        ->assertViewHas('canManagePerformanceSettings', false)
        ->assertSee('requires Performance management access');

    expect(fn () => $component->call('saveRuntimeSettings'))
        ->toThrow(AuthorizationDeniedException::class);
});
