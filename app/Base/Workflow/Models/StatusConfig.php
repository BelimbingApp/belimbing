<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Status node in a workflow's directed graph.
 *
 * Each row represents one status that a process instance can be in.
 * The transitions table defines edges between these nodes.
 *
 * @property int $id
 * @property string $flow
 * @property string $code
 * @property string $label
 * @property array<int, string>|null $pic
 * @property array<string, mixed>|null $notifications
 * @property int $position
 * @property array<int, string>|null $comment_tags
 * @property string|null $prompt
 * @property string|null $kanban_code
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, StatusTransition> $outboundTransitions
 * @property-read Collection<int, StatusTransition> $inboundTransitions
 * @property-read Collection<int, string> $nextStatuses
 */
class StatusConfig extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_workflow_status_configs';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'flow',
        'code',
        'label',
        'pic',
        'notifications',
        'position',
        'comment_tags',
        'prompt',
        'kanban_code',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'pic' => 'json',
        'notifications' => 'json',
        'position' => 'integer',
        'comment_tags' => 'json',
        'is_active' => 'boolean',
    ];

    /**
     * Transitions leaving this status (outbound edges).
     */
    public function outboundTransitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'from_code', 'code')
            ->where('base_workflow_status_transitions.flow', $this->flow)
            ->orderBy('position');
    }

    /**
     * Transitions arriving at this status (inbound edges).
     */
    public function inboundTransitions(): HasMany
    {
        return $this->hasMany(StatusTransition::class, 'to_code', 'code')
            ->where('base_workflow_status_transitions.flow', $this->flow);
    }

    /**
     * Computed accessor: status codes reachable from this status.
     *
     * Derives the list from the transitions table — the single source
     * of truth for which edges exist.
     *
     * @return Collection<int, string>
     */
    public function getNextStatusesAttribute(): Collection
    {
        return StatusTransition::query()
            ->where('flow', $this->flow)
            ->where('from_code', $this->code)
            ->where('is_active', true)
            ->orderBy('position')
            ->pluck('to_code');
    }

    /**
     * Scope to statuses for a specific flow.
     */
    #[ScopeAttribute]
    protected function forFlow(Builder $query, string $flow): Builder
    {
        return $query->where('flow', $flow);
    }

    /**
     * Scope to active statuses only.
     */
    #[ScopeAttribute]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to statuses mapped to a specific kanban column.
     */
    #[ScopeAttribute]
    protected function forKanban(Builder $query, string $kanbanCode): Builder
    {
        return $query->where('kanban_code', $kanbanCode);
    }
}
