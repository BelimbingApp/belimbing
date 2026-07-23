<?php

namespace App\Base\Settings\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\DTO\ScopeType;
use App\Base\Settings\Models\Setting;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Crypt;

/**
 * Resolves declared runtime parameters and legacy settings.
 *
 * Declared parameters resolve through their allowed DB scopes and then their
 * definition-owned default. Undeclared keys temporarily retain the legacy
 * DB → config → caller-default path during the settings-model migration.
 *
 * Each DB lookup is independently cached to keep invalidation simple:
 * on set/forget, only the specific (key, scope) cache entry is busted.
 */
class DatabaseSettingsService implements SettingsService
{
    public const int DEFAULT_CACHE_TTL_SECONDS = 6 * 60 * 60;

    /**
     * Sentinel value indicating "no DB row exists" in cache.
     *
     * Prevents repeated DB queries for keys with no override.
     */
    private const CACHE_MISS_SENTINEL = '__blb_settings_miss__';

    private const CACHE_ENCRYPTION_SUFFIX = ':is-encrypted';

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingDefinitionRegistry $definitions,
    ) {}

    /**
     * Resolve a group of settings while retaining definition-owned defaults.
     *
     * Global declared parameters use one database query so hot-path consumers
     * do not pay one query per key. Other combinations retain the exact
     * single-key resolver semantics.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys, ?Scope $scope = null): array
    {
        foreach ($keys as $key) {
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException('Setting keys must be non-empty strings.');
            }
        }

        $keys = array_values(array_unique($keys));

        if ($keys === []) {
            return [];
        }

        $definitions = [];

        foreach ($keys as $key) {
            $definition = $this->definitions->find($key);

            if ($definition === null || $scope !== null || ! $definition->allowsScope(null)) {
                $values = [];

                foreach ($keys as $settingKey) {
                    $values[$settingKey] = $this->get($settingKey, scope: $scope);
                }

                return $values;
            }

            $definitions[$key] = $definition;
        }

        $rows = Setting::query()
            ->whereIn('key', $keys)
            ->whereNull('scope_type')
            ->whereNull('scope_id')
            ->get()
            ->keyBy('key');

        $values = [];

        foreach ($keys as $key) {
            $row = $rows->get($key);
            $values[$key] = $row instanceof Setting
                ? $this->resolveSettingValue($row)
                : $definitions[$key]->default;
        }

        return $values;
    }

    /**
     * Resolve a setting value through the cascade.
     *
     * Declared parameters ignore config and caller defaults. Undeclared keys
     * retain the legacy fallback path until their definitions are migrated.
     *
     * @param  string  $key  Dot-notation key (e.g., 'ai.tools.web_search.cache_ttl_minutes')
     * @param  mixed  $default  Fallback if no layer provides a value
     * @param  Scope|null  $scope  Target scope; null resolves global DB → config only
     */
    public function get(string $key, mixed $default = null, ?Scope $scope = null): mixed
    {
        $definition = $this->definitions->find($key);
        $scopeChain = $definition === null
            ? $this->buildScopeChain($scope)
            : array_values(array_filter(
                $this->buildScopeChain($scope),
                $definition->allowsScope(...),
            ));

        foreach ($scopeChain as $chainScope) {
            $value = $this->getFromDb($key, $chainScope);

            if ($value !== null) {
                return $value;
            }
        }

        if ($definition !== null) {
            return $definition->default;
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
        $definition = $this->definitions->find($key);

        if ($definition !== null) {
            $definition->assertScopeAllowed($scope);
            $definition->assertStorableValue($value);
            $encrypted = $definition->encrypted;
        }

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
        $this->definitions->find($key)?->assertScopeAllowed($scope);

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
        $this->definitions->find($key)?->assertScopeAllowed($scope);

        return $this->getFromDb($key, $scope) !== null;
    }

    /**
     * Build the scope chain for cascade resolution.
     *
     * User scope cascades: user → company → global.
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

        if (in_array($scope->type, [ScopeType::USER, ScopeType::EMPLOYEE], true)) {
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
        $ttl = (int) config('settings.cache_ttl', self::DEFAULT_CACHE_TTL_SECONDS);
        $cacheKey = $this->cacheKey($key, $scope);

        if ($ttl <= 0) {
            return $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));
        }

        $encryptionKey = $cacheKey.self::CACHE_ENCRYPTION_SUFFIX;
        $isEncrypted = $this->cache->get($encryptionKey);

        if ($isEncrypted === true) {
            return $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));
        }

        if ($isEncrypted === false) {
            $cached = $this->cache->get($cacheKey);

            return $cached === self::CACHE_MISS_SENTINEL ? null : $cached;
        }

        $setting = Setting::findByKeyAndScope($key, $scope);

        if ($setting?->is_encrypted) {
            // Credentials are decrypted only for the caller. Never persist their
            // plaintext in a database, Redis, or filesystem cache store.
            $this->cache->forget($cacheKey);
            $this->cache->put($encryptionKey, true, $ttl);

            return $this->resolveSettingValue($setting);
        }

        $value = $this->resolveSettingValue($setting);
        $this->cache->put($encryptionKey, false, $ttl);
        $this->cache->put($cacheKey, $value ?? self::CACHE_MISS_SENTINEL, $ttl);

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
        $cacheKey = $this->cacheKey($key, $scope);

        $this->cache->forget($cacheKey);
        $this->cache->forget($cacheKey.self::CACHE_ENCRYPTION_SUFFIX);
    }
}
