<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Http\Controllers;

use App\Modules\Core\AI\Models\ChatTurn;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * HTTP endpoint for replaying a turn's persisted event stream.
 *
 * Returns all events after `after_seq` as a JSON array. Live delivery
 * is handled by the Reverb WebSocket channel (`turn.{turnId}`), so
 * this endpoint is used only for:
 *
 * 1. **Page-load replay** — client fetches missed events, then subscribes
 *    to the Echo channel for live updates.
 * 2. **Reconnection gap-fill** — client provides `after_seq` to fetch
 *    events missed during a brief disconnect.
 */
class TurnEventStreamController
{
    /**
     * Return turn events as JSON for replay.
     */
    public function __invoke(Request $request, string $turnId): JsonResponse|Response
    {
        $turn = ChatTurn::query()->find($turnId);

        if ($turn === null) {
            return response('Turn not found', 404);
        }

        if ((int) $turn->acting_for_user_id !== (int) auth()->id()) {
            return response('Forbidden', 403);
        }

        $afterSeq = (int) $request->query('after_seq', '0');

        $events = $turn->eventsAfter($afterSeq)
            ->get()
            ->map(fn ($event) => $event->toSsePayload())
            ->values();

        return response()->json([
            'events' => $events,
            'turn_id' => $turn->id,
            'status' => $turn->status->value,
            'current_phase' => $turn->current_phase?->value,
            'current_label' => $turn->current_label,
            'started_at' => $turn->started_at?->toIso8601String(),
        ]);
    }
}
