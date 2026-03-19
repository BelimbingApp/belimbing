<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Services;

use App\Base\Workflow\Models\StatusConfig;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages StatusConfig records for a flow.
 *
 * Loads and caches the status graph (nodes) per flow. Cache is
 * invalidated on mutation.
 */
class StatusManager
{
    private const CACHE_TTL = 3600;

    private const CACHE_PREFIX = 'workflow.statuses.';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Get all active statuses for a flow, ordered by position.
     *
     * @return Collection<int, StatusConfig>
     */
    public function getStatuses(string $flow): Collection
    {
        $cacheKey = self::CACHE_PREFIX.$flow;

        return $this->cache->remember($cacheKey, self::CACHE_TTL, function () use ($flow): Collection {
            return StatusConfig::query()
                ->forFlow($flow)
                ->active()
                ->orderBy('position')
                ->get();
        });
    }

    /**
     * Get a specific status config by flow and code.
     */
    public function getStatus(string $flow, string $code): ?StatusConfig
    {
        return $this->getStatuses($flow)
            ->first(fn (StatusConfig $s): bool => $s->code === $code);
    }

    /**
     * Create a new status config.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StatusConfig
    {
        $status = StatusConfig::query()->create($attributes);
        $this->clearCache($status->flow);

        return $status;
    }

    /**
     * Update a status config.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(StatusConfig $status, array $attributes): StatusConfig
    {
        $status->update($attributes);
        $this->clearCache($status->flow);

        return $status;
    }

    /**
     * Clear the cached statuses for a flow.
     */
    public function clearCache(string $flow): void
    {
        $this->cache->forget(self::CACHE_PREFIX.$flow);
    }
}
