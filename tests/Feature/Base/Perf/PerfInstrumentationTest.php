<?php

use App\Base\Perf\Services\PerformanceCollector;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Software\Services\SoftwareInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

const PERF_LOGIN_PATH = '/login';
const PERF_SELECT_ONE_SQL = 'select 1';

beforeEach(function (): void {
    $this->perfDir = storage_path('framework/testing/perf-'.uniqid());
    $settings = app(SettingsService::class);
    $settings->set(PerfRuntimeSettings::ENABLED_KEY, true);
    $settings->set(PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY, 0.0);
    $settings->set(PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY, 20.0);
    $settings->set(PerfRuntimeSettings::LOG_PATH_KEY, $this->perfDir);
    $settings->set(PerfRuntimeSettings::RETENTION_DAYS_KEY, 14);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfDir);
});

class PerfInstrumentationFixtureJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        DB::select(PERF_SELECT_ONE_SQL);
    }
}

function latestPerfEntry(string $dir): array
{
    $files = glob($dir.'/perf-*.jsonl');

    expect($files)->not->toBeEmpty();

    $lines = array_values(array_filter(explode(PHP_EOL, trim(file_get_contents(end($files))))));

    return json_decode(end($lines), true);
}

it('records one json line per web request', function (): void {
    $this->get(PERF_LOGIN_PATH)->assertOk();

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['path'])->toBe(PERF_LOGIN_PATH)
        ->and($entry['method'])->toBe('GET')
        ->and($entry['status'])->toBe(200)
        ->and($entry['ms'])->toBeGreaterThan(0)
        ->and($entry['resp_bytes'])->toBeGreaterThan(0)
        ->and($entry)->toHaveKeys(['ts', 'route', 'db_ms', 'queries', 'cache_hits', 'cache_misses', 'procs']);
});

it('counts queries, cache traffic, and subprocesses while a request window is active', function (): void {
    Process::fake();

    $collector = app(PerformanceCollector::class);
    $collector->begin(app(PerfRuntimeSettings::class)->slowSqlMinimumDurationMs());

    DB::select(PERF_SELECT_ONE_SQL);
    Cache::put('perf-test-key', 1, 10);
    Cache::get('perf-test-key');
    Cache::get('perf-test-missing');
    Process::run('git --version');

    $metrics = $collector->end();

    expect($metrics['queries'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['cache_writes'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['cache_hits'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['cache_misses'])->toBeGreaterThanOrEqual(1)
        ->and($metrics['procs'])->toBe(1);
});

it('keeps Process::fake working through the counting factory', function (): void {
    // The counting factory replaces Laravel's Process factory binding. If it
    // drops the fake-handler wiring, Process::fake() silently stops
    // intercepting and every faked test runs real subprocesses — which is how
    // deployment tests once ran real runtime reloads against the live dev
    // server. This must fail loudly if that wiring ever regresses.
    Process::fake([
        '*' => Process::result('faked-output'),
    ]);

    $result = Process::run('definitely-not-a-real-command --flag');

    expect(trim($result->output()))->toBe('faked-output');
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'definitely-not-a-real-command'));
});

it('ignores activity outside a request window', function (): void {
    $collector = app(PerformanceCollector::class);
    $collector->begin(app(PerfRuntimeSettings::class)->slowSqlMinimumDurationMs());
    $collector->end();

    DB::select(PERF_SELECT_ONE_SQL);

    $collector->begin(app(PerfRuntimeSettings::class)->slowSqlMinimumDurationMs());

    expect($collector->end()['queries'])->toBe(0);
});

it('respects the min_ms threshold', function (): void {
    app(SettingsService::class)->set(
        PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY,
        60_000.0,
    );

    $this->get(PERF_LOGIN_PATH)->assertOk();

    expect(glob($this->perfDir.'/perf-*.jsonl'))->toBeEmpty();
});

it('records console commands as command entries', function (): void {
    // Artisan's in-process test path (Kernel::call) never dispatches the
    // console events, so drive the recorder through the real event bus the
    // way `php artisan` does.
    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('perf:prune', $input, $output));
    DB::select(PERF_SELECT_ONE_SQL);
    event(new CommandFinished('perf:prune', $input, $output, 0));

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['type'])->toBe('command')
        ->and($entry['route'])->toBe('cmd:perf:prune')
        ->and($entry['method'])->toBe('CMD')
        ->and($entry['status'])->toBe(200)
        ->and($entry['queries'])->toBeGreaterThanOrEqual(1);
});

