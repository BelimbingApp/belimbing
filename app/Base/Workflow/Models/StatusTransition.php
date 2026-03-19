<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Workflow\Models;

use Illuminate\Database\Eloquent\Attributes\Scope as ScopeAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Directed edge in a workflow's status graph.
 *
 * Each row represents one allowed transition between two statuses,
 * carrying edge-level policy: capability, guard, action, SLA.
 *
 * @property int $id
 * @property string $flow
 * @property string $from_code
 * @property string $to_code
 * @property string|null $label
 * @property string|null $capability
 * @property string|null $guard_class
 * @property string|null $action_class
 * @property int|null $sla_seconds
 * @property array<string, mixed>|null $metadata
 * @property int $position
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class StatusTransition extends Model
{
    /**
     * @var string
     */
    protected $table = 'base_workflow_status_transitions';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'flow',
        'from_code',
        'to_code',
        'label',
        'capability',
        'guard_class',
        'action_class',
        'sla_seconds',
        'metadata',
        'position',
        'is_active',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'sla_seconds' => 'integer',
        'metadata' => 'json',
        'position' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Resolve the display label for this transition.
     *
     * Falls back to the target status label if no explicit transition label is set.
     */
    public function resolveLabel(): string
    {
        if ($this->label !== null) {
            return $this->label;
        }

        $targetStatus = StatusConfig::query()
            ->where('flow', $this->flow)
            ->where('code', $this->to_code)
            ->first();

        return $targetStatus?->label ?? $this->to_code;
    }

    /**
     * Scope to transitions for a specific flow.
     */
    #[ScopeAttribute]
    protected function forFlow(Builder $query, string $flow): Builder
    {
        return $query->where('flow', $flow);
    }

    /**
     * Scope to transitions from a specific source status.
     */
    #[ScopeAttribute]
    protected function fromStatus(Builder $query, string $fromCode): Builder
    {
        return $query->where('from_code', $fromCode);
    }

    /**
     * Scope to active transitions only.
     */
    #[ScopeAttribute]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to transitions for a specific flow from a specific source, ordered by position.
     */
    #[ScopeAttribute]
    protected function availableFrom(Builder $query, string $flow, string $fromCode): Builder
    {
        return $query->where('flow', $flow)
            ->where('from_code', $fromCode)
            ->where('is_active', true)
            ->orderBy('position');
    }
}
