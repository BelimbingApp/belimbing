<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\DTO\ScopeType;
use App\Base\Settings\Models\Setting;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Crypt;

/**
 * Resolves settings through the layered cascade.
 *
 * Resolution order (most specific wins):
 *   employee override → company override → global DB → config file → default
 *
 * Each DB lookup is independently cached to keep invalidation simple:
 * on set/forget, only the specific (key, scope) cache entry is busted.
 */
class DatabaseSettingsService implements SettingsService
{
    /**
     * Sentinel value indicating "no DB row exists" in cache.
     *
     * Prevents repeated DB queries for keys with no override.
     */
    private const CACHE_MISS_SENTINEL = '__blb_settings_miss__';

    public function __construct(
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Resolve a setting value through the cascade.
     *
     * Walks the scope chain from most specific to least, returning
     * the first value found. Falls back to config() then $default.
     *
     * @param  string  $key  Dot-notation key (e.g., 'ai.tools.web_search.cache_ttl_minutes')
     * @param  mixed  $default  Fallback if no layer provides a value
     * @param  Scope|null  $scope  Target scope; null resolves global DB → config only
     */
    public function get(string $key, mixed $default = null, ?Scope $scope = null): mixed
    {
        foreach ($this->buildScopeChain($scope) as $chainScope) {
            $value = $this->getFromDb($key, $chainScope);

            if ($value !== null) {
                return $value;
            }
        }

        return config($key, $default);
    }

    /**
     * Write a setting to the DB layer at the given scope.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  Value to store (must be JSON-serializable)
     * @param  Scope|null  $scope  Target scope; null = global
     * @param  bool  $encrypted  Whether to encrypt the value at rest
     */
    public function set(string $key, mixed $value, ?Scope $scope = null, bool $encrypted = false): void
    {
        $storeValue = $encrypted
            ? Crypt::encryptString(json_encode($value))
            : $value;

        Setting::query()->updateOrCreate(
            $this->scopeAttributes($key, $scope),
            ['value' => $storeValue, 'is_encrypted' => $encrypted]
        );

        $this->bustCache($key, $scope);
    }

    /**
     * Remove a DB-layer override, falling back to the next layer.
     */
    public function forget(string $key, ?Scope $scope = null): void
    {
        Setting::query()
            ->where($this->scopeAttributes($key, $scope))
            ->delete();

        $this->bustCache($key, $scope);
    }

    /**
     * Check whether a key has an explicit value at the given scope (DB only, no cascade).
     */
    public function has(string $key, ?Scope $scope = null): bool
    {
        return $this->getFromDb($key, $scope) !== null;
    }

    /**
     * Build the scope chain for cascade resolution.
     *
     * Employee scope cascades: employee → company → global.
     * Company scope cascades: company → global.
     * Null scope: global only.
     *
     * @return array<int, Scope|null>
     */
    private function buildScopeChain(?Scope $scope): array
    {
        if ($scope === null) {
            return [null];
        }

        if ($scope->type === ScopeType::EMPLOYEE) {
            $chain = [$scope];

            if ($scope->companyId !== null) {
                $chain[] = Scope::company($scope->companyId);
            }

            $chain[] = null;

            return $chain;
        }

        return [$scope, null];
    }

    /**
     * Look up a single DB row by key and scope, with caching.
     *
     * Returns the decoded value or null if no row exists.
     * Encrypted values are transparently decrypted on read.
     */
    private function getFromDb(string $key, ?Scope $scope): mixed
    {
        $ttl = (int) config('settings.cache_ttl', 3600);
        $cacheKey = $this->cacheKey($key, $scope);

        if ($ttl <= 0) {
            return $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));
        }

        $cached = $this->cache->get($cacheKey);

        if ($cached === self::CACHE_MISS_SENTINEL) {
            return null;
        }

        if ($cached !== null) {
            return $cached;
        }

        $value = $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));

        $this->cache->put(
            $cacheKey,
            $value ?? self::CACHE_MISS_SENTINEL,
            $ttl
        );

        return $value;
    }

    /**
     * Extract the value from a Setting model, decrypting if necessary.
     */
    private function resolveSettingValue(?Setting $setting): mixed
    {
        if ($setting === null) {
            return null;
        }

        if ($setting->is_encrypted) {
            return json_decode(Crypt::decryptString($setting->value), true);
        }

        return $setting->value;
    }

    /**
     * Build query attributes for key + scope.
     *
     * @return array<string, mixed>
     */
    private function scopeAttributes(string $key, ?Scope $scope): array
    {
        return [
            'key' => $key,
            'scope_type' => $scope?->type->value,
            'scope_id' => $scope?->id,
        ];
    }

    /**
     * Build the cache key for a specific (key, scope) pair.
     */
    private function cacheKey(string $key, ?Scope $scope): string
    {
        $prefix = config('settings.cache_prefix', 'blb:settings');

        if ($scope === null) {
            return $prefix.':global:'.$key;
        }

        return $prefix.':'.$scope->type->value.':'.$scope->id.':'.$key;
    }

    /**
     * Bust the cache entry for a specific (key, scope) pair.
     */
    private function bustCache(string $key, ?Scope $scope): void
    {
        $this->cache->forget($this->cacheKey($key, $scope));
    }
}
