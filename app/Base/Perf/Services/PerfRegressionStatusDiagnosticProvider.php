<?php

namespace App\Base\Perf\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Tells the status bar when the app itself is getting slower: each HTTP
 * route's p95 over the last day is compared against its own p95 over the
 * prior six days, and sustained regressions surface as a warning for
 * whoever is looking — human or agent — without anyone remembering to run
 * perf:slowest. This is what keeps the perf log self-sustaining as modules
 * accumulate: degradation announces itself.
 */
final class PerfRegressionStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    /** Read by the dashboard RequestHealth widget too. */
    public const CACHE_KEY = 'perf.route-regressions.v1';

    private const FRESH_SECONDS = 900;

    private const STALE_SECONDS = 3600;

    /** A route must appear this often in both windows to be judged. */
    private const MIN_HITS = 10;

    /** Recent p95 must be at least this multiple of the baseline p95. */
    private const MIN_FACTOR = 2.0;

    /** ...and at least this slow in absolute terms (ms). */
    private const MIN_RECENT_P95_MS = 750;

    private const MAX_REPORTED = 3;

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly PerfLog $log,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canViewPerf($user)) {
            return [];
        }

        // Scalars only in the cache: cache.serializable_classes is disabled.
        $regressions = Cache::flexible(
            self::CACHE_KEY,
            [self::FRESH_SECONDS, self::STALE_SECONDS],
            fn (): array => $this->computeRegressions(),
        );

        if ($regressions === []) {
            return [];
        }

        return [$this->regressionDiagnostic($regressions)];
    }

    /**
     * @return list<array{route: string, recent_p95: float, baseline_p95: float, factor: float}>
     */
    private function computeRegressions(): array
    {
        $recentCutoff = now()->subDay()->toIso8601String();
        $byRoute = [];

        foreach ($this->log->entriesSince(PerfLog::parseSince('7d')) as $entry) {
            if (($entry['type'] ?? 'http') !== 'http') {
                continue;
            }

            $route = $entry['route'] ?? $entry['path'] ?? null;

            if ($route === null) {
                continue;
            }

            $bucket = ($entry['ts'] ?? '') >= $recentCutoff ? 'recent' : 'baseline';
            $byRoute[$route][$bucket][] = (float) ($entry['ms'] ?? 0);
        }

        $regressions = [];

        foreach ($byRoute as $route => $windows) {
            $recent = $windows['recent'] ?? [];
            $baseline = $windows['baseline'] ?? [];

            if (count($recent) < self::MIN_HITS || count($baseline) < self::MIN_HITS) {
                continue;
            }

            $recentP95 = self::percentile($recent, 95);
            $baselineP95 = self::percentile($baseline, 95);

            if ($baselineP95 <= 0.0
                || $recentP95 < self::MIN_RECENT_P95_MS
                || $recentP95 < $baselineP95 * self::MIN_FACTOR) {
                continue;
            }

            $regressions[] = [
                'route' => (string) $route,
                'recent_p95' => round($recentP95, 1),
                'baseline_p95' => round($baselineP95, 1),
                'factor' => round($recentP95 / $baselineP95, 1),
            ];
        }

        usort($regressions, fn (array $a, array $b): int => $b['factor'] <=> $a['factor']);

        return array_slice($regressions, 0, self::MAX_REPORTED);
    }

    /**
     * @param  list<array{route: string, recent_p95: float, baseline_p95: float, factor: float}>  $regressions
     */
    private function regressionDiagnostic(array $regressions): StatusBarDiagnostic
    {
        $worst = $regressions[0];

        return new StatusBarDiagnostic(
            id: 'perf.route-regression',
            severity: StatusVariant::Warning,
            source: __('Performance'),
            summary: trans_choice(':count route is much slower than last week|:count routes are much slower than last week', count($regressions), [
                'count' => count($regressions),
            ]),
            detail: __(':route p95 went from :baseline ms to :recent ms (:factor×). Open Performance to see where the time goes.', [
                'route' => $worst['route'],
                'baseline' => number_format($worst['baseline_p95']),
                'recent' => number_format($worst['recent_p95']),
                'factor' => $worst['factor'],
            ]),
            target: Route::has('admin.system.perf.index') ? route('admin.system.perf.index') : null,
            metadata: [
                'routes' => $regressions,
            ],
        );
    }

    private function canViewPerf(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.perf.view')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  list<float>  $values
     */
    private static function percentile(array $values, int $percentile): float
    {
        sort($values);
        $index = (int) ceil($percentile / 100 * count($values)) - 1;

        return (float) $values[max(0, $index)];
    }
}
