<?php

namespace App\Base\Perf\Livewire\Widgets;

use App\Base\Dashboard\Widget;
use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerfRegressionStatusDiagnosticProvider;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/**
 * Dashboard widget: yesterday-to-now request health from the perf log, plus
 * the count of routes regressing against their weekly baseline. Visibility
 * is gated by `admin.system.perf.view` in Config/dashboard.php.
 */
class RequestHealth extends Widget
{
    private const CACHE_KEY = 'perf.widget-request-health.v1';

    public function render(PerfLog $log): View
    {
        // Scalars only in the cache: cache.serializable_classes is disabled.
        $summary = Cache::flexible(
            self::CACHE_KEY,
            [300, 1800],
            fn (): array => $this->summarize($log),
        );

        // Computed (and kept fresh) by the status-bar regression provider;
        // an empty cache just means "nothing reported yet".
        $regressions = Cache::get(PerfRegressionStatusDiagnosticProvider::CACHE_KEY, []);

        return view('livewire.perf.widgets.request-health', [
            'summary' => $summary,
            'regressionCount' => is_array($regressions) ? count($regressions) : 0,
            'perfUrl' => Route::has('admin.system.perf.index') ? route('admin.system.perf.index') : null,
        ]);
    }

    /**
     * @return array{requests: int, p50: float, p95: float, slowest_route: ?string}
     */
    private function summarize(PerfLog $log): array
    {
        $times = [];
        $byRoute = [];

        foreach ($log->entriesSince(PerfLog::parseSince('24h')) as $entry) {
            if (($entry['type'] ?? 'http') !== 'http') {
                continue;
            }

            $ms = (float) ($entry['ms'] ?? 0);
            $times[] = $ms;
            $route = (string) ($entry['route'] ?? $entry['path'] ?? '');

            if ($route !== '') {
                $byRoute[$route][] = $ms;
            }
        }

        sort($times);

        $slowestRoute = null;
        $slowestP95 = 0.0;

        foreach ($byRoute as $route => $routeTimes) {
            sort($routeTimes);
            $p95 = self::percentile($routeTimes, 95);

            if ($p95 > $slowestP95) {
                $slowestP95 = $p95;
                $slowestRoute = $route;
            }
        }

        return [
            'requests' => count($times),
            'p50' => self::percentile($times, 50),
            'p95' => self::percentile($times, 95),
            'slowest_route' => $slowestRoute,
        ];
    }

    /**
     * @param  list<float>  $sorted  Values sorted ascending
     */
    private static function percentile(array $sorted, int $percentile): float
    {
        if ($sorted === []) {
            return 0.0;
        }

        $index = (int) ceil($percentile / 100 * count($sorted)) - 1;

        return (float) $sorted[max(0, $index)];
    }
}
