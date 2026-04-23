<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\BrowserSessionStatus;
use App\Modules\Core\Company\Models\Company;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Persistent browser session — the canonical runtime unit for browser automation.
 *
 * Tracks session lifecycle, ownership, current page/tab state, and expiry.
 * Survives across PHP requests and process boundaries. Active sessions are
 * bound to a running Playwright process managed by the runtime adapter.
 *
 * @property string $id
 * @property int $agent_employee_id
 * @property int|null $acting_for_user_id
 * @property int $company_id
 * @property BrowserSessionStatus $status
 * @property bool $headless
 * @property string|null $active_tab_id
 * @property string|null $current_url
 * @property array<int, array<string, mixed>>|null $tabs
 * @property array<string, mixed>|null $page_state
 * @property string|null $failure_reason
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $last_activity_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $agentEmployee
 * @property-read User|null $actingForUser
 * @property-read Company $company
 * @property-read \Illuminate\Database\Eloquent\Collection<int, BrowserArtifact> $artifacts
 */
class BrowserSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'ai_browser_sessions';

    protected $fillable = [
        'id',
        'agent_employee_id',
        'acting_for_user_id',
        'company_id',
        'status',
        'headless',
        'active_tab_id',
        'current_url',
        'tabs',
        'page_state',
        'failure_reason',
        'meta',
        'last_activity_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BrowserSessionStatus::class,
            'headless' => 'boolean',
            'tabs' => 'json',
            'page_state' => 'json',
            'meta' => 'json',
            'last_activity_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    // ─── Relationships ──────────────────────────────────────────────

    public function agentEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'agent_employee_id');
    }

    public function actingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(BrowserArtifact::class, 'browser_session_id');
    }

    // ─── State queries ──────────────────────────────────────────────

    /**
     * Whether the session is in a terminal state and cannot accept actions.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether the session can accept new browser actions.
     */
    public function isActionable(): bool
    {
        return $this->status->isActionable();
    }

    /**
     * Whether the session has passed its expiry time.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    // ─── Scopes ─────────────────────────────────────────────────────

    /**
     * Scope to non-terminal (live) sessions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeActive(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereNotIn('status', [
            BrowserSessionStatus::Expired->value,
            BrowserSessionStatus::Failed->value,
            BrowserSessionStatus::Closed->value,
        ]);
    }

    /**
     * Scope to sessions past their expiry time but not yet marked expired.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     * @return \Illuminate\Database\Eloquent\Builder<self>
     */
    public function scopeStale(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->active()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }
}
