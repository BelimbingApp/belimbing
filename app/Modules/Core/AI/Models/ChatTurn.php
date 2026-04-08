<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Enums\TurnPhase;
use App\Modules\Core\AI\Enums\TurnStatus;
use App\Modules\Core\Employee\Models\Employee;
use App\Modules\Core\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Chat Turn — the user-visible unit of a coding-agent conversation.
 *
 * Each turn represents one user prompt and everything the agent does
 * in response. The UI watches turns for live activity; operators inspect
 * the underlying ai_runs for execution telemetry.
 *
 * @property string $id ULID primary key
 * @property int $employee_id Agent employee executing the turn
 * @property string $session_id Chat session identifier
 * @property int|null $acting_for_user_id User on whose behalf the turn runs
 * @property TurnStatus $status Lifecycle state (queued → completed/failed/cancelled)
 * @property TurnPhase $current_phase Fine-grained phase label for busy signal
 * @property string|null $current_label Optional human-readable label (e.g., tool name)
 * @property int $last_event_seq Highest seq written — used for next-seq allocation
 * @property string|null $current_run_id Active ai_run.id if a run is in progress
 * @property array<string, mixed>|null $meta Arbitrary metadata
 * @property Carbon|null $started_at When execution began
 * @property Carbon|null $finished_at When the turn reached a terminal state
 * @property Carbon|null $cancel_requested_at When cancellation was requested
 * @property array<string, mixed>|null $runtime_meta Ephemeral execution context (e.g. cancel reason)
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Employee $employee
 * @property-read User|null $actingForUser
 * @property-read Collection<int, ChatTurnEvent> $events
 * @property-read Collection<int, AiRun> $runs
 */
class ChatTurn extends Model
{
    use HasUlids;

    /**
     * @var string
     */
    protected $table = 'ai_chat_turns';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'employee_id',
        'session_id',
        'acting_for_user_id',
        'status',
        'current_phase',
        'current_label',
        'last_event_seq',
        'current_run_id',
        'meta',
        'started_at',
        'finished_at',
        'cancel_requested_at',
        'runtime_meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => TurnStatus::class,
            'current_phase' => TurnPhase::class,
            'last_event_seq' => 'integer',
            'meta' => 'json',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'runtime_meta' => 'json',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    /**
     * Agent (employee) executing this turn.
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * User on whose behalf this turn runs.
     */
    public function actingForUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acting_for_user_id');
    }

    /**
     * Ordered event stream for this turn.
     */
    public function events(): HasMany
    {
        return $this->hasMany(ChatTurnEvent::class, 'turn_id')->orderBy('seq');
    }

    /**
     * LLM runs spawned during this turn.
     */
    public function runs(): HasMany
    {
        return $this->hasMany(AiRun::class, 'turn_id');
    }

    // ── Queries ──────────────────────────────────────────────────────

    /**
     * Events after a given sequence number (for SSE resume).
     *
     * @return HasMany<ChatTurnEvent, $this>
     */
    public function eventsAfter(int $afterSeq): HasMany
    {
        return $this->events()->where('seq', '>', $afterSeq);
    }

    // ── State helpers ────────────────────────────────────────────────

    /**
     * Whether this turn has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    /**
     * Whether cancellation has been requested for this turn.
     */
    public function isCancelRequested(): bool
    {
        return $this->cancel_requested_at !== null;
    }

    /**
     * Request cancellation of this turn.
     *
     * Sets the cancel timestamp and stores the reason in runtime_meta.
     *
     * @param  string  $reason  Human-readable cancellation reason
     */
    public function requestCancel(string $reason = 'User pressed stop'): void
    {
        $this->cancel_requested_at = now();
        $this->runtime_meta = array_merge($this->runtime_meta ?? [], [
            'cancel_reason' => $reason,
        ]);
        $this->save();
    }

    /**
     * Whether the agent is actively busy on this turn.
     */
    public function isBusy(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Allocate the next event sequence number.
     *
     * Atomically increments last_event_seq and returns the new value.
     * Callers must use this within a transaction that also inserts the event.
     */
    public function nextSeq(): int
    {
        $this->increment('last_event_seq');
        $this->refresh();

        return $this->last_event_seq;
    }

    /**
     * Transition the turn to a new status.
     *
     * @throws \InvalidArgumentException if the transition is not allowed
     */
    public function transitionTo(TurnStatus $newStatus): void
    {
        if (! $this->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition turn from {$this->status->value} to {$newStatus->value}"
            );
        }

        $this->status = $newStatus;

        if ($newStatus->isTerminal()) {
            $this->finished_at = now();
        }

        $this->save();
    }

    /**
     * Update the current phase and optional label.
     */
    public function updatePhase(TurnPhase $phase, ?string $label = null): void
    {
        $this->current_phase = $phase;
        $this->current_label = $label;
        $this->save();
    }

    /**
     * Record a terminal event and finalize the turn.
     *
     * Emits the appropriate terminal event type, updates status, and
     * sets finished_at. Used by the turn event publisher on completion,
     * failure, or cancellation.
     */
    public function finalize(TurnStatus $terminalStatus, ?array $payload = null): void
    {
        $eventType = match ($terminalStatus) {
            TurnStatus::Completed => TurnEventType::TurnCompleted,
            TurnStatus::Failed => TurnEventType::TurnFailed,
            TurnStatus::Cancelled => TurnEventType::TurnCancelled,
            default => throw new \InvalidArgumentException(
                "Cannot finalize with non-terminal status: {$terminalStatus->value}"
            ),
        };

        $seq = $this->nextSeq();

        ChatTurnEvent::query()->create([
            'turn_id' => $this->id,
            'seq' => $seq,
            'event_type' => $eventType->value,
            'payload' => $payload,
        ]);

        $this->transitionTo($terminalStatus);
    }
}
