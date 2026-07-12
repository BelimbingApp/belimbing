<?php

use App\Base\Perf\Services\PerfRegressionStatusDiagnosticProvider;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\User\Models\User;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->perfDir = storage_path('framework/testing/perf-regr-'.uniqid());
    config()->set('perf.enabled', false);
    config()->set('perf.path', $this->perfDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->perfDir);
});

/**
 * Write $count entries for a route: $daysAgo days back, each taking $ms.
 */
function writeRegressionFixture(string $dir, string $route, int $daysAgo, float $ms, int $count): void
{
    File::ensureDirectoryExists($dir);

    $ts = now()->subDays($daysAgo);

    for ($i = 0; $i < $count; $i++) {
        File::append(
            $dir.DIRECTORY_SEPARATOR.'perf-'.$ts->format('Y-m-d').'.jsonl',
            json_encode([
                'ts' => $ts->toIso8601String(),
                'type' => 'http',
                'method' => 'GET',
                'path' => '/'.str_replace('.', '/', $route),
                'route' => $route,
                'status' => 200,
                'ms' => $ms,
                'db_ms' => 5,
                'queries' => 10,
                'cache_hits' => 1,
                'cache_misses' => 0,
                'cache_writes' => 0,
                'procs' => 0,
                'proc_ms' => 0,
            ]).PHP_EOL,
        );
    }
}

it('warns when a route regresses against its own baseline', function (): void {
    // Fast all week, then slow today: 300 ms -> 2 s is a 6.7x regression.
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 3, ms: 300, count: 20);
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 0, ms: 2000, count: 15);

    $diagnostics = collect(app(PerfRegressionStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    expect($diagnostics)->toHaveCount(1)
        ->and($diagnostics[0]->id)->toBe('perf.route-regression')
        ->and($diagnostics[0]->summary)->toContain('slower than last week')
        ->and($diagnostics[0]->metadata['routes'][0]['route'])->toBe('dashboard')
        ->and($diagnostics[0]->metadata['routes'][0]['factor'])->toBeGreaterThan(2);
});

it('stays quiet when routes hold their baseline', function (): void {
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 3, ms: 300, count: 20);
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 0, ms: 350, count: 15);

    expect(collect(app(PerfRegressionStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('ignores routes without enough traffic to judge', function (): void {
    // A big factor but only three recent hits: noise, not a regression.
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 3, ms: 300, count: 20);
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 0, ms: 5000, count: 3);

    expect(collect(app(PerfRegressionStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser())))->toBeEmpty();
});

it('hides the diagnostic from users without the perf capability', function (): void {
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 3, ms: 300, count: 20);
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 0, ms: 2000, count: 15);

    $user = User::factory()->create([
        'company_id' => Company::factory()->create()->id,
    ]);

    expect(collect(app(PerfRegressionStatusDiagnosticProvider::class)->diagnosticsFor($user)))->toBeEmpty();
});

it('caches a snapshot that survives hardened cache serialization', function (): void {
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 3, ms: 300, count: 20);
    writeRegressionFixture($this->perfDir, 'dashboard', daysAgo: 0, ms: 2000, count: 15);

    collect(app(PerfRegressionStatusDiagnosticProvider::class)->diagnosticsFor(createAdminUser()));

    $cached = Illuminate\Support\Facades\Cache::get('perf.route-regressions.v1');
    $roundTripped = unserialize(serialize($cached), ['allowed_classes' => false]);

    expect($roundTripped)->toEqual($cached)
        ->and(collect($roundTripped)->flatten()->filter(fn ($value) => is_object($value)))->toBeEmpty();
});
