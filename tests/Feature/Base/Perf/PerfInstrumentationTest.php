<?php

use App\Base\Perf\Services\PerformanceCollector;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

const PERF_LOGIN_PATH = '/login';

beforeEach(function (): void {
    $this->perfDir = storage_path('framework/testing/perf-'.uniqid());
    config()->set('perf.enabled', true);
    config()->set('perf.min_ms', 0);
    config()->set('perf.path', $this->perfDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfDir);
});

class PerfInstrumentationFixtureJob implements Illuminate\Contracts\Queue\ShouldQueue
{
    use Illuminate\Bus\Queueable;
    use Illuminate\Foundation\Bus\Dispatchable;
    use Illuminate\Queue\InteractsWithQueue;

    public function handle(): void
    {
        Illuminate\Support\Facades\DB::select('select 1');
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
    $collector->begin();

    DB::select('select 1');
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
    $collector->begin();
    $collector->end();

    DB::select('select 1');

    $collector->begin();

    expect($collector->end()['queries'])->toBe(0);
});

it('respects the min_ms threshold', function (): void {
    config()->set('perf.min_ms', 60_000);

    $this->get(PERF_LOGIN_PATH)->assertOk();

    expect(glob($this->perfDir.'/perf-*.jsonl'))->toBeEmpty();
});

it('records console commands as command entries', function (): void {
    // Artisan's in-process test path (Kernel::call) never dispatches the
    // console events, so drive the recorder through the real event bus the
    // way `php artisan` does.
    $input = new Symfony\Component\Console\Input\ArrayInput([]);
    $output = new Symfony\Component\Console\Output\NullOutput;

    event(new Illuminate\Console\Events\CommandStarting('perf:prune', $input, $output));
    DB::select('select 1');
    event(new Illuminate\Console\Events\CommandFinished('perf:prune', $input, $output, 0));

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['type'])->toBe('command')
        ->and($entry['route'])->toBe('cmd:perf:prune')
        ->and($entry['method'])->toBe('CMD')
        ->and($entry['status'])->toBe(200)
        ->and($entry['queries'])->toBeGreaterThanOrEqual(1);
});

it('records queue jobs as job entries', function (): void {
    dispatch(new PerfInstrumentationFixtureJob);

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['type'])->toBe('job')
        ->and($entry['route'])->toBe('job:'.PerfInstrumentationFixtureJob::class)
        ->and($entry['queries'])->toBeGreaterThanOrEqual(1);
});

it('captures the slowest sql statements on a request', function (): void {
    config()->set('perf.slow_sql_min_ms', 0);

    $this->get('/login')->assertOk();

    $entry = latestPerfEntry($this->perfDir);

    expect($entry['top_sql'])->toBeArray()->not->toBeEmpty()
        ->and($entry['top_sql'][0])->toHaveKeys(['ms', 'sql'])
        ->and(count($entry['top_sql']))->toBeLessThanOrEqual(3);
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
    $inventory = Mockery::mock(App\Base\Software\Services\SoftwareInventoryService::class);
    $inventory->shouldReceive('installedBundlesForStatusDiagnostics')->andReturn([]);
    app()->instance(App\Base\Software\Services\SoftwareInventoryService::class, $inventory);

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    // Measured 44 on 2026-07-12; the budget is ~2x for organic growth.
    expect($queries)->toBeLessThanOrEqual(90);
});
