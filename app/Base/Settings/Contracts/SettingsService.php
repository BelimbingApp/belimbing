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
     * Resolve a declared runtime parameter or claimed runtime-state value.
     *
     * Parameters resolve through definition-approved scopes to their declared
     * default. Claimed runtime state resolves through the supplied context and
     * returns null when no row exists.
     */
    public function get(string $key, ?Scope $scope = null): mixed;

    /**
     * Write a setting to the DB layer at the given scope.
     *
     * @param  string  $key  Dot-notation key
     * @param  mixed  $value  Value to store (must be JSON-serializable)
     * @param  Scope|null  $scope  Target scope; null = global
     */
    public function set(string $key, mixed $value, ?Scope $scope = null): void;

    /**
     * Remove a DB-layer override, falling back to the next layer.
     */
    public function forget(string $key, ?Scope $scope = null): void;

    /**
     * Check whether a key has an explicit value at the given scope (DB only, no cascade).
     */
    public function has(string $key, ?Scope $scope = null): bool;
}
