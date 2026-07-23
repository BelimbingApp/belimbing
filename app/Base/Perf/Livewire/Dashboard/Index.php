<?php

namespace App\Base\Perf\Livewire\Dashboard;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Perf\Services\PerfLog;
use App\Base\Perf\Services\PerfRuntimeSettings;
use App\Base\Settings\Contracts\SettingsService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Human window over the request performance log and its installation-wide
 * recording controls. The log's first-class consumers remain coding agents
 * via `perf:slowest` / `perf:requests`.
 */
class Index extends Component
{
    use InteractsWithNotifications;

    private const WINDOWS = ['1h', '24h', '7d'];

    private const array SETTING_FIELDS = [
        'recordingEnabled' => PerfRuntimeSettings::ENABLED_KEY,
        'minimumDurationMs' => PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY,
        'slowSqlMinimumDurationMs' => PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY,
        'logPath' => PerfRuntimeSettings::LOG_PATH_KEY,
        'retentionDays' => PerfRuntimeSettings::RETENTION_DAYS_KEY,
    ];

    /** Timeline dot cap; beyond this the window is sampled evenly. */
    private const MAX_TIMELINE_POINTS = 600;

    /** Log-scale bounds for the timeline y-axis (ms). */
    private const TIMELINE_FLOOR_MS = 10.0;

    private const TIMELINE_CEILING_MS = 30_000.0;

    #[Url]
    public string $window = '1h';

    public bool $recordingEnabled = false;

    public string $minimumDurationMs = '';

    public string $slowSqlMinimumDurationMs = '';

    public string $logPath = '';

    public string $retentionDays = '';

    public function mount(PerfRuntimeSettings $runtimeSettings): void
    {
        $this->loadRuntimeSettings($runtimeSettings);
    }

    public function setWindow(string $window): void
    {
        if (in_array($window, self::WINDOWS, true)) {
            $this->window = $window;
        }
    }

    public function saveRuntimeSettings(
        SettingsService $settings,
        PerfRuntimeSettings $runtimeSettings,
    ): void {
        $this->authorizeSettingsManagement();

        $validated = $this->validate(
            $this->validationRules($runtimeSettings),
            attributes: $this->validationAttributes($runtimeSettings),
        );

        $settings->set(
            PerfRuntimeSettings::ENABLED_KEY,
            (bool) $validated['recordingEnabled'],
        );
        $settings->set(
            PerfRuntimeSettings::MINIMUM_DURATION_MS_KEY,
            (float) $validated['minimumDurationMs'],
        );
        $settings->set(
            PerfRuntimeSettings::SLOW_SQL_MINIMUM_DURATION_MS_KEY,
            (float) $validated['slowSqlMinimumDurationMs'],
        );
        $settings->set(
            PerfRuntimeSettings::RETENTION_DAYS_KEY,
            (int) $validated['retentionDays'],
        );

        $path = trim((string) ($validated['logPath'] ?? ''));

        if ($path === '') {
            $settings->forget(PerfRuntimeSettings::LOG_PATH_KEY);
        } else {
            $settings->set(PerfRuntimeSettings::LOG_PATH_KEY, $path);
        }

        $runtimeSettings->refresh();
        $this->loadRuntimeSettings($runtimeSettings);
        $this->resetValidation();
        $this->notify(__('Performance recording settings saved.'));
    }

    public function restoreRuntimeSettingDefaults(
        SettingsService $settings,
        PerfRuntimeSettings $runtimeSettings,
    ): void {
        $this->authorizeSettingsManagement();

        foreach (PerfRuntimeSettings::KEYS as $key) {
            $settings->forget($key);
        }

        $runtimeSettings->refresh();
        $this->loadRuntimeSettings($runtimeSettings);
        $this->resetValidation();
        $this->notify(__('Performance recording defaults restored.'));
    }

