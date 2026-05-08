<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\RunEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * AI Run Event — one immutable entry in the run's ordered event stream.
 *
 * Events are append-only: once written, they are never updated or deleted.
 * The (run_id, seq) pair provides strict ordering for replay and resume.
 *
 * The event_type column stores the string backing value of RunEventType,
 * which is the durable contract key shared across DB and UI layers.
 *
 * @property int $id Auto-increment PK
 * @property string $run_id Parent run ULID
 * @property int $seq Sequence number within the run (application-assigned, strictly increasing)
 * @property RunEventType $event_type Discriminated event type
 * @property array<string, mixed>|null $payload Event-specific data
 * @property Carbon|null $created_at When the event was persisted
 * @property-read AiRun $run
 */
class AiRunEvent extends Model
{
    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'ai_run_events';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'run_id',
        'seq',
        'event_type',
        'payload',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'event_type' => RunEventType::class,
            'payload' => 'json',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if ($event->created_at === null) {
                $event->created_at = now();
            }
        });
    }

    // ── Relationships ────────────────────────────────────────────────

    /**
     * The run this event belongs to.
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(AiRun::class, 'run_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Whether this event signals the run has reached a terminal state.
     */
    public function isTerminal(): bool
    {
        return $this->event_type->isTerminal();
    }

    /**
     * Whether this event carries incremental content (deltas).
     */
    public function isDelta(): bool
    {
        return $this->event_type->isDelta();
    }

    /**
     * Format this event as the canonical client payload.
     *
     * The canonical event envelope: {run_id, seq, event_type, payload, occurred_at}.
     *
     * @return array{run_id: string, seq: int, event_type: string, payload: mixed, occurred_at: string}
     */
    public function toSsePayload(): array
    {
        return [
            'run_id' => $this->run_id,
            'seq' => $this->seq,
            'event_type' => $this->event_type->value,
            'payload' => $this->payload,
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
