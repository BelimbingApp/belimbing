<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Kanban column definition for a workflow's board view.
 *
 * Multiple statuses can map to the same column via StatusConfig.kanban_code.
 *
 * @property int $id
 * @property string $flow
 * @property string $code
 * @property string $label
 * @property int $position
 * @property int|null $wip_limit
 * @property array<string, mixed>|null $settings
 * @property string|null $description
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class KanbanColumn extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_workflow_kanban_columns';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'flow',
        'code',
        'label',
        'position',
        'wip_limit',
        'settings',
        'description',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'position' => 'integer',
        'wip_limit' => 'integer',
        'settings' => 'json',
        'is_active' => 'boolean',
    ];

    /**
     * Get statuses mapped to this kanban column.
     *
     * @return Collection<int, StatusConfig>
     */
    public function statuses(): Collection
    {
        return StatusConfig::query()
            ->forFlow($this->flow)
            ->forKanban($this->code)
            ->active()
            ->orderBy('position')
            ->get();
    }

    /**
     * Scope to columns for a specific flow.
     */
    #[ScopeAttribute]
    protected function forFlow(Builder $query, string $flow): Builder
    {
        return $query->where('flow', $flow);
    }

    /**
     * Scope to active columns, ordered by position.
     */
    #[ScopeAttribute]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
