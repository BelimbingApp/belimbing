<?php

namespace App\Base\Settings\Services;

use App\Base\Settings\Contracts\SettingsService;
use App\Base\Settings\DTO\Scope;
use App\Base\Settings\DTO\ScopeType;
use App\Base\Settings\Exceptions\InvalidSettingDefinitionException;
use App\Base\Settings\Models\Setting;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Crypt;

/**
 * Resolves declared runtime parameters and claimed operational state.
 *
 * Declared parameters resolve through their allowed DB scopes and then their
 * definition-owned default. Runtime state has no fallback and returns null
 * when its owning module has not stored a row.
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

    /**
     * Request/job-scoped memo of decoded database values.
     *
     * @var array<string, mixed>
     */
    private array $resolvedValues = [];

    /**
     * @var array<string, true>
     */
    private array $preloadedDefinitionScopes = [];

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SettingDefinitionRegistry $definitions,
        private readonly RuntimeSettingClaimRegistry $runtimeClaims,
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

        $unresolvedKeys = array_values(array_filter(
            $keys,
            fn (string $key): bool => ! array_key_exists(
                $this->cacheKey($key, null),
                $this->resolvedValues,
            ),
        ));
        $rows = $unresolvedKeys === []
            ? collect()
            : Setting::query()
                ->whereIn('key', $unresolvedKeys)
                ->whereNull('scope_type')
                ->whereNull('scope_id')
                ->get()
                ->keyBy('key');

        $values = [];

        foreach ($keys as $key) {
            $cacheKey = $this->cacheKey($key, null);

            if (! array_key_exists($cacheKey, $this->resolvedValues)) {
                $row = $rows->get($key);
                $databaseValue = $row instanceof Setting
                    ? $this->resolveSettingValue($row)
                    : null;
                $this->rememberResolvedValue($cacheKey, $databaseValue);
            }

            $databaseValue = $this->resolvedValues[$cacheKey] === self::CACHE_MISS_SENTINEL
                ? null
                : $this->resolvedValues[$cacheKey];
            $values[$key] = $databaseValue ?? $definitions[$key]->default;
        }

        return $values;
    }

    public function get(string $key, ?Scope $scope = null): mixed
    {
        $definition = $this->definitions->find($key);
        $this->assertKeyIsClaimed($key, $definition !== null);
        $scopeChain = $definition === null
            ? $this->buildScopeChain($scope)
            : array_values(array_filter(
                $this->buildScopeChain($scope),
                $definition->allowsScope(...),
            ));

        foreach ($scopeChain as $chainScope) {
            if ($definition !== null
                && $definition->key === $key
                && ! $definition->encrypted) {
                $this->preloadDeclaredScope($chainScope);
            }

            $value = $this->getFromDb($key, $chainScope);

            if ($value !== null) {
                return $value;
            }
        }

        if ($definition !== null) {
            return $definition->default;
        }

        return null;
    }

    /**
     * Write a setting to the DB layer at the given scope.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  Value to store (must be JSON-serializable)
     * @param  Scope|null  $scope  Target scope; null = global
     */
    public function set(string $key, mixed $value, ?Scope $scope = null): void
    {
        $definition = $this->definitions->find($key);
        $this->assertKeyIsClaimed($key, $definition !== null);
        $encrypted = false;

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
        $definition = $this->definitions->find($key);
        $this->assertKeyIsClaimed($key, $definition !== null);
        $definition?->assertScopeAllowed($scope);

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
        $definition = $this->definitions->find($key);
        $this->assertKeyIsClaimed($key, $definition !== null);
        $definition?->assertScopeAllowed($scope);

        if ($definition !== null
            && $definition->key === $key
            && ! $definition->encrypted) {
            $this->preloadDeclaredScope($scope);
        }

        return $this->getFromDb($key, $scope) !== null;
    }

    /**
     * Build the scope chain for cascade resolution.
     *
     * User scope cascades: user → company → global.
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

        if ($scope->type === ScopeType::USER) {
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

        if (array_key_exists($cacheKey, $this->resolvedValues)) {
            return $this->resolvedValues[$cacheKey] === self::CACHE_MISS_SENTINEL
                ? null
                : $this->resolvedValues[$cacheKey];
        }

        if ($ttl <= 0) {
            $value = $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));
            $this->rememberResolvedValue($cacheKey, $value);

            return $value;
        }

        $encryptionKey = $cacheKey.self::CACHE_ENCRYPTION_SUFFIX;
        $isEncrypted = $this->cache->get($encryptionKey);

        if ($isEncrypted === true) {
            $value = $this->resolveSettingValue(Setting::findByKeyAndScope($key, $scope));
            $this->rememberResolvedValue($cacheKey, $value);

            return $value;
        }

        if ($isEncrypted === false) {
            $cached = $this->cache->get($cacheKey);
            $this->resolvedValues[$cacheKey] = $cached;

            return $cached === self::CACHE_MISS_SENTINEL ? null : $cached;
        }

        $setting = Setting::findByKeyAndScope($key, $scope);

        if ($setting?->is_encrypted) {
            // Credentials are decrypted only for the caller. Never persist their
            // plaintext in a database, Redis, or filesystem cache store.
            $this->cache->forget($cacheKey);
            $this->cache->put($encryptionKey, true, $ttl);
            $value = $this->resolveSettingValue($setting);
            $this->rememberResolvedValue($cacheKey, $value);

            return $value;
        }

        $value = $this->resolveSettingValue($setting);
        $this->cache->put($encryptionKey, false, $ttl);
        $this->cache->put($cacheKey, $value ?? self::CACHE_MISS_SENTINEL, $ttl);
        $this->rememberResolvedValue($cacheKey, $value);

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
        unset($this->resolvedValues[$cacheKey]);
    }

    private function rememberResolvedValue(string $cacheKey, mixed $value): void
    {
        $this->resolvedValues[$cacheKey] = $value ?? self::CACHE_MISS_SENTINEL;
    }

    private function preloadDeclaredScope(?Scope $scope): void
    {
        $scopeKey = $scope === null
            ? 'global'
            : $scope->type->value.':'.$scope->id;

        if (isset($this->preloadedDefinitionScopes[$scopeKey])) {
            return;
        }

        $keys = [];

        foreach ($this->definitions->all() as $definition) {
            if ($definition->encrypted
                || str_contains($definition->key, '*')
                || ! $definition->allowsScope($scope)) {
                continue;
            }

            $cacheKey = $this->cacheKey($definition->key, $scope);

            if (! array_key_exists($cacheKey, $this->resolvedValues)) {
                $keys[] = $definition->key;
            }
        }

        if ($keys === []) {
            $this->preloadedDefinitionScopes[$scopeKey] = true;

            return;
        }

        $query = Setting::query()->whereIn('key', $keys);

        if ($scope === null) {
            $query->whereNull('scope_type')->whereNull('scope_id');
        } else {
            $query->where('scope_type', $scope->type->value)
                ->where('scope_id', $scope->id);
        }

        $rows = $query->get()->keyBy('key');

        foreach ($keys as $key) {
            $row = $rows->get($key);
            $this->rememberResolvedValue(
                $this->cacheKey($key, $scope),
                $row instanceof Setting ? $this->resolveSettingValue($row) : null,
            );
        }

        $this->preloadedDefinitionScopes[$scopeKey] = true;
    }

    private function assertKeyIsClaimed(string $key, bool $hasDefinition): void
    {
        if ($hasDefinition || $this->runtimeClaims->claims($key)) {
            return;
        }

        throw new InvalidSettingDefinitionException(
            "Setting [{$key}] has no discovered parameter definition or runtime-state claim.",
        );
    }
}
