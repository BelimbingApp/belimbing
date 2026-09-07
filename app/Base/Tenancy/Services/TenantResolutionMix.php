<?php

namespace App\Base\Tenancy\Services;

use App\Base\Perf\Services\PerfLog;
use Carbon\CarbonImmutable;

/**
 * Requests per tenant resolver over a recent window, read from the request
 * performance log (#781).
 *
 * The log line carries `tenant_resolver` (#769) and `tenant_id`; this counts
 * the lines for one tenant by resolver, and separately the lines that
 * resolved no tenant at all. Those misses have no tenant to scope by, so the
 * "none" count is the installation's, not the tenant's, and the page says so.
 */
class TenantResolutionMix
{
    public const int WINDOW_HOURS = 24;

    public function __construct(private readonly PerfLog $log) {}

    /**
     * @return array{resolvers: array<string, int>, none: int, hours: int}
     */
    public function forTenant(int $tenantId, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $resolvers = [];
        $none = 0;

        foreach ($this->log->entriesSince($now->subHours(self::WINDOW_HOURS)) as $entry) {
            if (($entry['type'] ?? null) !== 'http') {
                continue;
            }

            $resolver = $entry['tenant_resolver'] ?? null;

            if (! is_string($resolver) || $resolver === '') {
                $none++;

                continue;
            }

            if (! $this->belongsTo($entry, $tenantId)) {
                continue;
            }

            $resolvers[$resolver] = ($resolvers[$resolver] ?? 0) + 1;
        }

        arsort($resolvers);

        return ['resolvers' => $resolvers, 'none' => $none, 'hours' => self::WINDOW_HOURS];
    }

    /**
     * Tenant scope of the panel. A line without a tenant id is never another
     * tenant's, but it is not this one's either: it counts nowhere.
     *
     * @param  array<string, mixed>  $entry
     */
    private function belongsTo(array $entry, int $tenantId): bool
    {
        return isset($entry['tenant_id']) && (int) $entry['tenant_id'] === $tenantId;
    }
}
