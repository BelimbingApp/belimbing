<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Models;

use App\Modules\Core\AI\Enums\TurnEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Chat Turn Event — one immutable entry in the turn's ordered event stream.
 *
 * Events are append-only: once written, they are never updated or deleted.
 * The (turn_id, seq) pair provides strict ordering for replay and resume.
 *
 * The event_type column stores the string backing value of TurnEventType,
 * which is the durable contract key shared across DB, WebSocket, and UI layers.
 *
 * @property int $id Auto-increment PK
 * @property string $turn_id Parent turn ULID
 * @property int $seq Sequence number within the turn (application-assigned, strictly increasing)
 * @property TurnEventType $event_type Discriminated event type
 * @property array<string, mixed>|null $payload Event-specific data
 * @property Carbon|null $created_at When the event was persisted
 * @property-read ChatTurn $turn
 */
class ChatTurnEvent extends Model
{
    /**
     * Conservative ceiling for live Reverb payloads.
     *
     * Reverb speaks the Pusher protocol, which has a much smaller per-event
     * payload budget than our durable DB event store. Oversized live events
     * fall back to an HTTP replay marker instead of broadcasting the full blob.
     */
    public const MAX_BROADCAST_BYTES = 6_000;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var string
     */
    protected $table = 'ai_chat_turn_events';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'turn_id',
        'seq',
        'event_type',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seq' => 'integer',
            'event_type' => TurnEventType::class,
            'payload' => 'json',
            'created_at' => 'datetime',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────

    /**
     * The turn this event belongs to.
     */
    public function turn(): BelongsTo
    {
        return $this->belongsTo(ChatTurn::class, 'turn_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * Whether this event signals the turn has reached a terminal state.
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
     * Format this event as a broadcast-compatible array.
     *
     * The canonical event envelope: {turn_id, seq, event_type, payload, occurred_at}.
     *
     * @return array{turn_id: string, seq: int, event_type: string, payload: mixed, occurred_at: string}
     */
    public function toSsePayload(): array
    {
        return [
            'turn_id' => $this->turn_id,
            'seq' => $this->seq,
            'event_type' => $this->event_type->value,
            'payload' => $this->payload,
            'occurred_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Format this event for live Reverb delivery.
     *
     * Small events are broadcast in full. Oversized events degrade to a
     * lightweight marker so the browser can fetch the canonical event via the
     * replay endpoint without tripping the Pusher/Reverb payload limit.
     *
     * @return array{turn_id: string, seq: int, event_type: string, occurred_at: string|null, payload?: mixed, replay_required?: bool}
     */
    public function toBroadcastPayload(): array
    {
        $payload = $this->toSsePayload();

        if ($this->encodedPayloadSize($payload) <= self::MAX_BROADCAST_BYTES) {
            return $payload;
        }

        return [
            'turn_id' => $this->turn_id,
            'seq' => $this->seq,
            'event_type' => $this->event_type->value,
            'occurred_at' => $this->created_at?->toIso8601String(),
            'replay_required' => true,
        ];
    }

    /**
     * Calculate the UTF-8 encoded byte size of an event payload.
     *
     * @param  array<string, mixed>  $payload
     */
    private function encodedPayloadSize(array $payload): int
    {
        return strlen((string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