    public function render(PerfLog $log, PerfRuntimeSettings $runtimeSettings): View
    {
        // The window is URL-bound, so it can arrive as anything.
        if (! in_array($this->window, self::WINDOWS, true)) {
            $this->window = self::WINDOWS[0];
        }

        // Web requests only: job/command entries (perf:slowest --type=job)
        // have no response size or navigate flag and would distort the
        // latency scatter's story.
        $entries = array_values(array_filter(
            iterator_to_array($log->entriesSince(PerfLog::parseSince($this->window)), false),
            static fn (array $entry): bool => ($entry['type'] ?? 'http') === 'http',
        ));

        return view('livewire.admin.system.perf.index', [
            'windows' => self::WINDOWS,
            'summary' => $this->summarize($entries),
            'timeline' => $this->timeline($entries),
            'routes' => $this->byRoute($entries),
            'slowest' => $this->slowestRequests($entries),
            'canManagePerformanceSettings' => $this->canManageSettings(),
            'performanceSettingDefinitions' => $runtimeSettings->definitions(),
        ]);
    }

    private function loadRuntimeSettings(PerfRuntimeSettings $runtimeSettings): void
    {
        $settings = $runtimeSettings->snapshot();

        $this->recordingEnabled = $settings->enabled;
        $this->minimumDurationMs = $this->formatFloat($settings->minimumDurationMs);
        $this->slowSqlMinimumDurationMs = $this->formatFloat($settings->slowSqlMinimumDurationMs);
        $this->logPath = $settings->logPath ?? '';
        $this->retentionDays = (string) $settings->retentionDays;
    }

