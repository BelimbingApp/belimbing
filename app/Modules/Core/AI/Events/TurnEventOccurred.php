<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast a turn event to the client via Reverb WebSocket.
 *
 * Fires synchronously (ShouldBroadcastNow) so events reach the browser
 * with minimal latency — the queue worker already serializes turn events
 * and adding another queue hop would defeat the purpose.
 *
 * Most broadcasts carry the canonical turn-event envelope:
 * {turn_id, seq, event_type, payload, occurred_at}. Oversized events fall
 * back to a tiny `{turn_id, seq, event_type, occurred_at, replay_required}`
 * marker so the client can fetch the canonical payload over HTTP replay.
 */
class TurnEventOccurred implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  string  $turnId  The turn this event belongs to
     * @param  array<string, mixed>  $eventPayload  Canonical turn event envelope
     */
    public function __construct(
        public string $turnId,
        public array $eventPayload,
    ) {}

    /**
     * Private channel scoped to the turn — authorization checks acting_for_user_id.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('turn.'.$this->turnId);
    }

    /**
     * Broadcast as a single event name so Echo can listen with one handler.
     */
    public function broadcastAs(): string
    {
        return 'turn-event';
    }

    /**
     * Data sent to the client — full event when it fits, replay marker when it does not.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->eventPayload;
    }
}