it('skips console instrumentation until the settings table exists', function (): void {
    Schema::partialMock()
        ->shouldReceive('hasTable')
        ->once()
        ->with('base_settings')
        ->andReturnFalse();

    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('about', $input, $output));
    event(new CommandFinished('about', $input, $output, 0));

    expect(glob($this->perfDir.'/perf-*.jsonl'))->toBeEmpty();
});

it('does not instrument schema migration commands', function (): void {
    $input = new ArrayInput([]);
    $output = new NullOutput;

    event(new CommandStarting('migrate:fresh', $input, $output));
    DB::select(PERF_SELECT_ONE_SQL);
    event(new CommandFinished('migrate:fresh', $input, $output, 0));

    expect(glob($this->perfDir.'/perf-*.jsonl'))->toBeEmpty();
});

it('records queue jobs as job entries', function (): void {
    dispatch(new PerfInstrumentationFixtureJob);

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['type'])->toBe('job')
        ->and($entry['route'])->toBe('job:'.PerfInstrumentationFixtureJob::class)
        ->and($entry['queries'])->toBeGreaterThanOrEqual(1);
});

it('captures the slowest sql statements on a request', function (): void {
    app(SettingsService::class)->set(
        PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY,
        0.0,
    );

    $this->get('/login')->assertOk();

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['top_sql'])->toBeArray()->not->toBeEmpty()
        ->and($entry['top_sql'][0])->toHaveKeys(['ms', 'sql'])
        ->and(count($entry['top_sql']))->toBeLessThanOrEqual(3);
});

it('prunes from the declared retention and log path settings without config fallback', function (): void {
    File::ensureDirectoryExists($this->perfDir);
    $expired = $this->perfDir.'/perf-'.now()->subDays(15)->format('Y-m-d').'.jsonl';
    $retained = $this->perfDir.'/perf-'.now()->subDays(14)->format('Y-m-d').'.jsonl';
    File::put($expired, '{}'.PHP_EOL);
    File::put($retained, '{}'.PHP_EOL);

    config()->set('perf.retention_days', 1);
    config()->set('perf.path', storage_path('logs'));

    $this->artisan('perf:prune')
        ->expectsOutput('Deleted 1 perf file(s) older than 14 day(s).')
        ->assertSuccessful();

    expect(File::exists($expired))->toBeFalse()
        ->and(File::exists($retained))->toBeTrue();
});

it('aggregates the log by route in perf:slowest', function (): void {
    $this->get(PERF_LOGIN_PATH)->assertOk();
    $this->get(PERF_LOGIN_PATH)->assertOk();

    $this->artisan('perf:slowest', ['--since' => '1h'])
        ->expectsOutputToContain('login')
        ->assertSuccessful();
});

it('keeps shared-chrome page renders within the query budget', function (): void {
    // Budget guard, not a benchmark: the dashboard render includes the menu
    // tree, per-item authorization, and the status bar — the shared chrome
    // every page pays on a full load. A large jump here means an N+1 or new
    // per-request work crept into that path; fix the regression or, if the
    // growth is intentional, raise the budget in the same change that adds it.
    $user = createAdminUser();

    // The status bar's inventory provider would otherwise run the real nested
    // git scan (a dozen-plus subprocesses) inside the test process.
    $inventory = Mockery::mock(SoftwareInventoryService::class);
    $inventory->shouldReceive('installedBundlesForStatusDiagnostics')->andReturn([]);
    app()->instance(SoftwareInventoryService::class, $inventory);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    // Definition-backed settings preload sparse overrides once per active
    // scope so shared chrome does not pay one query per declared parameter.
    expect($queries)->toBeLessThanOrEqual(95);
});
