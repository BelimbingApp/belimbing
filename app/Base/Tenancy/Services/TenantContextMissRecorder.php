<?php

namespace App\Base\Tenancy\Services;

use App\Base\Tenancy\Middleware\RequireTenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Operator ring buffer for {@see RequireTenantContext}
 * refusals: last 50 misses with route name and the resolver that returned null.
 *
 * The 404 body is unchanged; this only records signal for operators (tenant-audit
 * page and `blb:tenant:misses`). A broken cache store must never widen the miss
 * into a 500.
 */
class TenantContextMissRecorder
{
    public const string CACHE_KEY = 'blb.tenancy.tenant_context_misses';

    public const string METRIC_KEY = 'blb.tenancy.TenantContextMissing';

    public const int MAX_ENTRIES = 50;

    /**
     * Record one miss. `$resolver` is one of host|session|header — today only
     * the session (authenticated-user) channel exists; host/header stay reserved
     * names so operator tooling can stay stable when those resolvers land.
     */
    public function record(?string $routeName, string $resolver): void
    {
        try {
            Cache::increment(self::METRIC_KEY);

            $entry = [
                'route' => $routeName,
                'resolver' => $resolver,
                'at' => now()->toIso8601String(),
            ];

            Log::warning('TenantContextMissing', $entry);

            $misses = Cache::get(self::CACHE_KEY, []);
            if (! is_array($misses)) {
                $misses = [];
            }

            $misses[] = $entry;
            if (count($misses) > self::MAX_ENTRIES) {
                $misses = array_slice($misses, -self::MAX_ENTRIES);
            }

            Cache::put(self::CACHE_KEY, $misses, now()->addDays(7));
        } catch (Throwable) {
            // Swallowed by design; RequireTenantContext must still 404.
        }
    }

    /**
     * Most recent misses, oldest first (same order as stored).
     *
     * @return list<array{route: string|null, resolver: string, at: string}>
     */
    public function recent(): array
    {
        try {
            $misses = Cache::get(self::CACHE_KEY, []);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($misses)) {
            return [];
        }

        $normalized = [];
        foreach ($misses as $miss) {
            if (! is_array($miss)) {
                continue;
            }
            $resolver = $miss['resolver'] ?? null;
            $at = $miss['at'] ?? null;
            if (! is_string($resolver) || $resolver === '' || ! is_string($at) || $at === '') {
                continue;
            }
            $route = $miss['route'] ?? null;
            $normalized[] = [
                'route' => is_string($route) && $route !== '' ? $route : null,
                'resolver' => $resolver,
                'at' => $at,
            ];
        }

        return $normalized;
    }

    public function clear(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
            Cache::forget(self::METRIC_KEY);
        } catch (Throwable) {
            // Operator clear is best-effort.
        }
    }
}
