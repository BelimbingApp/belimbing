<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Services;

use App\Base\Workflow\Models\StatusTransition;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Database\Eloquent\Collection;

/**
 * Manages StatusTransition records for a flow.
 *
 * Loads and caches edge-level policy per flow. Cache is invalidated on mutation.
 */
class TransitionManager
{
    private const CACHE_TTL = 3600;

    private const CACHE_PREFIX = 'workflow.transitions.';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Get all active transitions from a specific status in a flow.
     *
     * @return Collection<int, StatusTransition>
     */
    public function getAvailableTransitions(string $flow, string $fromCode): Collection
    {
        return $this->getAllTransitions($flow)
            ->filter(fn (StatusTransition $t): bool => $t->from_code === $fromCode && $t->is_active)
            ->sortBy('position')
            ->values();
    }

    /**
     * Get a specific transition by flow, from_code, and to_code.
     */
    public function getTransition(string $flow, string $fromCode, string $toCode): ?StatusTransition
    {
        return $this->getAllTransitions($flow)
            ->first(fn (StatusTransition $t): bool => $t->from_code === $fromCode && $t->to_code === $toCode);
    }

    /**
     * Get all transitions for a flow (cached).
     *
     * @return Collection<int, StatusTransition>
     */
    public function getAllTransitions(string $flow): Collection
    {
        $cacheKey = self::CACHE_PREFIX.$flow;

        $cached = $this->cache->get($cacheKey);
        if ($cached instanceof Collection) {
            return $cached;
        }

        // Discard stale or unserializable cache payloads (e.g. __PHP_Incomplete_Class).
        $this->cache->forget($cacheKey);

        $transitions = StatusTransition::query()
            ->forFlow($flow)
            ->orderBy('position')
            ->get();

        $this->cache->put($cacheKey, $transitions, self::CACHE_TTL);

        return $transitions;
    }

    /**
     * Create a new transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): StatusTransition
    {
        $transition = StatusTransition::query()->create($attributes);
        $this->clearCache($transition->flow);

        return $transition;
    }

    /**
     * Update a transition.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(StatusTransition $transition, array $attributes): StatusTransition
    {
        $transition->update($attributes);
        $this->clearCache($transition->flow);

        return $transition;
    }

    /**
     * Clear the cached transitions for a flow.
     */
    public function clearCache(string $flow): void
    {
        $this->cache->forget(self::CACHE_PREFIX.$flow);
    }
}
