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
 * Lifecycle record for a workflow instance.
 *
 * Each row records a status transition: the status entered, who triggered it,
 * turnaround time from the previous status, and contextual snapshots.
 *
 * @property int $id
 * @property string $flow
 * @property int $flow_id
 * @property string $status
 * @property int|null $tat
 * @property int|null $actor_id
 * @property string|null $actor_role
 * @property string|null $actor_department
 * @property string|null $actor_company
 * @property array<int, array<string, mixed>>|null $assignees
 * @property string|null $comment
 * @property string|null $comment_tag
 * @property array<int, array<string, mixed>>|null $attachments
 * @property array<string, mixed>|null $metadata
 * @property Carbon $transitioned_at
 * @property Carbon|null $created_at
 */
class StatusHistory extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var string
     */
    protected $table = 'base_workflow_status_history';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'flow',
        'flow_id',
        'status',
        'tat',
        'actor_id',
        'actor_role',
        'actor_department',
        'actor_company',
        'assignees',
        'comment',
        'comment_tag',
        'attachments',
        'metadata',
        'transitioned_at',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'flow_id' => 'integer',
        'tat' => 'integer',
        'actor_id' => 'integer',
        'assignees' => 'json',
        'attachments' => 'json',
        'metadata' => 'json',
        'transitioned_at' => 'datetime',
    ];

    /**
     * Get the full timeline for a specific workflow instance, ordered chronologically.
     *
     * @return Collection<int, static>
     */
    public static function timeline(string $flow, int $flowId): Collection
    {
        return static::query()
            ->where('flow', $flow)
            ->where('flow_id', $flowId)
            ->orderBy('transitioned_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Get the latest history entry for a workflow instance.
     */
    public static function latest(string $flow, int $flowId): ?static
    {
        return static::query()
            ->where('flow', $flow)
            ->where('flow_id', $flowId)
            ->orderByDesc('transitioned_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Scope to history entries for a specific flow and instance.
     */
    #[ScopeAttribute]
    protected function forInstance(Builder $query, string $flow, int $flowId): Builder
    {
        return $query->where('flow', $flow)->where('flow_id', $flowId);
    }

    /**
     * Scope to history entries for a specific status.
     */
    #[ScopeAttribute]
    protected function forStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to entries that exceeded an SLA threshold (in seconds).
     */
    #[ScopeAttribute]
    protected function exceededSla(Builder $query, int $slaSeconds): Builder
    {
        return $query->whereNotNull('tat')->where('tat', '>', $slaSeconds);
    }

    /**
     * Scope to entries by a specific actor.
     */
    #[ScopeAttribute]
    protected function byActor(Builder $query, int $actorId): Builder
    {
        return $query->where('actor_id', $actorId);
    }
}
