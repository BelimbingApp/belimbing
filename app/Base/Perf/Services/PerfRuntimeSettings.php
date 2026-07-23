<?php

namespace App\Base\Perf\Services;

use App\Base\Perf\DTO\PerfRuntimeSettingsSnapshot;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\SettingDefinition;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use Illuminate\Http\Request;

/**
 * Typed access to installation-wide performance instrumentation settings.
 */
final class PerfRuntimeSettings
{
    /**
     * Keep one coherent snapshot across a request and briefly across worker
     * reuse. Saves refresh the local worker immediately; other workers observe
     * a change within this small window.
     */
    private const float SNAPSHOT_CACHE_SECONDS = 1.0;

    private const string REQUEST_ATTRIBUTE = '_blb_perf_runtime_settings';

    public const string ENABLED_KEY = 'perf.enabled';

    public const string MINIMUM_DURATION_MS_KEY = 'perf.min_ms';

    public const string SLOW_SQL_MINIMUM_DURATION_MS_KEY = 'perf.slow_sql_min_ms';

    public const string LOG_PATH_KEY = 'perf.path';

    public const string RETENTION_DAYS_KEY = 'perf.retention_days';

    public const array KEYS = [
        self::ENABLED_KEY,
        self::MINIMUM_DURATION_MS_KEY,
        self::SLOW_SQL_MINIMUM_DURATION_MS_KEY,
        self::LOG_PATH_KEY,
        self::RETENTION_DAYS_KEY,
    ];

    private ?PerfRuntimeSettingsSnapshot $cachedSnapshot = null;

    private float $cachedAt = 0.0;

    public function __construct(
        private readonly SettingsService $settings,
        private readonly SettingDefinitionRegistry $definitions,
    ) {}

    public function snapshot(): PerfRuntimeSettingsSnapshot
    {
        $request = $this->currentRequest();
        $requestSnapshot = $request?->attributes->get(self::REQUEST_ATTRIBUTE);

        if ($requestSnapshot instanceof PerfRuntimeSettingsSnapshot) {
            return $requestSnapshot;
        }

        $now = microtime(true);

        if ($this->cachedSnapshot !== null && ($now - $this->cachedAt) < self::SNAPSHOT_CACHE_SECONDS) {
            return $this->cachedSnapshot;
        }

        $values = $this->settings->getMany(self::KEYS);

        $this->cachedSnapshot = new PerfRuntimeSettingsSnapshot(
            enabled: (bool) $values[self::ENABLED_KEY],
            minimumDurationMs: max(0.0, (float) $values[self::MINIMUM_DURATION_MS_KEY]),
            slowSqlMinimumDurationMs: max(0.0, (float) $values[self::SLOW_SQL_MINIMUM_DURATION_MS_KEY]),
            logPath: $this->normalizeLogPath($values[self::LOG_PATH_KEY]),
            retentionDays: max(1, (int) $values[self::RETENTION_DAYS_KEY]),
        );
        $this->cachedAt = $now;
        $request?->attributes->set(self::REQUEST_ATTRIBUTE, $this->cachedSnapshot);

        return $this->cachedSnapshot;
    }

    public function refresh(): void
    {
        $this->cachedSnapshot = null;
        $this->cachedAt = 0.0;
        $this->currentRequest()?->attributes->remove(self::REQUEST_ATTRIBUTE);
    }

    public function enabled(): bool
    {
        return $this->snapshot()->enabled;
    }

    public function minimumDurationMs(): float
    {
        return $this->snapshot()->minimumDurationMs;
    }

    public function slowSqlMinimumDurationMs(): float
    {
        return $this->snapshot()->slowSqlMinimumDurationMs;
    }

    public function logPath(): ?string
    {
        return $this->snapshot()->logPath;
    }

    public function retentionDays(): int
    {
        return $this->snapshot()->retentionDays;
    }

    public function definition(string $key): SettingDefinition
    {
        if (! in_array($key, self::KEYS, true)) {
            throw new \InvalidArgumentException("Unknown performance setting [{$key}].");
        }

        return $this->definitions->get($key);
    }

    /**
     * @return array<string, SettingDefinition>
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach (self::KEYS as $key) {
            $definitions[$key] = $this->definition($key);
        }

        return $definitions;
    }

    private function normalizeLogPath(mixed $path): ?string
    {
        if (! is_string($path) || trim($path) === '') {
            return null;
        }

        return trim($path);
    }

    private function currentRequest(): ?Request
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        return $request instanceof Request ? $request : null;
    }
}
