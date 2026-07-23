<?php

namespace App\Base\Settings\Contracts;

use App\Base\Settings\DTO\Scope;

interface SettingsService
{
    /**
     * Resolve several settings at one scope.
     *
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    public function getMany(array $keys, ?Scope $scope = null): array;

    /**
     * Resolve a setting value through the cascade.
     *
     * Declared runtime parameters resolve from their allowed DB scopes to the
     * definition-owned code default. Undeclared keys temporarily retain the
     * legacy DB → config → caller-default path during migration.
     *
     * @param  string  $key  Dot-notation key (e.g., 'ai.tools.web_search.cache_ttl_minutes')
     * @param  mixed  $default  Fallback if no layer provides a value
     * @param  Scope|null  $scope  Target scope; null resolves global DB → config only
     */
    public function get(string $key, mixed $default = null, ?Scope $scope = null): mixed;

    /**
     * Write a setting to the DB layer at the given scope.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  Value to store (must be JSON-serializable)
     * @param  Scope|null  $scope  Target scope; null = global
     * @param  bool  $encrypted  Whether to encrypt the value at rest
     */
    public function set(string $key, mixed $value, ?Scope $scope = null, bool $encrypted = false): void;

    /**
     * Remove a DB-layer override, falling back to the next layer.
     */
    public function forget(string $key, ?Scope $scope = null): void;

    /**
     * Check whether a key has an explicit value at the given scope (DB only, no cascade).
     */
    public function has(string $key, ?Scope $scope = null): bool;
}
