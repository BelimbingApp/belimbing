<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Settings\Models;

use App\Base\Settings\DTO\Scope;
use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Setting model for the base_settings table.
 *
 * Stores key-value pairs scoped to global, company, or employee level.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property bool $is_encrypted
 * @property string|null $scope_type
 * @property int|null $scope_id
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class Setting extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_settings';

    /**
     * @var array<string>
     */
    protected $fillable = [
        'key',
        'value',
        'is_encrypted',
        'scope_type',
        'scope_id',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'value' => 'json',
        'is_encrypted' => 'boolean',
        'scope_id' => 'integer',
    ];

    /**
     * Scope query to a specific setting scope (or global when null).
     */
    #[ScopeAttribute]
    protected function forScope(Builder $query, ?Scope $scope): Builder
    {
        if ($scope === null) {
            return $query->whereNull('scope_type')->whereNull('scope_id');
        }

        return $query->where('scope_type', $scope->type->value)
            ->where('scope_id', $scope->id);
    }

    /**
     * Scope query to global settings only.
     */
    #[ScopeAttribute]
    protected function global(Builder $query): Builder
    {
        return $query->whereNull('scope_type')->whereNull('scope_id');
    }

    /**
     * Scope query to settings with keys matching a prefix.
     */
    #[ScopeAttribute]
    protected function keyPrefix(Builder $query, string $prefix): Builder
    {
        return $query->where('key', 'like', $prefix.'%');
    }

    /**
     * Find a setting by key and scope.
     */
    public static function findByKeyAndScope(string $key, ?Scope $scope): ?self
    {
        return self::query()
            ->where('key', $key)
            ->forScope($scope)
            ->first();
    }
}