    /**
     * @return array<string, list<string>>
     */
    private function validationRules(PerfRuntimeSettings $runtimeSettings): array
    {
        $rules = [];

        foreach (self::SETTING_FIELDS as $field => $key) {
            $rules[$field] = $runtimeSettings->definition($key)->rules;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function validationAttributes(PerfRuntimeSettings $runtimeSettings): array
    {
        $attributes = [];

        foreach (self::SETTING_FIELDS as $field => $key) {
            $definition = $runtimeSettings->definition($key);
            $attributes[$field] = __($definition->label ?? $key);
        }

        return $attributes;
    }

    private function formatFloat(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }

    private function canManageSettings(): bool
    {
        $user = auth()->user();

        return $user !== null && app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.system.perf.manage')
            ->allowed;
    }

    private function authorizeSettingsManagement(): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        app(AuthorizationService::class)->authorize(
            Actor::forUser($user),
            'admin.system.perf.manage',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array{requests: int, p50: float, p95: float, db_share: float, proc_ms: float, cache_rate: float|null, slowest_route: ?string, slowest_route_p95: float}
     */
    private function summarize(array $entries): array
    {
        $times = array_column($entries, 'ms');
        sort($times);

        $totalMs = array_sum($times);
        $cacheHits = array_sum(array_column($entries, 'cache_hits'));
        $cacheMisses = array_sum(array_column($entries, 'cache_misses'));

        $routes = $this->byRoute($entries);
        $slowest = $routes[0] ?? null;

        return [
            'requests' => count($entries),
            'p50' => self::percentile($times, 50),
            'p95' => self::percentile($times, 95),
            'db_share' => $totalMs > 0.0 ? array_sum(array_column($entries, 'db_ms')) / $totalMs * 100 : 0.0,
            'proc_ms' => array_sum(array_column($entries, 'proc_ms')),
            'cache_rate' => ($cacheHits + $cacheMisses) > 0 ? $cacheHits / ($cacheHits + $cacheMisses) * 100 : null,
            'slowest_route' => $slowest['route'] ?? null,
            'slowest_route_p95' => $slowest['p95'] ?? 0.0,
        ];
    }

    /**
     * Dots for the latency scatter: x is time position across the window,
     * y is log-scaled duration, tone flags the ones worth a second look.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{points: list<array{x: float, y: float, tone: string, label: string}>, from: ?string, to: ?string}
     */
    private function timeline(array $entries): array
    {
        if ($entries === []) {
            return ['points' => [], 'from' => null, 'to' => null];
        }

        $count = count($entries);

        if ($count > self::MAX_TIMELINE_POINTS) {
            $step = $count / self::MAX_TIMELINE_POINTS;
            $sampled = [];
            for ($i = 0.0; $i < $count; $i += $step) {
                $sampled[] = $entries[(int) $i];
            }
            $entries = $sampled;
        }

        $first = strtotime((string) $entries[0]['ts']);
        $last = strtotime((string) end($entries)['ts']);
        $span = max(1, $last - $first);
        $logFloor = log10(self::TIMELINE_FLOOR_MS);
        $logSpan = log10(self::TIMELINE_CEILING_MS) - $logFloor;

        $points = array_map(function (array $entry) use ($first, $span, $logFloor, $logSpan): array {
            $ms = max((float) ($entry['ms'] ?? 0), 1.0);
            $clamped = min(max($ms, self::TIMELINE_FLOOR_MS), self::TIMELINE_CEILING_MS);

            return [
                'x' => (strtotime((string) $entry['ts']) - $first) / $span * 100,
                'y' => 100 - ((log10($clamped) - $logFloor) / $logSpan * 100),
                'tone' => match (true) {
                    ($entry['status'] ?? 200) >= 500, $ms >= 5_000 => 'danger',
                    $ms >= 1_000 => 'warning',
                    default => 'ok',
                },
                'label' => sprintf(
                    '%s  %s %s — %s ms',
                    substr((string) $entry['ts'], 11, 8),
                    $entry['method'] ?? '',
                    $entry['path'] ?? '',
                    number_format($ms),
                ),
            ];
        }, $entries);

        return [
            'points' => $points,
            'from' => substr((string) $entries[0]['ts'], 11, 5),
            'to' => substr((string) end($entries)['ts'], 11, 5),
        ];
    }

    /**
     * Aggregate by route with a DB / subprocess / other split of the average
     * request, sized against the slowest route so the bars compare honestly.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<array{route: string, hits: int, p50: float, p95: float, avg_ms: float, avg_queries: float, avg_procs: float, bar_width: float, db_pct: float, proc_pct: float}>
     */
    private function byRoute(array $entries): array
    {
        $grouped = [];

        foreach ($entries as $entry) {
            $grouped[$entry['route'] ?? $entry['path'] ?? __('(unknown)')][] = $entry;
        }

        $routes = [];

        foreach ($grouped as $route => $group) {
            $times = array_column($group, 'ms');
            sort($times);

            $avgMs = array_sum($times) / count($group);
            $avgDb = array_sum(array_column($group, 'db_ms')) / count($group);
            $avgProc = array_sum(array_column($group, 'proc_ms')) / count($group);

            $routes[] = [
                'route' => (string) $route,
                'hits' => count($group),
                'p50' => self::percentile($times, 50),
                'p95' => self::percentile($times, 95),
                'avg_ms' => $avgMs,
                'avg_queries' => array_sum(array_column($group, 'queries')) / count($group),
                'avg_procs' => array_sum(array_column($group, 'procs')) / count($group),
                'db_pct' => $avgMs > 0.0 ? min($avgDb / $avgMs * 100, 100) : 0.0,
                'proc_pct' => $avgMs > 0.0 ? min($avgProc / $avgMs * 100, 100) : 0.0,
            ];
        }

        usort($routes, fn (array $a, array $b): int => $b['p95'] <=> $a['p95']);
        $routes = array_slice($routes, 0, 12);

        $maxAvg = max(array_column($routes, 'avg_ms') ?: [1.0]);

        return array_map(static function (array $route) use ($maxAvg): array {
            $route['bar_width'] = max($route['avg_ms'] / $maxAvg * 100, 0.75);

            return $route;
        }, $routes);
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function slowestRequests(array $entries): array
    {
        usort($entries, fn (array $a, array $b): int => ($b['ms'] ?? 0) <=> ($a['ms'] ?? 0));

        return array_slice($entries, 0, 10);
    }

    /**
     * @param  list<float|int>  $sorted  Values sorted ascending
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
