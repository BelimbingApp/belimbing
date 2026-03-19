<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Process registry — lightweight catalog of all configured workflows.
 *
 * @property int $id
 * @property string $code
 * @property string $label
 * @property string|null $module
 * @property string|null $description
 * @property string|null $model_class
 * @property array<string, mixed>|null $settings
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StatusConfig> $statusConfigs
 * @property-read Collection<int, StatusTransition> $transitions
 * @property-read Collection<int, KanbanColumn> $kanbanColumns
 */
class Workflow extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_workflow';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'code',
        'label',
        'module',
        'description',
        'model_class',
        'settings',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'settings' => 'json',
        'is_active' => 'boolean',
    ];

    public function statusConfigs(): HasMany
    {
        return $this->hasMany(StatusConfig::class, 'flow', 'code')
            ->orderBy('position');
    }

    public function transitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'flow', 'code');
    }

    public function kanbanColumns(): HasMany
    {
        return $this->hasMany(KanbanColumn::class, 'flow', 'code')
            ->orderBy('position');
    }

    /**
     * Scope to active workflows only.
     */
    #[ScopeAttribute]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to workflows belonging to a specific module.
     */
    #[ScopeAttribute]
    protected function forModule(Builder $query, string $module): Builder
    {
        return $query->where('module', $module);
    }
}
